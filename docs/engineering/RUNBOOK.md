# SiroCommerce Operations Runbook

> Incident triage (severity, on-call roles, failure playbooks) is in
> [INCIDENT_RUNBOOK.md](./INCIDENT_RUNBOOK.md).

Operational procedures for the production host. Commands assume the standard
layout created by [`deploy/release.sh`](../../deploy/release.sh):

```text
/opt/sirocommerce/
  current -> releases/<timestamp>   # live symlink
  releases/<timestamp>/             # immutable release trees
  backups/pre-<timestamp>.sql       # pre-migration dumps
  .env                              # copied into each release, never committed
```

`ROOT` defaults to `/opt/sirocommerce`; every command below accepts a different
root when explicitly overridden. Never paste secrets into shell arguments, logs,
tickets, or evidence files.

## 1. Release procedure

The supported path is CI/CD; see
[`docs/operations/CI_CD_RUNBOOK.md`](../operations/CI_CD_RUNBOOK.md).

1. Merge to `main`. CI runs backend, storefront, and admin tests, typechecks, and
   production builds.
2. The deploy workflow ships a source tarball to the host and runs
   `deploy/release.sh` over SSH.
3. `deploy/release.sh` performs, in order: extract, link `.env`, build/up the
   selected services, pre-migration MySQL backup, `php siro migrate`, switch the
   `current` symlink, install/validate the nginx cache + site config and reload
   only when they changed, health checks, then prune old releases.
4. Verify after the release:
   - `curl -fsS https://<host>/health/live`
   - `curl -fsS https://<host>/health/ready` (must be `200`; provider/config
     failures return `503`)
   - Admin shell loads and a read-only page responds.
   - `docker compose --env-file /opt/sirocommerce/current/.env -f /opt/sirocommerce/current/deploy/docker-compose.yml ps`

Do not deploy from a dirty working tree or with uncommitted local changes. A
manual fallback is documented in the CI/CD runbook; it uses the same
`deploy/release.sh`, not an ad-hoc `docker compose up`.

## 2. Backup

### Automatic pre-migration backup

`deploy/release.sh` runs `mysqldump` before migrations and keeps the newest five
`pre-*.sql` dumps. It is best-effort and never blocks a release: a failed dump is
logged loudly, so inspect release output when it warns.

The dump uses:

```text
mysqldump --single-transaction --quick --routines --triggers --databases "$DB_DATABASE"
```

Credentials are expanded inside the MySQL container, never on the host command
line.

### Manual logical backup

Run on the host, outside a release window:

```sh
ROOT=/opt/sirocommerce
STAMP=$(date -u +%Y%m%d%H%M%S)
DB_NAME=$(grep -m1 '^DB_DATABASE=' "$ROOT/current/.env" | cut -d= -f2- | tr -d '\r')
install -d -m 0700 "$ROOT/backups"
docker exec sirocommerce-mysql sh -c '
  if [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --quick --routines --triggers --databases "$1"
  else
    MYSQL_PWD="${MYSQL_PASSWORD:-}" exec mysqldump --single-transaction --quick --routines --triggers --databases -u "${MYSQL_USER:-<db-admin-user>}" "$1"
  fi
' sh "$DB_NAME" > "$ROOT/backups/manual-$STAMP.sql"
gzip -9 "$ROOT/backups/manual-$STAMP.sql"
```

Check the file is non-empty and gzip is valid before treating it as evidence:

```sh
ls -lh "$ROOT/backups/manual-$STAMP.sql.gz"
gzip -t "$ROOT/backups/manual-$STAMP.sql.gz"
```

### Restore

Restore into an isolated database/container first and run the readiness probe.
Only restore in place after the dump has been verified and a fresh safety dump
exists.

1. Announce maintenance and stop writes:
   ```sh
   cd /opt/sirocommerce/current
   docker compose --env-file .env -f deploy/docker-compose.yml stop backend storefront admin
   ```
2. Take a safety dump of the current state (see above).
3. Recreate the target database and import. This is destructive; confirm the
   database name first:
   ```sh
   ROOT=/opt/sirocommerce
   DB_NAME=$(grep -m1 '^DB_DATABASE=' "$ROOT/current/.env" | cut -d= -f2- | tr -d '\r')
   docker exec sirocommerce-mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u "<db-admin-user>" -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"' sh "$DB_NAME"
   gunzip -c "$ROOT/backups/manual-<stamp>.sql.gz" | docker exec -i sirocommerce-mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u "<db-admin-user>" "$1"' sh "$DB_NAME"
   ```
4. Start services and verify:
   ```sh
   cd /opt/sirocommerce/current
   docker compose --env-file .env -f deploy/docker-compose.yml up -d backend storefront admin
   curl -fsS https://<host>/health/ready
   ```
5. Record the dump used, restore start/end timestamps, row-count checks, and the
   measured `RPO`/`RTO`. A restore without evidence is `NOT_PROVEN`.

### Isolated restore drill

Rehearse a restore into a throwaway database before trusting a dump. See
[`BACKUP_DRILL.md`](./BACKUP_DRILL.md) for the latest measured drill (file, size,
import time, table/row counts, RTO). `pre-*.sql` dumps use `--databases`, so they
contain `CREATE DATABASE`/`USE sirocommerce`; strip those lines so an import can
never touch production. The root password stays inside the container:

```sh
ROOT=/opt/sirocommerce
LATEST=$(ls -1t "$ROOT"/backups/pre-*.sql | head -n1)
DRILL=siro_restore_drill

docker exec sirocommerce-mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS siro_restore_drill; CREATE DATABASE siro_restore_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'

START=$(date +%s%N)
sed -e '/^CREATE DATABASE/d' -e '/^USE /d' "$LATEST" \
  | docker exec -i sirocommerce-mysql sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot siro_restore_drill'
echo "import_seconds=$(awk -v s=$START -v e=$(date +%s%N) 'BEGIN{printf "%.2f",(e-s)/1e9}')"

docker exec sirocommerce-mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -D siro_restore_drill -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();"'

# Always clean up, including after a failed import.
docker exec sirocommerce-mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS siro_restore_drill;"'
```

Never pass the password as a host command-line argument. A drill that is not
measured and recorded is `NOT_PROVEN`.

## 3. Rollback a release

The automatic rollback in `deploy/release.sh` switches `current` back to the
previous release and rebuilds when migrations or health checks fail.

Manual rollback:

```sh
ROOT=/opt/sirocommerce
ls -1t "$ROOT/releases"
PREVIOUS=<timestamp-before-current>
ln -sfn "releases/$PREVIOUS" "$ROOT/current"
cd "$ROOT/current"
docker compose --env-file .env -f deploy/docker-compose.yml up -d --build backend storefront admin
curl -fsS https://<host>/health/ready
```

Rules:

- Never delete the release you are rolling back to or the one currently live.
- Database migrations are forward-only in normal operation. If a rollback needs
  a schema reversal, restore the pre-migration dump from `backups/` instead of
  guessing at down-migrations.
- Capture `docker compose ps`, health output, and failed release logs as evidence.

## 4. Disk full and prune

Start with read-only diagnosis:

```sh
df -h /
docker system df
du -sh /opt/sirocommerce/releases /opt/sirocommerce/backups
docker ps --format '{{.Names}}\t{{.Image}}'
```

Container logs are bounded by the compose logging config (`10m` per file, five
files per service), so a noisy service should not grow without limit. If the disk
is still full, use the prune script:

```sh
# Safe preview (default)
sh deploy/prune.sh

# Show what will be removed, then actually remove with --apply
sh deploy/prune.sh --apply
```

`deploy/prune.sh`:

- Defaults to dry-run; only `--apply` deletes anything.
- Removes dangling Docker images via `docker image prune -f` (no volumes, no
  tagged images).
- Keeps the newest `KEEP_RELEASES` release directories (default `3`) and always
  protects the release behind `current` plus the inferred previous release.
- Keeps the newest `KEEP_BACKUPS` backup dumps (default `5`).
- Prints `df`, `du`, and `docker system df` before and after.

Useful overrides:

```sh
ROOT=/opt/sirocommerce KEEP_RELEASES=3 KEEP_BACKUPS=5 sh deploy/prune.sh
PREVIOUS_RELEASE=20261001000000 sh deploy/prune.sh --apply
```

Do not remove the `mysql-data`, `redis-data`, or `backend_storage` Docker volumes
to reclaim space; they contain live data or uploads.

## 5. Provider outage

Commerce fails closed: payment/shipping/tax/search provider errors surface as
safe failures, they do not silently succeed.

1. Check readiness and which probes are failing:
   ```sh
   curl -sS https://<host>/health/ready
   ```
   The response lists missing configuration variable names, never secret values.
2. Run the configuration-only preflight from `backend/`:
   ```sh
   php scripts/provider-preflight.php
   ```
3. Inspect operations evidence:
   - Admin -> Operations -> anomaly alerts and reconciliation card.
   - `GET /api/admin/ops-alerts` and `GET /api/v2/admin/operations/...`.
   - Worker/queue status via `php siro queue:status` from `backend/`.
4. Recovery rules:
   - Retry failed jobs through Operations; the original idempotency key is kept.
   - Replay notification deliveries through Operations.
   - Use payment reconciliation dry-run first, then apply fixes from the confirm
     dialog; never fake a capture or refund outside the normal reconciliation
     flow.
   - Provider calls retry up to five attempts before remaining `failed` for manual
     retry.
5. See [`docs/operations/PRODUCTION_OPERATIONS.md`](../operations/PRODUCTION_OPERATIONS.md)
   for queue, preflight, and smoke-test details.

## 6. Alert webhook

Set `OPS_ALERT_WEBHOOK_URL` to an HTTPS receiver that accepts JSON. The URL may
embed a bearer token, so treat it as a secret and never log it.

When an anomaly first opens (refund/payment/fulfillment spikes versus the 7-day
baseline), the hourly `smart:aggregate` run posts:

```json
{
  "kind": "payment_fail_spike",
  "observed": 12,
  "baseline_x100": 150,
  "ratio_x100": 800,
  "at": "2026-11-15T10:00:00+00:00"
}
```

Delivery is best-effort, has a 3-second timeout, and never blocks aggregation.
Opening a second alert of the same kind does not repost while it is already open.

Receiver checklist:

- Return `2xx` quickly; queue slow work downstream.
- Deduplicate on `kind` plus a short time window.
- Page only for `open` anomalies; route informational alerts separately.

Acknowledge from Admin -> Operations -> Anomaly alerts, or:

```sh
curl -X POST -H "Authorization: Bearer <admin-token>" \
  https://<host>/api/admin/ops-alerts/<kind>/ack
```

Acknowledged alerts stay visible and re-open if the anomaly recurs after
resolution.

## 7. Quick verification after any change

```sh
cd apps/admin
npx tsc --noEmit
npm run build
npx vitest run

cd ../..
bash -n deploy/release.sh
bash -n deploy/prune.sh
```

For backup/restore evidence requirements see
[`docs/operations/PRODUCTION_PROOF_RUNBOOK.md`](../operations/PRODUCTION_PROOF_RUNBOOK.md).

## 8. Extension scaffold

- Scaffold: `php siro make:extension <Vendor> <Name>` from `backend/` (add `--force` to overwrite generated files).
- Validate and enable: `php siro extension:check` → Admin → Extensions → `<vendor>/<name>` → Install → Enable.
- Test local: `php vendor/bin/phpunit tests/Unit/ExtensionScaffolderTest.php` and smoke `GET /api/ext/<vendor>/<name>/status`.

## 9. Storefront microcache (nginx)

Public storefront HTML is microcached for 10 seconds at the nginx layer to cut
storefront p95. The cache is deliberately short-lived and bypassed for any
request that can carry user state.

Files:

- `/etc/nginx/conf.d/siro-cache.conf` from `deploy/nginx-siro-cache.conf`: the
  http-context `proxy_cache_path /var/cache/nginx/siro ... keys_zone=siro_micro:50m`
  (inactive 10m, max 500m) plus the bypass `map`s.
- `/etc/nginx/sites-available/ecommerce.sirophp.com` from
  `deploy/nginx-ecommerce.conf`: the cache location itself.

`deploy/release.sh` creates `/var/cache/nginx/siro` (owner `www-data`, mode
`0750`) and installs/reloads nginx when either file changed. Debian/Ubuntu nginx
workers run as `www-data`; if the host uses another user, adjust the ownership
before relying on the cache.

### What is cached and bypassed

Cached for GET/HEAD, with `X-Cache-Status` on the response:

- `/`
- `/category/...`
- `/product/...`
- `/search` and `/search/...`
- `/pages/...`

Never cached (not matched by the cache location):

- `/api/`, `/cart`, `/checkout`, `/account`, `/quote`, `/order-result`,
  `/metrics`, `/health`

Bypassed even inside the cache location:

- any method other than GET/HEAD
- `Cookie` containing `siro-locale`, `siro-currency`, `siro_cart_*`, or
  `siro_bag_count`
- any `Authorization` header
- Next.js RSC/prefetch requests (`RSC` header)
- `Cache-Control: no-cache`/`no-store` (used by `deploy/release.sh` health
  checks so they test the live storefront, never stale HTML)

`200` responses are cached for 10s and `404` for 5s. When the storefront returns
`500/502/503/504` or times out, nginx may serve the stale entry while refreshing
in the background (up to the 10s TTL).

### Verify after deploy

```sh
# First request: MISS (or BYPASS if cookies/authorization are present)
curl -s -o /dev/null -D - https://ecommerce.sirophp.com/product/<slug> | grep -i x-cache-status
# Repeat within 10s: HIT
curl -s -o /dev/null -D - https://ecommerce.sirophp.com/product/<slug> | grep -i x-cache-status

# Locale/cart cookie must bypass the shared cache (expected: BYPASS)
curl -s -o /dev/null -D - -H 'Cookie: siro-locale=vi' https://ecommerce.sirophp.com/product/<slug> | grep -i x-cache-status

# Cart must never be cached (expected: no header)
curl -s -o /dev/null -D - https://ecommerce.sirophp.com/cart | grep -i x-cache-status || echo "no X-Cache-Status (expected)"
```

`curl -I` sends HEAD; the cache key includes the method, so HEAD and GET report
separate entries. Use the GET form above when checking what real visitors see.

### Purge / clear

The standard nginx build has no `proxy_cache_purge`; clear the filesystem:

```sh
rm -rf /var/cache/nginx/siro/*
```

Deleting cached files while nginx runs is safe; the next request repopulates the
cache. A 10s TTL means an explicit purge is rarely needed, but clear it after
changing a price/stock value while investigating, then re-run the `curl` check.

If the host has the third-party `ngx_cache_purge` module, add a dedicated purge
location and call
`proxy_cache_purge siro_micro "$scheme$request_method$host$request_uri";`.
Do not assume the module is installed.

### Risks

- Prices and stock on PDP/category pages can lag up to 10s. This is accepted for
  anonymous browsing; cart and checkout always bypass the cache.
- `Set-Cookie` from the storefront is hidden in the cache location, so one
  visitor's cookie is never replayed to another. A future GET page that relies
  on server-set cookies (session/auth) must bypass or leave the cache location.
- The cache key ignores request headers beyond the bypass maps. RSC/prefetch
  requests bypass, so HTML and RSC payloads never mix.
- Only anonymous requests are shared; any locale/currency/cart cookie forces a
  bypass.
