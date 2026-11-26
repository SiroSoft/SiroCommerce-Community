# Deploy Safety

Operational rules for `deploy/release.sh` and `deploy/docker-compose.yml`.
Nothing here runs automatically during a PR; it documents what a production
release does and how to verify it.

## Release order

`extract -> env link -> build -> DB backup -> migrate -> switch symlink ->
nginx reload -> health poll -> prune`.

- The `.env` file is never taken from the tarball. The release copies
  `$ROOT/current/.env` into the new release and sets mode `0600`; the CI tarball
  excludes `.env` (and `*/.env`) so secrets are not packaged.
- The database backup runs **before** migrations (see below).
- The `current` symlink switches only after migrations succeed.

## Migrations fail hard

`php siro migrate` runs via `docker exec sirocommerce-backend`. Its exit code is
authoritative: on non-zero the script prints the tail of the output, rolls back
**before** switching the symlink, and exits `1`. There is no `|| true` and no
"continue to health check" path.

## Rollback

`rollback()` repoints `$ROOT/current` to the previous release and runs:

```sh
docker compose --env-file "$ROOT/current/.env" \
  -f "$ROOT/current/deploy/docker-compose.yml" up -d --build $SERVICES
```

`--build` is required so the previous image is rebuilt from its own source,
not left on the new image. Rollback is triggered by:

1. a failed migration (before the symlink switch), or
2. health checks that never pass (after the switch).

A failed rollback logs `ERROR` and still exits non-zero; treat it as a manual
intervention.

## Health polling

`sleep 25` is gone. The release polls up to `HEALTH_ATTEMPTS` (default 30)
times every `HEALTH_INTERVAL` (default 5s) until **all** of these return `200`:

- `$HEALTH_URL/health/live`
- `$HEALTH_URL/health/ready`
- `$HEALTH_URL/`

Only then is the release declared healthy and old releases pruned. Override
`HEALTH_URL`, `HEALTH_ATTEMPTS`, `HEALTH_INTERVAL` from the SSH command if a
maintenance window needs different timing.

## MySQL backup path and retention

- Dumps: `$ROOT/backups/pre-$RELEASE.sql` (`/opt/sirocommerce/backups/`).
- Runs only when the `sirocommerce-mysql` container is running; DB name comes
  from `DB_DATABASE` in the release `.env`.
- Credentials expand **inside** the container (`MYSQL_PWD` from the container's
  `MYSQL_ROOT_PASSWORD`/`MYSQL_PASSWORD`) so they do not appear in the host
  process list.
- Best-effort: a failed dump logs `WARNING` and removes the partial file, but
  does not block the release. Restore with
  `docker exec -i sirocommerce-mysql mysql -u "<db-admin-user>" -p < pre-<release>.sql`.
- Retention: the newest `BACKUP_RETENTION` dumps (default 5) are kept.

## Uploads / persistent storage

`deploy/docker-compose.yml` mounts the named volume `backend_storage` at
`/app/backend/storage` (the path created in `deploy/Dockerfile.backend`).
Uploads, evidence exports and generated files survive rebuilds and rollbacks.
`mysql-data` and `redis-data` are unchanged.

Every service logs through Docker `json-file` with `max-size: 10m` and
`max-file: 5`. Backend and admin have `restart: unless-stopped` and
`healthcheck`s. No hard memory/CPU limits are set: a 128M-style cap OOMs PHP
workers; size limits against real traffic before adding them.

## Anomaly alert webhook

Set `OPS_ALERT_WEBHOOK_URL` in the release `.env` to receive a JSON `POST`
when an anomaly kind first opens (not on every hourly run while it stays open):

```json
{"kind":"refund_spike","observed":6,"baseline_x100":29,"ratio_x100":2069,"at":"2026-..."}
```

- Best-effort through `Siro\Core\Http::post` with a 3s timeout; failures are
  swallowed so aggregation never breaks.
- The URL is never logged (it may contain a token).
- Leave empty to disable.

## Verifying a release

```sh
# syntax only, never runs the release
bash -n deploy/release.sh

# compose schema/anchors (needs Docker)
docker compose -f deploy/docker-compose.yml config

# health endpoints after deploy
curl -fsS https://ecommerce.sirophp.com/health/live
curl -fsS https://ecommerce.sirophp.com/health/ready

# backups present and recent
ls -lt /opt/sirocommerce/backups/pre-*.sql | head

# container state and logs
docker ps --filter name=sirocommerce-
docker stats --no-stream
```

## Metrics persistence

`routes/schedule.php` runs `metrics:persist` every 5 minutes. The command
reloads `storage/framework/metrics.json` and rewrites it so the cache file is
materialized even when no worker shuts down cleanly. It is safe to run against
an existing file; it does not delete unrelated metric keys.
