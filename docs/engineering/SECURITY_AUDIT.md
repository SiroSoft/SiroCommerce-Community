# Security Audit — Quick Pass

Date: 2026-11-20
Repository: `D:/VietVang/SiroSoft/SIROCOMMERCE` (branch `main`, HEAD `218e4e5`)
Scope: local repository + read-only live header check of
`https://ecommerce.sirophp.com`. No payment/auth logic was changed and nothing
was committed or deployed.

## 1. Scope and method

Reviewed:

- Secret/PII exposure in the working tree (new `scripts/security-scan.mjs`).
- Git tracking of `.env` files.
- Live response headers for `/` and `/health/live`.
- nginx header configuration in `deploy/nginx-ecommerce.conf` and
  `deploy/nginx-ecommerce-http.conf`.
- Storefront response headers in `apps/storefront/next.config.ts`.
- Admin route authentication from `backend/routes/api.php`.
- Static serving boundary for `backend/storage`.
- Production dependency advisories: `composer audit`, `npm audit --omit=dev`.

Not performed:

- No code review of payment/auth internals (explicitly out of scope).
- No dependency upgrades (explicitly out of scope).
- No nginx binary syntax check: nginx is not installed on this Windows host.
  The confs were reviewed manually; `deploy/release.sh` runs `nginx -t` before
  reloading on the server.

## 2. Findings

| # | Severity | Area | Finding | Status |
|---|---|---|---|---|
| F1 | High | Dependencies | `next@16.3.5` (storefront + admin) is inside the advisory range `16.0.0 - 16.4.4` (RCE in image optimization / `use cache`, SSRF, cache poisoning, DoS). Fix available: `16.4.6`. | Open, not upgraded |
| F2 | High | Dependencies | `sharp@0.35.4` (<0.35.5), librsvg `CVE-2026-96889`, in both apps. | Open, not upgraded |
| F3 | High | Dependencies | `source-map-js@1.2.1` (1.0.0 - 1.2.1), event-loop DoS. | Open, not upgraded |
| F4 | High | Dependencies | `zod@3.25.76` (<4.6.6) in admin, memory exhaustion on oversized collections. Fix is a major bump. | Open, not upgraded |
| F5 | Medium | CORS | `CORS_ALLOWED_ORIGINS` defaults to `*` in `backend/.env.example` and `CorsMiddleware`; production must set an exact origin whitelist. Code unchanged. | Recommendation |
| F6 | Medium | Live headers | Live `/` returns duplicate/conflicting headers: `X-Frame-Options: DENY` + `SAMEORIGIN`, duplicated `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. API also exposes `X-Powered-By: PHP/8.3.35`. | Fixed in nginx conf (pending deploy) |
| F7 | Low | Header posture | nginx configs did not set `server_tokens` and did not send `X-Permitted-Cross-Domain-Policies`. Storefront Next config lacked `Permissions-Policy` and `X-Permitted-Cross-Domain-Policies`. | Fixed |
| F8 | Low | Repo hygiene | Test artifacts are tracked under `storage/logs/*.xml` and `backend/storage/lang/*` despite `.gitignore` (`no secrets found` in them; storage is not web-served). | Noted, unchanged |
| F9 | Info | `.env` tracking | Only `backend/.env.example` and `apps/admin/.env.example` are tracked; no real `.env` file is in git. | Pass |
| F10 | Info | Admin routes | 255 admin routes in `backend/routes/api.php`; 0 lack `auth:` after resolving middleware variables. Admin paths also require `X-Siro-FE` through `FeGuardMiddleware`. | Pass |
| F11 | Info | Storage exposure | Docroot is `backend/public`; `backend/public/storage` does not exist; compose publishes only `127.0.0.1:8090`. | Pass |
| F12 | Info | Secret scan | No private keys, AWS/Stripe live/GitHub/Slack tokens, production-root logins, or production IP literals found. | Pass |

## 3. Changes made

### 3.1 New local scanner

`scripts/security-scan.mjs` walks the repo while skipping `.git`, `node_modules`,
`vendor`, `.next`, `test-results`, `coverage`, `storage`, `dist`/`build` and
similar artifacts, and checks:

- PEM private key blocks.
- Provider token prefixes: `AKIA`, `sk_live_`, `ghp_`, `xox[baprs]-`.
- Hardcoded credential assignments (password/secret/token/API key).
- Git-tracked `.env` files (`git ls-files`).
- `root@<host>` / `ssh root@` in `docs/`, `scripts/`, `deploy/`.
- Non-loopback IPv4 literals in `docs/`, `scripts/`, `deploy/`.

Placeholders (`change-me`, `example`, `dummy`, `<...>`, `${...}`, empty values),
test/fixture paths, locale files and variables referencing env values are
whitelisted. Exit code is 1 only for critical/high findings; medium findings are
reported without failing.

### 3.2 nginx — `deploy/nginx-ecommerce.conf` (HTTPS)

- Added `server_tokens off;` to the port 80 redirect server and the 443 server.
- Added `proxy_hide_header` for `Permissions-Policy`,
  `Strict-Transport-Security` and `X-Powered-By` so upstream duplicate headers
  and the PHP version do not reach clients.
- Added `add_header X-Permitted-Cross-Domain-Policies none always;`.
- Added `add_header Strict-Transport-Security "max-age=31536000;
  includeSubDomains" always;` at server level and repeated it in every location
  that defines its own `add_header` (`/health/live`, `/health/ready`,
  `/health/providers`, storefront microcache regex). `preload` was intentionally
  not added.
- No changes to cache keys, microcache TTL/bypass rules, proxy targets, or
  timeouts.

### 3.3 nginx — `deploy/nginx-ecommerce-http.conf` (HTTP-only)

- Added `server_tokens off;`.
- Added `proxy_hide_header Permissions-Policy` and `X-Powered-By`.
- Added `X-Permitted-Cross-Domain-Policies: none` at server and location level.
- No `Strict-Transport-Security`: this config serves plain HTTP; HSTS is only
  meaningful in the HTTPS config. A comment documents this.

### 3.4 Storefront — `apps/storefront/next.config.ts`

- Added `Permissions-Policy: geolocation=(), microphone=(), camera=()`.
- Added `X-Permitted-Cross-Domain-Policies: none`.
- Existing `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `X-XSS-Protection` and HSTS were left intact. No CSP was added to avoid
  breaking the storefront.

### 3.5 `backend/.env.example`

Added variables referenced by code or compose but missing from the example, all
with safe/empty defaults: `STOREFRONT_URL`, `DB_HOST`, `DB_PORT`,
`DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
`CORS_ALLOWED_METHODS`, `CORS_ALLOWED_HEADERS`, `THROTTLE_STOREFRONT_READ`,
`DIGITAL_DELIVERY_ROOT`, `DIGITAL_DELIVERY_MAX_BYTES`, `ERP_LITE_SECRET`,
`ERP_STORE_ID`, `ERP_CHANNEL_ID`, `MAIL_PROVIDER`, `MAIL_FROM_ADDRESS`,
`SHIPPING_DEFAULT_PROVIDER`.

## 4. Live header check (before deployment)

Command: `curl.exe -sS -D - -o NUL https://ecommerce.sirophp.com/` and
`.../health/live` (2026-11-20).

Current live state:

- `/`: HSTS `max-age=63072000; includeSubDomains; preload`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `X-Frame-Options: DENY` **and**
  `SAMEORIGIN`, `Permissions-Policy`, `X-XSS-Protection: 0`.
- `/health/live`: HSTS `max-age=31536000; includeSubDomains`, CSP
  `default-src 'none'; frame-ancestors 'none'; ...`, Permissions-Policy,
  `X-Powered-By: PHP/8.3.35`, CORS headers.
- `/api/health` is not a route (404); the health endpoint is `/health/live`.

Conclusion: HSTS, `X-Content-Type-Options`, `Referrer-Policy`,
`X-Frame-Options`/`frame-ancestors` and `Permissions-Policy` are all present.
The edge config change removes duplicate values and adds HSTS/anti-fingerprint
headers consistently after deploy.

## 5. Verification

Run from the repository root:

```bash
node scripts/security-scan.mjs
bash -n deploy/release.sh
npm run typecheck --workspace apps/storefront
```

Observed on 2026-11-20:

- `node scripts/security-scan.mjs` → `2060 files scanned, 621 skipped`,
  `PASS ...`, exit 0. A temporary self-test file containing a fake AWS key,
  Stripe live key and PEM key was detected as 3 critical findings with exit 1
  and then deleted.
- `bash -n deploy/release.sh` (Git Bash) → exit 0.
- `npm run typecheck --workspace apps/storefront` → pass.
- nginx syntax: **not run locally** (`nginx` is not installed). Deploy path runs
  `nginx -t` in `deploy/release.sh` and keeps the running config on failure.

Dependency audit summary (`--omit=dev`, no upgrades applied):

| Target | Critical | High | Moderate | Low |
|---|---:|---:|---:|---:|
| `backend` (`composer audit`) | 0 | 0 | 0 | 0 |
| `apps/storefront` | 1 (`next`) | 2 (`sharp`, `source-map-js`) | 0 | 0 |
| `apps/admin` | 1 (`next`) | 3 (`sharp`, `source-map-js`, `zod`) | 2 | 0 |

Re-run:

```bash
cd backend && composer audit --format=summary
cd apps/storefront && npm audit --omit=dev
cd apps/admin && npm audit --omit=dev
```

## 6. Accepted risks

1. **Access/refresh token in localStorage fallback.** The frontends keep the
   access token in memory and a bounded refresh fallback in `localStorage`
   (`docs/engineering/SECURITY_NOTES.md`, `docs/frontend/FE_SECURITY_PRIVACY.md`).
   Any XSS can read it. Moving to HttpOnly cookies requires contract/frontend
   changes outside this audit.
2. **Admin CSP keeps `script-src 'unsafe-inline'`** until Next.js hydration moves
   to nonces/hashes. `'unsafe-eval'` is development-only. Unchanged.
3. **Siro license proprietary.** `sirosoft/core` is a separately licensed
   vendor package; it was not modified or re-audited. Keep its license and
   update path separate from this repository's MIT code.
4. **CORS `*` remains the code/example default.** Production must set
   `CORS_ALLOWED_ORIGINS` to exact origins and keep credentials disabled for
   wildcard mode. This audit did not change code behavior.
5. **Next.js/sharp/source-map-js/zod advisories remain.** Upgrading is out of
   scope for this pass; treat as a tracked dependency-upgrade task. The
   storefront enables `dangerouslyAllowSVG` only with an attachment disposition
   and sandbox CSP, but the `next` image-optimization advisories still apply.
6. **Origin fallback is not authentication.** `FE_ALLOWED_ORIGINS` protects
   against drive-by browser calls only; non-browser clients can spoof `Origin`.
   Keep authentication, capability checks and throttling on all sensitive
   routes.
7. **HTTP-only nginx config has no HSTS.** That config is for plain-HTTP
   deployments; HSTS is added only in the HTTPS config where the domain is
   HTTPS-only (port 80 redirects to 443).
8. **Tracked test artifacts** under `storage/logs` and `backend/storage/lang`
   remain committed. They contain no secrets and are outside the web root, but
   they should be cleaned up separately if the repository should not carry test
   output.

## 7. Deploy notes for the nginx change

`deploy/release.sh` already copies `nginx-ecommerce.conf` to
`/etc/nginx/sites-available/ecommerce.sirophp.com`, substitutes
`__FE_SHARED_SECRET__`, then runs `nginx -t` before
`systemctl reload nginx`. After the next release:

```bash
nginx -t
systemctl reload nginx
curl -sSI https://ecommerce.sirophp.com/ | grep -Ei 'strict-transport|x-frame|x-content|referrer|permissions|x-permitted|x-powered'
curl -sSI https://ecommerce.sirophp.com/health/live | grep -Ei 'strict-transport|x-frame|x-content|referrer|permissions|x-permitted|x-powered'
```

Expected: one `Strict-Transport-Security` header per response (`max-age=31536000;
includeSubDomains` on the HTTPS edge), one of each security header, no
`X-Powered-By`, and no nginx version in `Server` (Cloudflare still reports
`Server: cloudflare` at its edge).
