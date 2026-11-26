# SiroCommerce Incident Runbook

Status: CURRENT. Operational incident response for production. This is the
severity/on-call/playbook entry point; routine release, backup, prune, and
provider procedures remain in [RUNBOOK.md](./RUNBOOK.md). SLO signals and
proposed targets are in [SLO.md](./SLO.md). Queue/provider operations are in
[PRODUCTION_OPERATIONS.md](../operations/PRODUCTION_OPERATIONS.md).

Rules that apply to every incident:

- Never paste secrets, tokens, card data, customer PII, or full provider payloads
  into chat, tickets, commits, or evidence files.
- Never delete `mysql-data`, `redis-data`, `meilisearch-data`, or
  `backend_storage` volumes to "fix" an incident.
- Never deploy from a dirty working tree or invent an ad-hoc
  `docker compose up` release. Use `deploy/release.sh` (via CI) or the documented
  rollback.
- Capture timestamps in UTC, commands run, outputs (redacted), and the final
  verification. "Not checked" is `NOT_PROVEN`, not "fine".
- When unsure about severity, start higher and downgrade only after mitigation.

## 1. Severity levels

| Severity | Definition | Examples | First response | Comms cadence | Escalation |
|---|---|---|---|---|---|
| **SEV1** | Critical outage, data loss/corruption, security breach, or money movement broken. | Site/checkout fully down; sustained 5xx; DB unavailable; payment capture/refund wrong; confirmed secret leak; corrupted orders/inventory. | Page IC + ops + backend + security immediately; open incident channel. | Every 15 min until mitigated. | IC -> engineering lead -> founder/exec. Security involves security on-call/DPO. |
| **SEV2** | Core journey degraded but not fully down, or a provider fails with fallback. | 5-25% checkout failures; one payment/shipping provider down; ready probe flapping; DB latency near budget; webhook backlog growing; disk >90%. | Page primary ops + backend on-call. | Every 30 min. | IC if >30 min or customer-visible money impact. |
| **SEV3** | One feature, tenant, or non-core surface degraded with a workaround. | Search degraded to native; admin report/export failing; one role blocked; single store/channel affected. | Ticket + on-call during business hours; page after-hours only if worsening. | At start, mitigation, closure. | Ops lead; backend owner for code. |
| **SEV4** | Cosmetic or low-impact issue; no data or money risk. | UI copy/layout defect; non-critical alert noise; documentation error. | Backlog ticket. | On closure. | Normal backlog. |

## 2. Roles and on-call

| Role | Owns | Notes |
|---|---|---|
| Incident Commander (IC) | Severity, priorities, decision log, comms, rollback approval. | One IC per incident. Does not debug by default. |
| Ops on-call | Host, containers, DB/Redis/Meili, disk, deploy/rollback, evidence capture. | First responder for SEV1/SEV2. |
| Backend on-call | PHP app, queue/outbox, migrations, payment/webhook logic. | Escalate for 5xx, data, or migration causes. |
| Security on-call | Token/secret exposure, auth anomalies, PII, containment. | Joined for any suspected breach. |
| Support/Comms lead | Customer/merchant updates; status page. | Never includes internal secrets or speculation. |

On-call rules:

- One incident channel and one scribe. Primary on-call acknowledges within 5
  minutes for SEV1/SEV2.
- Handoff must state current severity, mitigation status, next action, owner,
  and evidence location.
- The IC approves destructive actions (DB restore, data repair, volume changes)
  and rollbacks. The person executing reads the command back before running it.
- Escalation path: primary -> secondary -> engineering lead -> founder/exec;
  security incidents add security on-call immediately.

## 3. Detection checklist

Run from the release host unless marked otherwise. Replace placeholders:
`<host>`, `<admin-token>`, `<db-admin-user>`, `<release>`, `<stamp>`.

```sh
ROOT=/opt/sirocommerce
COMPOSE="docker compose --env-file $ROOT/current/.env -f $ROOT/current/deploy/docker-compose.yml"
```

| Area | Check | Healthy signal |
|---|---|---|
| Liveness | `curl -fsS https://<host>/health/live` | HTTP 200. |
| Readiness | `curl -fsS https://<host>/health/ready` | HTTP 200; 503 lists missing dependency/var names only. |
| Providers | `curl -fsS https://<host>/health/providers` | Configuration readiness only; never secret values. |
| Metrics | `curl -fsS https://<host>/metrics` (throttled) | Prometheus text; persisted snapshot. |
| Admin metrics | `curl -fsS -H "Authorization: Bearer <admin-token>" https://<host>/api/admin/ops/metrics` | OpenMetrics text. |
| Observability/readiness | `GET /api/admin/ops/observability`, `/api/admin/ops/readiness`, `/api/admin/ops-alerts` | No new anomaly alert; probes pass. |
| Containers | `$COMPOSE ps`; `docker ps --filter name=sirocommerce-` | Expected services `running`/`healthy`. |
| Backend logs | `docker logs --since 15m sirocommerce-backend` | No repeating 500/fatal/connection errors. |
| DB | `docker exec sirocommerce-mysql mysqladmin ping -h 127.0.0.1 -u "<db-admin-user>" -p` | `mysqld is alive`; prompts for password, do not pass it in argv. |
| Redis | `docker exec sirocommerce-redis redis-cli ping` | `PONG`. |
| Meilisearch | `curl -fsS http://127.0.0.1:7700/health` | `{"status":"available"}`. |
| Disk | `df -h /`; `docker system df`; `du -sh $ROOT/releases $ROOT/backups` | Free space >20%; no runaway images/backups. |
| Queue/outbox | `docker exec sirocommerce-backend sh -c 'cd /app/backend && php siro queue:status'`; `php siro commerce:ops outbox status` | No growing backlog; worker running. |
| Webhooks | `php siro commerce:ops webhooks replay --dry-run` (if supported); admin Operations | No stuck/failed backlog. |
| Storefront cache | `curl -s -o /dev/null -D - https://<host>/product/<slug>` | `X-Cache-Status: MISS/HIT/BYPASS` as expected. |

Suggested trigger thresholds (starting points, tune against SLO; not measured
SLO claims): `/health/ready` 503 for 2+ minutes; 5xx >5% for 5 minutes; p95
above the current budget for 15 minutes; disk >90%; queue/outbox oldest job >15
minutes or backlog >100; webhook failure rate >10% for 10 minutes.

## 4. Playbooks

Each playbook is ordered **Symptoms -> Check -> Act -> Verify -> Escalate**.
Copy evidence into the incident record before closing.

### 4.1 Site down / sustained 5xx

**Symptoms:** storefront/admin unreachable, 502/503/504 from nginx, checkout or
product pages fail, `/health/live` or `/health/ready` failing.

**Check:**
1. `curl -sS -o /dev/null -w '%{http_code}\n' https://<host>/health/live` and
   `/health/ready`; note which one fails.
2. `$COMPOSE ps` and `docker ps -a --filter name=sirocommerce-`.
3. `docker logs --since 15m sirocommerce-backend`, `...-storefront`, `...-admin`.
4. Check nginx upstream errors and whether a deploy finished in the last 30 min
   (`ls -1t $ROOT/releases | head`).
5. Check DB/Redis/Meili from section 3; check disk.
6. Confirm whether `/api/` works but HTML fails (frontend) or everything fails
   (backend/nginx/host).

**Act:**
- If it started after a release and health never passed, roll back using 4.3.
- If a dependency is down, follow its playbook (DB 4.2, Redis 4.6, Meili 4.7).
- If all containers are down, start the current release:
  `$COMPOSE up -d backend storefront admin` (this is recovery, not a new
  release). Do not rebuild a dirty tree.
- If nginx config changed, test and reload only after validating the generated
  config; otherwise restart nginx only if it is the proven cause.
- If the host is unhealthy (kernel/OOM/disk), engage the host provider and
  preserve console/log evidence.

**Verify:** `/health/live`, `/health/ready` return 200 for 5 consecutive minutes;
a product page, cart quote, and admin read page succeed; login/checkout smoke is
performed only after readiness. Watch 5xx rate for 15 minutes.

**Escalate:** SEV1 if customer-facing >5 minutes or money path broken; backend
on-call for application 500s; host provider for infrastructure.

### 4.2 DB down / slow

**Symptoms:** `/health/ready` 503 with database probe failing; 500s on reads;
checkout/inventory writes time out; admin lists slow.

**Check:**
1. `docker exec sirocommerce-mysql mysqladmin ping -h 127.0.0.1 -u "<db-admin-user>" -p`.
2. `docker logs --since 15m sirocommerce-mysql`.
3. `df -h /` and `docker system df` (a full disk often presents as DB failure).
4. `docker stats --no-stream sirocommerce-mysql`.
5. From `backend/`: `php siro db:health`, `php siro db:check`, `php siro db:stats`.
6. For slow queries/locks, open a DB session and run:
   `SHOW FULL PROCESSLIST;`, `SHOW ENGINE INNODB STATUS\G`, and check the slow
   query log if enabled. Never paste customer data from results into tickets.

**Act:**
- Disk full: follow 4.5 first.
- Container crashed: `$COMPOSE restart mysql`, then watch logs. Do not delete the
  volume. Do not run recovery imports while writes are live.
- Slow queries/lock waits: identify the blocking session. Kill a query only with
  IC approval and only after capturing `SHOW FULL PROCESSLIST`; prefer waiting
  for the transaction or rolling back the deploy that introduced it.
- Missing/corrupt schema: do not improvise DDL on production. Escalate to the
  backend owner and use the migration playbook 4.4.
- DB unrecoverable: declare SEV1, stop writes (`$COMPOSE stop backend storefront
  admin`), take a safety dump if possible, then restore via 4.11.

**Verify:** `mysqladmin ping` passes; readiness database probe is 200; a read and
a controlled write (for example an admin note on a test order, if available)
succeed; replication/backup status is known; row counts/invariants match the
incident record.

**Escalate:** SEV1 for unavailable DB or data risk; backend on-call + data owner
for corruption; hosting provider for disk/VM failure.

### 4.3 Deploy fail / rollback

**Symptoms:** CI deploy red; `release.sh` exits non-zero; health checks never
pass; new release live but errors increased.

**Check:**
1. Open the failed Actions run and the release output; note the last successful
   step (extract, build, backup, migrate, symlink, nginx, health, prune).
2. `ls -1t $ROOT/releases | head`; `readlink -f $ROOT/current`.
3. `$COMPOSE ps`; `docker logs --since 20m sirocommerce-backend`.
4. `curl -fsS https://<host>/health/ready`; check DB/Redis/disk.
5. If migration failed, use 4.4 instead of a blind rollback.

**Act:**
- The release script already rolls back on migration or health failure. Confirm
  the symlink points to the previous good release and containers were rebuilt
  from it.
- Manual rollback (only after confirming the target release):
  `ls -1t $ROOT/releases`; choose `<previous>`; then
  `ln -sfn "$ROOT/releases/<previous>" "$ROOT/current"`;
  `cd "$ROOT/current"`; `$COMPOSE up -d --build backend storefront admin`.
- Never delete the rollback target or the live release. Never reverse a schema
  by guessing at down-migrations; if the old code is incompatible with the new
  schema, restore the pre-migration dump (4.11).
- Fix forward only after the site is stable; a second deploy during an incident
  requires IC approval.

**Verify:** readiness 200; target commit is live (`/api/version`); key journeys
smoke green; error rate and latency return to baseline for 15 minutes. Record the
previous and restored release IDs.

**Escalate:** SEV1 if rollback fails or both old/new releases are unhealthy;
SEV2 for failed deploy with site still healthy; engineering lead + CI owner for
pipeline defects.

### 4.4 Migration fail

**Symptoms:** `php siro migrate` exits non-zero; release aborted/rolled back;
schema partially applied; readiness reports missing table/column.

**Check:**
1. Capture the migration output tail and the exact migration name/timestamp.
2. `docker exec sirocommerce-backend sh -c 'cd /app/backend && php siro migrate:status'`.
3. Confirm the pre-migration dump exists and is recent:
   `ls -lh $ROOT/backups/pre-*.sql` and `gzip -t` if compressed.
4. Check DB locks/disk (`SHOW FULL PROCESSLIST`, `df -h /`).
5. Confirm whether the migration is additive or destructive from the migration
   file and the release diff.

**Act:**
- Do not rerun a partially applied destructive migration blindly. Stop and read
  the migration.
- If the release script rolled back and the old schema is intact, keep the old
  release live and fix the migration in a new commit.
- If the schema is half-applied and incompatible with the old release, declare
  SEV1, stop writes, take a safety dump, and restore the pre-migration dump
  (4.11). A forward fix requires backend-owner review and a tested migration.
- Never edit an applied migration's identity; add a new later migration.
- Evidence: migration name, error, dump path, restore timestamps, and final
  `migrate:status`.

**Verify:** `php siro migrate:status` shows the expected state; readiness 200;
schema-dependent read/write smoke passes; no duplicate migration timestamps.

**Escalate:** SEV1 for partial/destructive schema change or restore decision;
backend on-call + data owner immediately.

### 4.5 Disk full

**Symptoms:** `No space left on device`; MySQL/Redis/Meili crash-loop; image
builds fail; 5xx; logs stop.

**Check:**
1. `df -h /` and `df -i /`.
2. `docker system df`; `docker ps --format '{{.Names}}\t{{.Image}}'`.
3. `du -sh $ROOT/releases $ROOT/backups /var/lib/docker /var/cache/nginx/siro`
   (paths may vary by host).
4. Check the Meili and MySQL volumes; check oversized container logs (compose
   caps at 10 MB x 5 per service).

**Act:**
- Preview cleanup: `sh $ROOT/current/deploy/prune.sh` (dry-run default).
- Apply only after reviewing: `sh $ROOT/current/deploy/prune.sh --apply`.
  It prunes dangling images and old releases/backups while protecting `current`
  and the previous release.
- Clear only disposable caches if needed: `rm -rf /var/cache/nginx/siro/*`
  (safe; repopulates) and host package/journal caches per host policy.
- Do not delete live volumes (`mysql-data`, `redis-data`, `meilisearch-data`,
  `backend_storage`). If the DB volume is the cause, stop writes and expand the
  disk/volume first; restoring into a full disk will corrupt the recovery.
- If the host cannot free space safely, escalate to the hosting provider.

**Verify:** `df -h /` free space >20%; containers stable for 15 minutes; readiness
200; DB/Meili health; write smoke and backup creation succeed.

**Escalate:** SEV1 if DB/checkout is affected; SEV2 if only builds/logs are
affected; hosting provider for volume expansion.

### 4.6 Redis / cache down

**Symptoms:** readiness/queue failures; throttling returns 503 or denies writes;
sessions/cache misses; queue jobs stop; high latency.

**Check:**
1. `docker exec sirocommerce-redis redis-cli ping`; expect `PONG`.
2. `docker logs --since 15m sirocommerce-redis`.
3. `df -h /` and `docker system df`.
4. `$COMPOSE ps redis`; check `CACHE_DRIVER` and `QUEUE_DRIVER` in the release
   `.env` (compose sets redis for both; confirm the actual release value).
5. API smoke: a throttled write may fail closed by design; distinguish fail-closed
   behavior from a general outage.

**Act:**
- Restart the service: `$COMPOSE restart redis`; then watch logs and `ping`.
- If Redis is OOM/disk-full, free disk (4.5) or raise memory, then restart. Do
  not delete `redis-data` unless the IC explicitly accepts queue/cache loss.
- If AOF is corrupted and Redis will not start, preserve a copy of the volume for
  inspection, then seek IC + backend approval before recreating it. Losing Redis
  may lose queued jobs; reconcile outbox/payments/webhooks afterward.
- Do not switch production to an ephemeral cache driver to "make it work"; that
  hides the failure and breaks throttling/queue guarantees.
- After recovery, replay/retry failed jobs and webhooks through the documented
  operations (`commerce:ops outbox ...`, `commerce:ops jobs ...`).

**Verify:** `redis-cli ping` = PONG; readiness 200; a throttled request no longer
fails closed unexpectedly; queue worker consumes; outbox/webhook backlog is
draining; cache hit behavior returns.

**Escalate:** SEV2 for cache/queue degradation; SEV1 if checkout/payment or
throttling blocks all writes; backend on-call for job/data reconciliation.

### 4.7 Meilisearch down (native fallback expected)

**Symptoms:** search slower or using SQL; `/health/ready` may report search
stale/unavailable; Meili container restarting; index updates stop.

**Check:**
1. `curl -fsS http://127.0.0.1:7700/health` and `$COMPOSE ps meilisearch`.
2. `docker logs --since 15m sirocommerce-meilisearch`.
3. `df -h /`, `docker system df`, and the `meilisearch-data` volume size.
4. Confirm `CATALOG_SEARCH_PROVIDER=meili` and `SEARCH_URL`/`SEARCH_API_KEY` are
   configured; if not, native search is expected.
5. Search a known query; native fallback should still return products.

**Act:**
- Expected behavior: provider timeout (1.5 s), error, or missing config falls back
  to native SQL search. Do not declare a site outage solely for Meili.
- Restart Meili: `$COMPOSE restart meilisearch`; verify health. Disk pressure:
  follow 4.5 and prune Meili tasks only after reading SEARCH_MEILI.
- If Meili is unavailable and native search is serving, keep it native and
  schedule repair; do not block checkout.
- Rebuild the index after recovery: from `backend/`,
  `php siro search:index-products --fresh` (run from the trusted release/ops
  context; it needs the master key). Expect up to one day of staleness otherwise.
- Never expose port 7700 publicly or put the Meili key in browser bundles.

**Verify:** Meili health available OR native search returns valid results;
readiness search probe is acceptable; index command reports no failed documents;
search latency is within budget.

**Escalate:** SEV3 for degraded search with native fallback; SEV2 if search
failure also breaks the read path or readiness for an extended period; backend
on-call for index/schema issues.

### 4.8 Payment provider outage

**Symptoms:** authorize/capture/refund failures or timeouts; provider 5xx;
unknown payment states; checkout success drops; reconciliation alerts.

**Check:**
1. `curl -fsS https://<host>/health/providers` and readiness; note missing/failed
   provider names only.
2. From `backend/`: `php scripts/provider-preflight.php` (configuration only).
3. Admin operations/alerts: `/api/admin/ops-alerts`, operations retry/reconcile
   views; `php siro commerce:ops payments reconcile` (dry-run first if required).
4. Inspect failed payments/jobs and provider error codes; never log or paste
   credentials, tokens, signatures, or raw provider bodies.
5. Check webhook receipt and queue backlog (4.9).

**Act:**
- Commerce fails closed; do not fake a capture, refund, or "paid" state.
- If one provider is down, use the documented fallback/manual path only if the
  business has enabled it (for example COD/manual transfer). Do not silently
  switch providers in the database.
- Retry failed operations through Operations with the original idempotency key.
  Run reconciliation dry-run first, then apply from the confirm flow.
- If the provider is confirmed down, notify support/comms and pause retries that
  only amplify load; preserve the original provider reference and evidence.
- If credentials are rejected, verify secret presence without printing values;
  rotate only through the secret store/deploy env.

**Verify:** preflight passes; a sandbox/contract check is not live evidence; a
real low-risk transaction (if approved) reconciles; no unknown payments remain;
checkout success returns to baseline; webhook backlog drains.

**Escalate:** SEV1 for money movement broken or duplicate/incorrect captures;
SEV2 for one provider down with customer-visible failures; finance owner +
provider support.

### 4.9 Webhook backlog

**Symptoms:** payment/shipping/ERP events delayed; outbox/webhook queue growth;
orders stuck in unknown/awaiting state; duplicate delivery risk.

**Check:**
1. `php siro queue:status`; `php siro commerce:ops outbox status`;
   `php siro commerce:ops jobs status`.
2. Admin `/api/v2/admin/operations/...` and `/api/v2/admin/notification-events`
   for failed/backlogged items.
3. `docker logs --since 30m sirocommerce-backend` filtered for webhook/queue
   errors (redact payloads).
4. Check Redis/DB health and disk; a dependency outage is often the root cause.
5. Confirm webhook signature verification failures versus processing backlog;
   never disable signature checks to drain a queue.

**Act:**
- Fix the dependency first (DB 4.2, Redis 4.6). Restart the queue worker if it is
  dead: `php siro queue:work --daemon` (or the supervised worker used by the
  release).
- Retry/replay through documented operations with idempotency preserved:
  `php siro commerce:ops webhooks replay`, `php siro commerce:ops outbox dispatch`,
  `php siro commerce:ops jobs retry --id=<job-id>`. Use dry-run where offered.
- Dead-letter/failed items remain visible; do not delete them. If a payload is
  malformed, quarantine it with evidence and escalate to the provider owner.
- If duplicate events are suspected, verify event-id deduplication before
  replaying; a changed replay must be rejected.

**Verify:** backlog age and count return below threshold; worker running; a
known event reaches terminal state once; order/payment/inventory reconciliation
matches; no signature failures introduced.

**Escalate:** SEV2 for payment/shipping webhooks delayed; SEV1 if money/order
state diverges or duplicate capture is possible; backend on-call + provider
owner.

### 4.10 Security incident (token/secret leak)

**Symptoms:** secret committed/posted/logged; suspicious admin sessions; auth
anomalies; unexpected data access; leaked `JWT_SECRET`, `FE_SHARED_SECRET`,
provider key, `MEILI_MASTER_KEY`, DB password, or SSH key.

**Check:**
1. Declare the incident privately; do not repeat the secret in chat. Preserve the
   evidence location and redact values.
2. Identify scope: which secret, where exposed, for how long, who could read it,
   and whether it grants money, data, admin, or infrastructure access.
3. Check auth/admin audit logs, unusual logins, rotation history, and recent
   deploys. Correlate with `X-Correlation-ID`/trace IDs.
4. Determine blast radius: sessions, API keys, webhook signatures, provider
   accounts, server access.

**Act:**
- Contain: rotate the exposed secret in the secret store/release `.env` and
  redeploy or restart the affected services. Rotate `JWT_SECRET` invalidates
  issued access tokens; coordinate user re-login. Rotate `FE_SHARED_SECRET` in
  backend and nginx together. Rotate provider/Meili/DB/SSH credentials with the
  owning provider.
- Revoke sessions/refresh tokens via the documented auth token-version/revoke
  path; do not rely on expiry.
- If server access is involved, revoke keys, review `authorized_keys`, and
  preserve host logs before cleanup.
- Do not "clean" logs or history before evidence is preserved. Remove the secret
  from the current working tree only after the security owner directs it; assume
  git history is public to anyone with repo access.
- Notify security on-call/DPO and legal per policy if PII/customer data or
  payment data may be involved. Do not speculate publicly.

**Verify:** old credential fails; new credential works from the expected services;
sessions revoked; no anomalous admin activity after rotation; affected provider
confirms old key revoked; monitoring remains clean for 24 hours.

**Escalate:** always SEV1/security incident. Security on-call + IC + DPO/legal.
Never close without a written timeline and follow-up actions.

### 4.11 Data corruption / restore from backup

**Symptoms:** wrong prices/totals, inventory negative or duplicated, orders
missing/duplicated, payment state inconsistent, failed migration/restore, or
explicit corruption alert.

**Check:**
1. Stop writes before more damage: `$COMPOSE stop backend storefront admin`
   (declare maintenance; keep nginx/DB up for evidence).
2. Identify the corruption window and the last known-good backup:
   `ls -lh $ROOT/backups/pre-*.sql*`, check timestamps and `gzip -t`.
3. Check whether a pre-migration dump exists for the suspect release and whether
   uploads/config also changed.
4. Verify DB/disk/Redis health; do not restore into a failing/full disk.
5. Capture row counts, invariants, and affected IDs before any destructive step.

**Act:**
- Take a **safety dump** of the current state even if corrupt. Use a dedicated
  backup/admin credential from the secret store (never a literal password):
  ```sh
  DB_NAME=$(grep -m1 '^DB_DATABASE=' "$ROOT/current/.env" | cut -d= -f2- | tr -d '\r')
  docker exec -e MYSQL_PWD="<db-admin-password>" sirocommerce-mysql \
    mysqldump --single-transaction --quick --routines --triggers \
    -u "<db-admin-user>" --databases "$DB_NAME" > "$ROOT/backups/incident-$STAMP.sql"
  ```
  See [RUNBOOK.md](./RUNBOOK.md) section 2 for the established backup procedure.
- Rehearse the restore into an isolated DB/container first when time permits; run
  migrations and readiness there. Do not test restores on the live DB.
- For an in-place restore: confirm the DB name twice, recreate it, then import:
  ```sh
  docker exec -e MYSQL_PWD="<db-admin-password>" sirocommerce-mysql mysql \
    -u "<db-admin-user>" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  gunzip -c "$ROOT/backups/<verified-dump>.sql.gz" | \
    docker exec -i -e MYSQL_PWD="<db-admin-password>" sirocommerce-mysql mysql \
    -u "<db-admin-user>" "$DB_NAME"
  ```
  This is destructive; the IC must approve, and a safety dump must exist.
- Restore order: database -> schema/version metadata -> uploads/config ->
  queue/outbox -> providers -> read paths.
- After restore, reconcile orders, payments, inventory, shipments, and outbox
  rows. Re-enable traffic gradually.

**Verify:** readiness 200; row counts/invariants match the known-good snapshot;
sample orders/payments/refunds reconcile; no duplicate/lost events; backup
restore timestamps and measured RPO/RTO are recorded; [PRODUCTION_PROOF_RUNBOOK.md](../operations/PRODUCTION_PROOF_RUNBOOK.md)
evidence is attached, not inferred.

**Escalate:** SEV1 immediately; IC + data owner + backend on-call. If the restore
affects customer data/payments, involve security/legal/DPO. Do not declare
recovery until reconciliation and smoke tests pass.

## 5. Closure checklist

- Severity, start/end UTC, detection source, and customer impact recorded.
- Root cause and contributing factors written without speculation.
- Mitigation/rollback/restore commands and evidence linked.
- Data reconciliation complete (orders, payments, inventory, shipments, outbox,
  webhooks).
- Monitoring clean for the agreed window; alerts acknowledged or resolved.
- Follow-up owners, due dates, and prevention work filed.
- Secrets rotated and old credentials confirmed revoked for security incidents.
- Post-incident review scheduled for SEV1/SEV2; blameless and evidence-based.
