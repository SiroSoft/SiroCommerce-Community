# Backup Restore Drill

Evidence record for an isolated MySQL restore drill on the production host.
A restore that was not measured is `NOT_PROVEN`; this file is the measured
evidence for the run below.

- Drill date (UTC): **2026-11-20** (import `04:33:24Z` -> checks done `04:33:30Z`)
- Host: `instance-60966192` (`nhan_tt`, sudo), root `/opt/sirocommerce`
- MySQL container: `sirocommerce-mysql` (`mysql:8.0`), database `sirocommerce`
- Backup used: `/opt/sirocommerce/backups/pre-20261016070000.sql`
- Safety: imported into the throwaway database `siro_restore_drill` only; the
  dump's `CREATE DATABASE`/`USE` lines were stripped so nothing could write to
  production. The temp database was dropped in an `EXIT` trap (also on failure).

## Result: PASS

| Metric | Measured |
| --- | --- |
| Backup file | `pre-20261016070000.sql` (mtime `2026-11-17 08:33`) |
| Backup size | 1,474,455 bytes (~1.5 MiB) |
| Tables restored | 192 / 192 (matches production) |
| Import wall time | **5.21 s** (`import_rc=0`) |
| RTO (import start -> checks done) | **6.24 s** |
| Temp database after drill | dropped (`SHOW DATABASES LIKE 'siro_restore_drill'` empty) |
| `/health/live` and `/health/ready` | 200 internal (`127.0.0.1:8090`) and 200 public |
| Production data | unchanged (row counts below) |

### Row counts (restored vs production)

Captured during the drill; production was also re-checked after cleanup.

| Table | Restored | Production | Match |
| --- | --- | --- | --- |
| `orders` | 30 | 30 | yes |
| `products` | 84 | 84 | yes |
| `product_variants` | 124 | 124 | yes |
| `inventory_items` | 256 | 256 | yes |
| `users` | 40 | 40 | yes |

Production baseline before import (`2026-11-20T04:32:25Z`) and after cleanup
(`2026-11-20T04:34:13Z`) both showed `orders=30`, `products=84`.

## Method

Run as root on the host. The password is read from the container's
`MYSQL_ROOT_PASSWORD` and never placed on a host command line or in a log.

```sh
DB=siro_restore_drill
F=/opt/sirocommerce/backups/pre-20261016070000.sql

# 1. Fresh temp database (production is untouched).
docker exec sirocommerce-mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS siro_restore_drill; CREATE DATABASE siro_restore_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'

# 2. Import with timing. Strip the dump's CREATE DATABASE / USE so the import
#    can only land in the database named on the client, never `sirocommerce`.
START=$(date +%s%N)
sed -e '/^CREATE DATABASE/d' -e '/^USE /d' "$F" \
  | docker exec -i sirocommerce-mysql sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot siro_restore_drill'
echo "import_seconds=$(awk -v s=$START -v e=$(date +%s%N) 'BEGIN{printf "%.2f",(e-s)/1e9}')"

# 3. Verify.
docker exec sirocommerce-mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -D siro_restore_drill -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();"'
for t in orders products product_variants inventory_items users; do
  docker exec sirocommerce-mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -D siro_restore_drill -e "SELECT COUNT(*) FROM '"$t"';"'
done

# 4. Always drop the temp database (run even if import/verify failed).
docker exec sirocommerce-mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS siro_restore_drill;"'
```

> The `pre-*.sql` dumps are created with `--databases`, so they contain
> `CREATE DATABASE`/`USE sirocommerce`. Importing such a dump without stripping
> those lines would write into production. The drill script above is the safe
> pattern; keep the strip step in any restore rehearsal.

## Storage volume

`sirocommerce-backend` mounts the named volume `deploy_backend_storage` at
`/app/backend/storage` (`rw=true`).

| Check | Result |
| --- | --- |
| `docker exec sirocommerce-backend du -sh /app/backend/storage` | `9.9M` |
| Top-level entries | `database/`, `evidence/`, `logs/` |
| Owner / mode | `10001:10001`, `755` (container runs as uid/gid 10001 `app`) |
| Write test | `touch` + `rm` in the volume succeeded as uid 10001 |

Volume ownership note: the volume must stay `10001:10001`. If it was created
root-owned, chown it once (see
[SECURITY_NOTES.md](./SECURITY_NOTES.md#named-volume-ownership)); do not run the
backend as root to work around permissions.

## Ops measurements at drill time (`2026-11-20T04:33:58Z`)

```text
df -h /            242G total, 108G used, 134G avail, 45% use
docker system df   Images 36.26GB (35.38GB reclaimable), Containers 98.7MB,
                   Local Volumes 49.7GB, Build Cache 23.45GB (20.91GB reclaimable)
```

Retention/inventory:

- Releases: 3 (`20261016060000`, `20261016063000`, `20261016070000` at 27M/27M/28M);
  `current -> releases/20261016070000`.
- Backups: 5 `pre-*.sql` files, ~1.5M each (~7.5M total), newest
  `pre-20261016070000.sql`.
- Legacy `backups/20261003T*` directories: 5, not matched by `prune.sh` (it only
  prunes `*.sql`/`*.sql.gz`), so they stay until removed manually.

## Prune dry-run summary

`bash deploy/prune.sh` from `/opt/sirocommerce/current` (dry-run, no `--apply`):

- Releases: all 3 kept (`current` and `previous` are protected, `KEEP_RELEASES=3`).
- Backups: all 5 kept (`KEEP_BACKUPS=5`); nothing eligible for removal.
- Dangling images: **0** would be pruned.
- Disk usage unchanged before/after: releases `82M`, backups `7.6M`, root 45%.

Nothing met the retention thresholds, so `--apply` was **not** run. When a future
run does show eligible old SiroCommerce releases/backups, run the dry-run first,
confirm only SiroCommerce paths are listed, then `bash deploy/prune.sh --apply`.

## Conclusion

PASS. The newest pre-migration dump restores cleanly in ~5 seconds, restores all
192 tables, and matches production row counts on the key commerce tables. RTO
measured at **6.24 s** for this dataset. Health stayed `200` and production data
was unchanged; the temp database was dropped.
