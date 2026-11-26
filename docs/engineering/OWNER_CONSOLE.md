# Owner Console Domain

Status: CURRENT. Decision: the owner console is the existing `apps/admin`
Next.js app, deployed on its own hostname `owner.sirophp.com`. Do **not** fork
the source or run a second codebase.

## 1. Decision

| Question | Decision |
|---|---|
| Separate source repo? | **No.** Same monorepo and release; `apps/admin` is the owner console. |
| Separate app/container? | Yes, `sirocommerce-admin` already exists as an independent Next.js container. |
| Separate domain? | Yes, `owner.sirophp.com` (rename with `OWNER_DOMAIN` if needed). |
| Separate API? | No. The admin app calls `https://ecommerce.sirophp.com/api`; owner origin is added to CORS. |
| Separate data? | No. Same backend/store scope; authorization is enforced by the backend role/capability gates. |
| Public marketplace/portal? | No. This is the merchant/owner console only. |

Why not fork:
- one API/contract and one release;
- no schema/version drift between owner and storefront;
- security fixes and permission changes ship once;
- the admin container is already isolated and only binds `127.0.0.1:3005`.

A separate repo is only justified if a different team owns a different security
lifecycle. That is not the case now.

## 2. DNS

Create:

```text
owner.sirophp.com  A     222.255.181.133
```

Optional IPv6 `AAAA` if the server has a stable address. For the first
certificate issuance, disable a Cloudflare proxy (orange cloud) so the HTTP-01
webroot challenge reaches nginx. After the certificate is installed, Cloudflare
proxy/Access may be enabled.

Do not point `owner` at the storefront hostname; the owner console is a separate
virtual host and a separate Next.js container.

## 3. TLS and nginx

Template: `deploy/nginx-owner.conf` (`__OWNER_DOMAIN__` is substituted).
One-time installer: `deploy/setup-owner-domain.sh`.

```bash
sudo env OWNER_DOMAIN=owner.sirophp.com \
  LETSENCRYPT_EMAIL=ops@example.com \
  sh /opt/sirocommerce/current/deploy/setup-owner-domain.sh
```

The script:
1. checks the admin container is running;
2. bootstraps the ACME webroot and requests a certificate with certbot;
3. installs the HTTPS virtual host and reloads nginx;
4. adds `https://owner.sirophp.com` to `CORS_ALLOWED_ORIGINS` in the release
   `.env` and recreates the backend;
5. verifies `https://owner.sirophp.com/login` returns 200.

The template proxies `/` to `127.0.0.1:3005` and keeps security headers/HSTS.
It allows a 24 MB upload for extension packages and a 120s proxy timeout for
install/QIT operations.

## 4. Access control

A login page is not enough for an owner console. Use one of:

1. **Cloudflare Access** (recommended): policy limited to owner emails/groups;
   proxy the hostname after TLS is issued.
2. **nginx allowlist**: uncomment `allow`/`deny all` in the template with the
   owner's fixed IP/CIDR.
3. **VPN/Tailscale**: bind the admin container to the private network and proxy
   only from that network.

Role/capability checks remain the final authority in the backend. The owner
console shows owner/admin pages; finance/support roles remain limited by their
existing capabilities.

## 5. CORS and cookies

- The admin app uses bearer tokens, not cross-site cookies, so it does not need
  `credentials: include`.
- `CORS_ALLOWED_ORIGINS` must contain `https://owner.sirophp.com`; the setup
  script adds and persists it.
- Do not use a wildcard with credentials.
- Do not expose the admin container port publicly; nginx remains the only public
  entry point.

## 6. Verification

```bash
curl -sI https://owner.sirophp.com/login
curl -s https://ecommerce.sirophp.com/health/ready
sudo nginx -t
sudo systemctl status nginx --no-pager
```

Expected: `/login` 200, storefront readiness 200, nginx config valid. Then log in
as owner and verify:
- Dashboard, catalog, orders, finance.
- Extensions → Catalog/Updates/Licenses.
- Theme catalog.
- Extension upload/QIT and license import.

## 7. Rollback

```bash
sudo rm -f /etc/nginx/sites-enabled/owner.sirophp.com.conf
sudo nginx -t
sudo systemctl reload nginx
```

The admin container and storefront are unaffected. Remove the owner origin from
`CORS_ALLOWED_ORIGINS` only if the hostname is retired.

## 8. Not included

- No separate database, API, or source tree.
- No public admin marketplace.
- No SSO/SAML/SCIM gate (Cloudflare Access is the interim gate).
- No automatic DNS/Certbot changes in `deploy/release.sh`; this is a one-time
  host setup, not a per-release mutation.
