# Scale Plan

Status: **proposal**. The sections below describe how to scale SiroCommerce
beyond a single host. They are engineering guidance, not measured production
capacity. Load targets are goals; see [`SLO.md`](SLO.md) for current measured
samples and their single-node caveat.

Related: [`SLO.md`](SLO.md), [`RUNBOOK.md`](RUNBOOK.md),
[`../operations/PRODUCTION_PROOF_RUNBOOK.md`](../operations/PRODUCTION_PROOF_RUNBOOK.md),
[`CI_QUALITY.md`](CI_QUALITY.md).

## Targets

| Dimension | Target | Notes |
| --- | --- | --- |
| API p95 latency | <= 800ms | Read-only storefront paths; stricter engineering budget remains in [`09_PERFORMANCE_BUDGETS.md`](09_PERFORMANCE_BUDGETS.md). |
| Sustained throughput | 200 RPS at concurrency 50 | If the workload is more write-heavy, treat checkout/queue capacity separately. |
| Error rate | <= 2% | Same gate as `scripts/load-smoke.mjs`. |
| Availability | 99.5% / 30d | Requires an external probe and multi-node deployment; see `SLO.md`. |

Validate any candidate topology with:

```sh
node scripts/load-smoke.mjs --base https://<host> --duration 300 --concurrency 50 --ramp
```

## Stateless application tier

The backend is stateless by design and can be horizontally replicated:

- Auth uses bearer tokens (JWT access + refresh tokens), not sticky sessions.
- Cart ownership and idempotency live in shared storage, not process memory.
- No request depends on a file written only to the local application container.
- The admin and storefront are separate Next apps and scale independently of the
  PHP runtime.

Scale by running multiple backend processes behind nginx. Keep the same code
version and configuration on every node; deploy via CI, never by copying a dirty
working tree.

## Shared uploads volume

Media uploads and generated derivatives are written under `backend/storage`.
With more than one node, this directory **must** be a shared volume:

- Mount the same network filesystem (or object storage backed mount) on every
  node at the same path.
- Keep the public storage symlink consistent across nodes.
- Upload write latency is now part of request latency; prefer local network
  storage over high-latency remote mounts.
- Object storage with signed URLs is the long-term option for very large media,
  but it is not implemented by this plan.

Do not run multiple nodes with per-node local upload volumes: a user may upload
on node A and request the file from node B.

## Redis for cache and throttling

Use Redis for cross-node coordination:

- Shared cache for `Siro\Core\Cache` where stale-while-revalidate or read-model
  caching is enabled.
- Throttle counters, so rate limits apply across all nodes instead of per node.
- Queue backend if queue throughput outgrows the database driver.

Configuration is environment-driven; confirm the cache/queue driver and Redis
availability with `php siro env:check`, `/health/ready`, and the ops metrics
endpoint. If Redis is unavailable, decide per subsystem whether to fail closed
(throttle) or degrade to the existing file/database fallback.

## Database sizing and read replica

Start with a single primary sized for peak writes plus headroom:

- Use MySQL 8 or PostgreSQL with connection pooling/proxying when node count
  grows; PHP-FPM workers multiplied by nodes must not exhaust connections.
- Keep checkout, inventory, payment, payout, and audit writes on the primary.
- Add a read replica for reporting, exports, and other heavy read-only queries.
  Route only explicitly safe read paths to the replica; never serve
  read-after-write-critical admin checks from a lagging replica.
- Monitor replication lag and fail reporting reads back to the primary when lag
  exceeds the acceptable window.
- Back up before migrations and rehearse restores; see the backup drill below.

Recommended headroom: keep CPU below ~60% at peak on the primary and leave room
for a replica during failover. Query budgets and indexes remain the first lever
before vertical scaling.

## Meilisearch HA

For search-dependent scale:

- Run at least three Meilisearch nodes with a load balancer, or two plus a warm
  standby if three is not economical.
- Take snapshots on a schedule and verify a restore before relying on HA.
- Indexing is asynchronous. Keep the existing index-lag signal visible and alert
  when lag exceeds the documented budget.
- The native search fallback must remain functional so storefront reads degrade
  instead of failing when Meili is unavailable.

## CDN

- Serve storefront HTML/assets and cacheable public GETs through a CDN.
- Keep personalization, cart, checkout, and admin traffic uncached.
- Respect existing response cache headers and purge on publish events.
- Ensure the origin sees the client IP correctly through trusted proxy headers
  for throttling and audit.

## Horizontal nginx load balancer

- Put nginx (or a managed load balancer) in front of multiple backend nodes.
- Health check `/health/live` for liveness and `/health/ready` for readiness;
  remove unready nodes from rotation.
- Terminate TLS at the edge, forward the original host/proto/client IP, and keep
  request body limits aligned with upload limits.
- Use least-connections or weighted balancing. Do not enable sticky sessions as
  a substitute for shared state.
- Keep one deployment unit across nodes: same image, same env, same migrations
  applied before traffic shifts.

## Backup and restore drill

Release and restore procedures are owned by operations:

- Release, backup, rollback, disk prune: [`RUNBOOK.md`](RUNBOOK.md).
- Measured backup/restore evidence: [`../operations/PRODUCTION_PROOF_RUNBOOK.md`](../operations/PRODUCTION_PROOF_RUNBOOK.md).

A restore that has not been rehearsed with recorded checksums and RPO/RTO is
`NOT_PROVEN`. Re-run the drill after topology changes (new database host, new
storage backend, new replica).

## Roadmap

1. **Baseline**: keep the single-node deployment, capture 300s ramp soaks, and
   maintain SLO evidence (current state).
2. **Shared state**: move uploads to a shared volume and enable Redis for cache
   and throttling.
3. **Two backend nodes**: put nginx in front, verify readiness-based draining,
   and re-run the ramp soak at c=50.
4. **Database headroom**: add the read replica, move reporting/exports to it,
   and monitor replication lag and connection counts.
5. **Search HA**: run Meilisearch with replication/snapshots and alert on index
   lag.
6. **Edge scale**: enable CDN caching for public GETs and purge on publish.
7. **Target validation**: re-run the 300s c=50 ramp against the multi-node
   topology and record real numbers in `SLO.md`. Until then the 200 RPS / p95
   800ms target remains a proposal, not production evidence.
