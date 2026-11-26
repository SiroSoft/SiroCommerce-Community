# CI/CD Runbook

> Status 2026-11-22: GitHub Actions is **disabled** because the account has run
> out of minutes. The workflows remain in the repo but do not run on push/PR.
> Releases in this cycle used the same `deploy/release.sh` path manually. Re-enable
> Actions before claiming CI-gated deploys.

When Actions is enabled, every push to `main` runs CI (backend, storefront,
admin: tests, typecheck, builds) and then releases to production automatically.
Until then, use the manual release procedure below/`deploy/release.sh`.

## How it works

1. `.github/workflows/ci.yml` — tests + typechecks + production builds.
2. `.github/workflows/deploy.yml` — needs CI green; ships a source tarball to
   the server and runs `deploy/release.sh` over SSH.
3. `deploy/release.sh` (single release path, also usable by hand):
   extract -> link `.env` -> `docker compose up -d --build` ->
   `php siro migrate` -> switch `current` symlink -> nginx reload when its
   config changed (with the real `FE_SHARED_SECRET` substituted) ->
   health checks (`/health/live`, `/health/ready`, `/`) -> prune old
   releases. On any health failure it switches the symlink back and
   restarts the previous containers.

## Required GitHub secrets

Repository Settings -> Secrets and variables -> Actions -> New repository secret.

| Secret | Value | Notes |
|---|---|---|
| `DEPLOY_HOST` | `<deploy-host>` | production server (placeholder; never commit the real IP/host) |
| `DEPLOY_PORT` | `22` | optional, defaults to 22 |
| `DEPLOY_USER` | `<deploy-user>` | dedicated deploy user that owns `/opt/sirocommerce` and runs docker |
| `DEPLOY_SSH_KEY` | private OpenSSH key | pair its public key into the server's `authorized_keys`; never commit it |

Until the secrets exist, the deploy job fails fast with a clear auth error and
nothing is shipped. The manual fallback stays available:

```sh
RELEASE=$(date -u +%Y%m%d%H%M%S)
tar <same excludes as deploy.yml> -czf /tmp/sirocommerce-$RELEASE.tar.gz .
# upload, then on the server:
RELEASE=$RELEASE sh deploy/release.sh < /tmp/sirocommerce-$RELEASE.tar.gz
```

## First run checklist

1. Add the four secrets above.
2. Push to `main` (or use Actions -> Deploy -> Run workflow).
3. Watch Actions: CI (~5 min) then release (~6 min, mostly Docker builds).
4. Confirm: Actions log ends with "is live", `/health/ready` returns 200.

## Rollback

Automatic on health failure. Manual rollback:

```sh
ln -sfn releases/<PREVIOUS> /opt/sirocommerce/current
cd /opt/sirocommerce/current
docker compose --env-file .env -f deploy/docker-compose.yml up -d backend storefront admin
```
