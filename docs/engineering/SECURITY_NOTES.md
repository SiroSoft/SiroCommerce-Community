# Security Notes

This document records the hardening changes for the admin CSP, the frontend
trust guard, and non-root container execution. It is an operational reference,
not a substitute for the deployment runbooks.

## 1. Admin Content-Security-Policy

File: `apps/admin/next.config.ts`

### Before (production and development)

```
default-src 'self';
script-src 'self' 'unsafe-inline' 'unsafe-eval' https://challenges.cloudflare.com https://static.cloudflareinsights.com;
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
font-src 'self' data:;
frame-src 'self' https://challenges.cloudflare.com;
connect-src 'self' http://localhost:* https:;
```

### After

Production:

```
default-src 'self';
script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://static.cloudflareinsights.com;
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
font-src 'self' data:;
frame-src 'self' https://challenges.cloudflare.com;
connect-src 'self' https:;
object-src 'none';
base-uri 'self';
frame-ancestors 'none';
form-action 'self';
```

Development only: `script-src` additionally contains `'unsafe-eval'`, and
`connect-src` additionally contains `http://localhost:* ws://localhost:*`.
This keeps Next.js dev-server/React-refresh working without shipping eval to
production. `'unsafe-inline'` remains in `script-src` until hydration is moved
to nonce/hash-based scripts; removing it earlier breaks Next.js inline
bootstrap/flight data. `'unsafe-inline'` is still required for styles.

`object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'none'` and
`form-action 'self'` close plugin embedding, base-tag hijacking, framing, and
cross-origin form posts. `X-Frame-Options: DENY` remains as a legacy fallback.

## 2. Frontend trust guard

File: `backend/app/Middleware/FeGuardMiddleware.php`

The old model required every `/api` request to carry `X-Siro-FE` equal to
`FE_SHARED_SECRET`. `NEXT_PUBLIC_FE_TOKEN` is inlined into browser JavaScript,
so it is public and must be treated as **deprecated and not a secret**.

The new model accepts either:

1. A valid `X-Siro-FE` token (`hash_equals` against `FE_SHARED_SECRET`), or
2. No token, with `Origin` (preferred) or `Referer` exactly matching an entry
   in `FE_ALLOWED_ORIGINS`.

Rules:

- The origin fallback applies to storefront paths only. Any path containing an
  `/admin/` segment (including `/api/admin/...` and `/api/v2/admin/...`) still
  requires the shared token.
- A supplied but wrong token is always `403`; it is never rescued by a valid
  origin.
- Missing origin + missing token is `403`.
- The fallback does not bypass throttling: route throttle middleware still
  runs after `FeGuardMiddleware`.
- Each accepted origin fallback emits `fe_guard.origin_fallback` through the
  security logger with origin, path and client IP for metrics/alerting.
- `FE_ALLOWED_ORIGINS` is an exact, comma-separated allowlist. Wildcards,
  `null`, malformed values and non-HTTP(S) schemes are ignored.
- Local development keeps the legacy fail-open behavior only when
  `FE_SHARED_SECRET` is unset and `APP_ENV` is not `production`. Production
  admin traffic without a configured secret still fails closed (`503`
  `TRUST_CONFIG_MISSING`).

nginx may continue to inject `X-Siro-FE` for server-side/storefront callers
that do not send an `Origin` header. That path remains supported while the
token is phased out.

### Token rotation

- `FE_SHARED_SECRET` is distributed to nginx and the backend. Rotate it by
  updating the deployment `.env`, updating the nginx `X-Siro-FE` value, then
  restarting/reloading both. Origin-based storefront traffic is unaffected.
- `FE_ALLOWED_ORIGINS` changes require a backend restart. Remove an origin only
  after clients have moved off it.
- Do not put the shared secret in `NEXT_PUBLIC_*` variables. Browser bundles
  are public.

## 3. Non-root containers

### Backend

File: `deploy/Dockerfile.backend`

- Creates `app` with uid/gid `10001` and runs the server as `USER app`.
- Chowns `backend/storage` (database, evidence, framework, logs, rate_limit,
  cache) so migrations, logging, throttling fallback and uploads work without
  root.
- Sets `HOME`/`XDG_*` to app-owned paths for FrankenPHP/Caddy runtime state.
- Keeps port `8090` and the existing `frankenphp php-server` contract.

`deploy/release.sh` runs `docker exec sirocommerce-backend sh -c 'cd
/app/backend && php siro migrate'`; `docker exec` uses the image user (`app`),
which can write the storage volume.

### Frontend (storefront and admin)

File: `deploy/Dockerfile.frontend`

- Copies the build output with `--chown=node:node`.
- Runs `USER node` with `HOME=/home/node` so `.next/cache` and npm state are
  writable without root.
- Keeps `next start` on port `3000`.

### Named volume ownership

`backend_storage` is initialised from the image's `/app/backend/storage`
ownership, which is uid/gid `10001`. A volume created before this change may
still be root-owned. Chown it once before deploying:

```sh
docker run --rm -v sirocommerce_backend_storage:/s busybox \
  chown -R 10001:10001 /s
```

Verify after deploy:

```sh
docker exec sirocommerce-backend id            # uid=10001(app)
docker exec sirocommerce-backend touch /app/backend/storage/logs/.write-test
docker exec sirocommerce-admin id              # uid=1000(node)
```

If uploads or logs fail with permission errors, fix the volume ownership
before rolling forward; do not switch the container back to root.

## 4. Residual risks

- **localStorage access token**: the admin/storefront clients keep access
  tokens in browser storage (see storefront/admin source). Any XSS can read
  them. CSP reduces but does not eliminate XSS risk while `'unsafe-inline'`
  remains for scripts. Moving to HttpOnly cookies or in-memory tokens requires
  frontend changes outside this scope.
- **Inline scripts**: `script-src 'unsafe-inline'` in production is a temporary
  compatibility requirement for Next.js. A nonce-based policy is the next step.
- **Origin is spoofable by non-browser clients**: the origin fallback is a
  browser-origin control, not authentication. It protects against drive-by
  cross-origin browser calls; it does not identify a trusted server. Keep
  authentication/authorization and throttling for all sensitive operations.
- **nginx-injected shared token**: the legacy token still lives in nginx
  configuration and the backend environment. Treat it as a secret and rotate
  it if leaked. `NEXT_PUBLIC_FE_TOKEN` provides no protection.
- **Container writable paths**: the backend still needs writable storage for
  SQLite, evidence, logs and the file throttle fallback. Compromise of the app
  user can modify those files; keep the volume permissions narrow and back up
  evidence separately.
- **Static-host `_headers`**: `apps/admin/public/_headers` still contains the
  legacy CSP (including `unsafe-eval`). The Docker/nginx deployment serves the
  Next.js server, so `next.config.ts` is authoritative there. If the admin app
  is ever deployed to a static host that consumes `_headers`, that file must be
  updated to match the production policy before enabling it.
