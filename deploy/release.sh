#!/bin/sh
# One release path for humans and CI. Reads a source tarball from stdin and
# performs: extract -> env link -> build -> backup -> migrate -> switch ->
# health check. On migration failure it rolls back before switching the
# symlink; on health failure it rolls back to the previous release and exits
# non-zero.
# Usage: cat release.tar.gz | ssh deploy@host 'RELEASE=20261004000000 SERVICES="backend storefront admin" sh -s' < deploy/release.sh
set -eu

RELEASE="${RELEASE:?RELEASE timestamp required (e.g. 20261004000000)}"
SERVICES="${SERVICES:-backend storefront admin}"
ROOT="/opt/sirocommerce"
TARGET="$ROOT/releases/$RELEASE"
HEALTH_URL="${HEALTH_URL:-https://ecommerce.sirophp.com}"
HEALTH_ATTEMPTS="${HEALTH_ATTEMPTS:-30}"
HEALTH_INTERVAL="${HEALTH_INTERVAL:-5}"
BACKUP_RETENTION="${BACKUP_RETENTION:-5}"

log() { echo "[release $RELEASE] $*"; }
fail() { echo "[release $RELEASE] FATAL: $*" >&2; exit 1; }

# Read a KEY=value from an env file without sourcing it (values may contain
# characters that are unsafe in shell). Strips CR and surrounding quotes.
env_value() {
  _key="$1"
  _file="$2"
  _value="$(grep -m1 "^${_key}=" "$_file" 2>/dev/null | cut -d= -f2- | tr -d '\r' || true)"
  _value="${_value%\"}"
  _value="${_value#\"}"
  printf '%s' "$_value"
}

rollback() {
  log "rolling back to $PREVIOUS (rebuilding previous release)"
  ln -sfn "$PREVIOUS" "$ROOT/current"
  # shellcheck disable=SC2086
  if docker compose --env-file "$ROOT/current/.env" -f "$ROOT/current/deploy/docker-compose.yml" up -d --build $SERVICES; then
    log "rollback build/up completed; $PREVIOUS is current"
  else
    log "ERROR: rollback build/up failed; manual intervention required"
  fi
  exit 1
}

# Best-effort logical MySQL backup before migrations run. Keeps the newest
# $BACKUP_RETENTION dumps. Never blocks the release: a failure is logged loudly.
backup_database() {
  install -d -m 0700 "$ROOT/backups"
  if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'sirocommerce-mysql'; then
    log "backup: mysql container is not running; skipping pre-migration dump"
    return 0
  fi
  db_name="$(env_value DB_DATABASE "$TARGET/.env")"
  [ -n "$db_name" ] || db_name="$(env_value MYSQL_DATABASE "$TARGET/.env")"
  if [ -z "$db_name" ]; then
    log "WARNING: backup skipped: DB_DATABASE is not set in $TARGET/.env"
    return 0
  fi
  backup_file="$ROOT/backups/pre-$RELEASE.sql"
  # Credentials are expanded inside the container so they do not appear in the
  # host process list. DB_* values reach the container through compose.
  if docker exec sirocommerce-mysql sh -c '
        if [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
          MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --quick --routines --triggers --databases "$1"
        else
          MYSQL_PWD="${MYSQL_PASSWORD:-}" exec mysqldump --single-transaction --quick --routines --triggers --databases -u "${MYSQL_USER:-root}" "$1"
        fi
      ' sh "$db_name" > "$backup_file"; then
    log "backup: wrote $backup_file ($(wc -c < "$backup_file") bytes)"
    # shellcheck disable=SC2012
    ls -1t "$ROOT"/backups/pre-*.sql 2>/dev/null | tail -n "+$((BACKUP_RETENTION + 1))" | while IFS= read -r old; do
      rm -f "$old"
    done
  else
    rm -f "$backup_file"
    log "WARNING: pre-migration MySQL backup failed; continuing (best-effort)"
  fi
}

[ "$(id -u)" = "0" ] || fail "run as root (or a user able to manage $ROOT and docker)"
command -v docker >/dev/null 2>&1 || fail "docker not found"
# nginx microcache directory. Debian/Ubuntu nginx workers run as www-data;
# "|| true" keeps releases working if the worker user differs from www-data.
install -d -o www-data -g www-data -m 0750 /var/cache/nginx/siro || true
command -v curl >/dev/null 2>&1 || fail "curl not found (needed for health checks)"

PREVIOUS="$(readlink "$ROOT/current" || true)"
[ -n "$PREVIOUS" ] || fail "no current release symlink at $ROOT/current"

log "extracting source"
install -d -o sirocommerce -g sirocommerce -m 0755 "$TARGET"
tar -xzf - -C "$TARGET"
chown -R sirocommerce:sirocommerce "$TARGET"
cp "$ROOT/current/.env" "$TARGET/.env"
chmod 0600 "$TARGET/.env"

log "building: $SERVICES"
# shellcheck disable=SC2086
if ! docker compose --env-file "$TARGET/.env" -f "$TARGET/deploy/docker-compose.yml" up -d --build $SERVICES; then
  fail "compose build/up failed; live release untouched ($PREVIOUS still current)"
fi

log "backing up the database before migrating"
backup_database

log "running database migrations (hard fail)"
if ! migrate_output="$(docker exec sirocommerce-backend sh -c 'cd /app/backend && php siro migrate' 2>&1)"; then
  printf '%s\n' "$migrate_output" | tail -20 >&2
  log "migration failed; rolling back BEFORE switching the symlink"
  rollback
fi
printf '%s\n' "$migrate_output" | tail -3

log "switching symlink"
ln -sfn "releases/$RELEASE" "$ROOT/current"

log "reloading nginx when its config changed"
nginx_changed=0
if [ -f "$TARGET/deploy/nginx-siro-cache.conf" ]; then
  if ! cmp -s "$TARGET/deploy/nginx-siro-cache.conf" /etc/nginx/conf.d/siro-cache.conf; then
    install -m 0644 "$TARGET/deploy/nginx-siro-cache.conf" /etc/nginx/conf.d/siro-cache.conf
    nginx_changed=1
  fi
fi
if [ -f "$TARGET/deploy/nginx-ecommerce.conf" ]; then
  if ! cmp -s "$TARGET/deploy/nginx-ecommerce.conf" /etc/nginx/sites-available/ecommerce.sirophp.com; then
    SECRET="$(grep -m1 '^FE_SHARED_SECRET=' "$TARGET/.env" | cut -d= -f2-)"
    cp "$TARGET/deploy/nginx-ecommerce.conf" /etc/nginx/sites-available/ecommerce.sirophp.com
    if [ -n "$SECRET" ]; then
      sed -i "s|__FE_SHARED_SECRET__|$SECRET|" /etc/nginx/sites-available/ecommerce.sirophp.com
    fi
    nginx_changed=1
  fi
fi
if [ "$nginx_changed" = "1" ]; then
  if nginx -t 2>&1 | tail -1 | grep -q successful; then
    systemctl reload nginx
  else
    log "WARNING: new nginx config failed validation; keeping the running config"
  fi
fi

http_code() {
  # no-cache bypasses the nginx microcache so health checks test the live
  # storefront, never a stale cached page.
  curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H 'Cache-Control: no-cache' "$1" 2>/dev/null || true
}

# live + ready + home must all answer 200 before the release is declared healthy.
healthy_now() {
  for path in /health/live /health/ready /; do
    code="$(http_code "$HEALTH_URL$path")"
    if [ "$code" != "200" ]; then
      return 1
    fi
  done
  return 0
}

log "waiting for health checks (up to $((HEALTH_ATTEMPTS * HEALTH_INTERVAL))s)"
attempt=1
healthy=0
while [ "$attempt" -le "$HEALTH_ATTEMPTS" ]; do
  if healthy_now; then
    healthy=1
    break
  fi
  log "health check attempt $attempt/$HEALTH_ATTEMPTS not ready; retrying in ${HEALTH_INTERVAL}s"
  sleep "$HEALTH_INTERVAL"
  attempt=$((attempt + 1))
done

if [ "$healthy" != "1" ]; then
  log "health checks never passed after $((HEALTH_ATTEMPTS * HEALTH_INTERVAL))s"
  rollback
fi

log "healthy; pruning old releases (keeping the 3 newest plus previous)"
cd "$ROOT/releases"
# shellcheck disable=SC2012
ls -1 | sort | head -n -3 | while IFS= read -r old; do
  if [ "releases/$old" != "$PREVIOUS" ] && [ "$old" != "$RELEASE" ]; then
    rm -rf "$ROOT/releases/$old"
  fi
done
log "release $RELEASE is live"
exit 0
