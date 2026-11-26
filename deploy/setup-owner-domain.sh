#!/bin/sh
# Install the owner console on its own domain.
#
# Prerequisites:
#   - DNS A/AAAA for OWNER_DOMAIN points at this server (disable Cloudflare
#     proxy for the first certificate issuance; enable it afterwards).
#   - The admin container is running on 127.0.0.1:3005.
#   - certbot is installed.
#
# Usage:
#   sudo env LETSENCRYPT_EMAIL=ops@example.com sh deploy/setup-owner-domain.sh
#   sudo env OWNER_DOMAIN=owner.sirophp.com LETSENCRYPT_EMAIL=ops@example.com \
#     sh deploy/setup-owner-domain.sh
set -eu

ROOT="/opt/sirocommerce"
DOMAIN="${OWNER_DOMAIN:-owner.sirophp.com}"
EMAIL="${LETSENCRYPT_EMAIL:-}"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
WEBROOT="/var/www/certbot"
CONF_NAME="$DOMAIN.conf"

log() { echo "[owner-domain] $*"; }
fail() { echo "[owner-domain] FATAL: $*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || fail "run as root"
[ -n "$DOMAIN" ] || fail "OWNER_DOMAIN is required"
[ "$DOMAIN" != "ecommerce.sirophp.com" ] || fail "OWNER_DOMAIN must not be the storefront host"
[ -f "$ROOT/current/deploy/nginx-owner.conf" ] || fail "missing $ROOT/current/deploy/nginx-owner.conf"
[ -f "$ROOT/current/.env" ] || fail "missing $ROOT/current/.env"
command -v certbot >/dev/null 2>&1 || fail "certbot is not installed"
command -v nginx >/dev/null 2>&1 || fail "nginx is not installed"
command -v curl >/dev/null 2>&1 || fail "curl is not installed"
docker inspect -f '{{.State.Running}}' sirocommerce-admin 2>/dev/null | grep -qx true \
    || fail "sirocommerce-admin is not running"

install -d -m 0755 "$WEBROOT"
install -d -m 0755 /etc/nginx/sites-available /etc/nginx/sites-enabled

if [ ! -f "$CERT_DIR/fullchain.pem" ]; then
    [ -n "$EMAIL" ] || fail "LETSENCRYPT_EMAIL is required to issue the first certificate"

    log "bootstrapping HTTP challenge for $DOMAIN"
    cat > "/etc/nginx/sites-available/$CONF_NAME" <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    server_tokens off;
    location /.well-known/acme-challenge/ { root $WEBROOT; }
    location / { return 404; }
}
EOF
    ln -sfn "/etc/nginx/sites-available/$CONF_NAME" "/etc/nginx/sites-enabled/$CONF_NAME"
    nginx -t
    systemctl reload nginx

    log "requesting certificate for $DOMAIN"
    certbot certonly --webroot -w "$WEBROOT" -d "$DOMAIN" \
        --email "$EMAIL" --agree-tos --non-interactive --keep-until-expiring
else
    log "certificate already exists for $DOMAIN"
fi

log "installing nginx site for $DOMAIN"
sed "s/__OWNER_DOMAIN__/$DOMAIN/g" "$ROOT/current/deploy/nginx-owner.conf" \
    > "/etc/nginx/sites-available/$CONF_NAME"
chmod 0644 "/etc/nginx/sites-available/$CONF_NAME"
ln -sfn "/etc/nginx/sites-available/$CONF_NAME" "/etc/nginx/sites-enabled/$CONF_NAME"
nginx -t
systemctl reload nginx

# The admin app keeps its API base at https://ecommerce.sirophp.com. Allow the
# owner origin through CORS, and persist it in the release env that future
# releases copy forward.
ENV_FILE="$ROOT/current/.env"
ORIGIN="https://$DOMAIN"
if grep -q '^CORS_ALLOWED_ORIGINS=' "$ENV_FILE"; then
    ORIGINS="$(grep -m1 '^CORS_ALLOWED_ORIGINS=' "$ENV_FILE" | cut -d= -f2- | tr -d '\r')"
    case ",$ORIGINS," in
        *",$ORIGIN,"*) log "CORS already allows $ORIGIN" ;;
        *)
            NEW_ORIGINS="${ORIGINS:+$ORIGINS,}$ORIGIN"
            sed -i "s|^CORS_ALLOWED_ORIGINS=.*|CORS_ALLOWED_ORIGINS=$NEW_ORIGINS|" "$ENV_FILE"
            log "added $ORIGIN to CORS_ALLOWED_ORIGINS"
            ;;
    esac
else
    printf '\nCORS_ALLOWED_ORIGINS=%s\n' "$ORIGIN" >> "$ENV_FILE"
    log "set CORS_ALLOWED_ORIGINS=$ORIGIN"
fi
chmod 0600 "$ENV_FILE"

log "recreating backend to apply CORS"
docker compose --env-file "$ENV_FILE" -f "$ROOT/current/deploy/docker-compose.yml" up -d backend

log "waiting for https://$DOMAIN/login"
i=0
while [ "$i" -lt 30 ]; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "https://$DOMAIN/login" || true)"
    [ "$code" = "200" ] && break
    i=$((i + 1))
    sleep 3
done
[ "$code" = "200" ] || fail "owner console did not become healthy (last HTTP $code)"

log "owner console is live at https://$DOMAIN/login"
log "next: add Cloudflare Access or an nginx allowlist for owner-only access"
