# SLO & Observability (Proposed)

Status: **proposed** baseline. Targets below are not production evidence and
must not be reported as measured until the listed source has data. When a source
is missing, report `NOT_PROVEN`, never an estimated number.

Scope: production host described in [`RUNBOOK.md`](RUNBOOK.md) (backend,
storefront, admin). Window: rolling 30 days, timestamps in UTC. This document
adds no dashboard code; it only defines what to measure with existing signals.

## Baseline (measured)

### 2026-11-16 (after nginx microcache)

- Config: `node scripts/load-smoke.mjs --base https://ecommerce.sirophp.com --duration 15 --concurrency 10`
- Requests 706, errors 0 (0%), RPS 42.49
- p50 128.77ms, **p95 695.27ms**, p99 1324.61ms
- Per-path p95: health 713.82ms, suggest 444.49ms, storefront config 718.44ms, PDP 670.45ms
- Verdict: p95 API objective (<800ms) met for this sample; p99 still ~1.3s; end-to-end SLO remains NOT_PROVEN (single sample, single node).


Read-only load smoke against the live storefront. This is a single short sample,
not a 30-day SLO measurement; do not extrapolate it into an availability or
monthly latency claim.

| Field | Value |
| --- | --- |
| Date (UTC) | 2026-11-16T05:20:41Z |
| Command | `node scripts/load-smoke.mjs --base https://ecommerce.sirophp.com --duration 15 --concurrency 10` |
| Duration / concurrency | 15s / 10 in-flight GETs |
| Paths | `/health/live`, `/api/search/suggest?q=lin`, `/api/storefront/config`, `/product/linen-camp-shirt` |
| Headers | `X-Store-Id: default`, `X-Channel-Id: web`, `X-Locale: en`, `X-Currency: VND` |
| Requests / errors | 435 / 0 |
| Error rate | 0.00% |
| RPS | 26.69 |
| p50 | 158.67ms |
| p95 | 1313.97ms |
| p99 | 2507.49ms |
| min / max | 111.78ms / 3883.98ms |

Per-path:

| Path | Requests | Errors | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: | ---: |
| `/health/live` | 109 | 0 | 138.74ms | 1325.10ms | 1773.12ms |
| `/api/search/suggest?q=lin` | 109 | 0 | 145.41ms | 1337.85ms | 1438.81ms |
| `/api/storefront/config` | 109 | 0 | 135.30ms | 735.95ms | 1322.61ms |
| `/product/linen-camp-shirt` | 108 | 0 | 285.58ms | 1322.15ms | 2697.07ms |

Conclusion: the endpoint set stayed error-free and the load-smoke gate passed
(error rate <= 2%, p95 <= 1500ms). Latency is **not** yet proven against the
800ms p95 API objective: this single 15s sample shows p95 1313.97ms and p99
2507.49ms, so the latency SLO remains `NOT_PROVEN` until a longer window with
the metrics snapshot/prometheus scrape is evaluated. Three of four paths share
a similar p95 (~1.3s), which points at shared request-path overhead rather than
one route.

### 2026-11-17 soak (180s, c=20)

Read-only soak against the live storefront. The harness now supports
`--abort-error-rate` / `--abort-p95`; this run used 5% and 3000ms but completed
the full window without tripping either threshold.

| Field | Value |
| --- | --- |
| Date (UTC) | 2026-11-17T01:18:53Z |
| Command | `node scripts/load-smoke.mjs --base https://ecommerce.sirophp.com --duration 180 --concurrency 20 --abort-error-rate 5 --abort-p95 3000` |
| Duration / concurrency | 180s / 20 in-flight GETs |
| Paths | `/health/live`, `/api/search/suggest?q=lin`, `/api/storefront/config`, `/product/linen-camp-shirt` |
| Headers | `X-Store-Id: default`, `X-Channel-Id: web`, `X-Locale: en`, `X-Currency: VND` |
| Requests / errors | 30225 / 0 |
| Error rate | 0.00% |
| RPS | 167.81 |
| p50 | 118.79ms |
| p95 | 140.05ms |
| p99 | 173.72ms |
| min / max | 74.28ms / 1212.46ms |
| Early abort | No |

Per-path:

| Path | Requests | Errors | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: | ---: |
| `/health/live` | 7557 | 0 | 119.20ms | 137.67ms | 173.57ms |
| `/api/search/suggest?q=lin` | 7556 | 0 | 125.45ms | 144.10ms | 182.30ms |
| `/api/storefront/config` | 7556 | 0 | 122.76ms | 141.28ms | 173.28ms |
| `/product/linen-camp-shirt` | 7556 | 0 | 100.53ms | 116.61ms | 160.48ms |

Cache note: `/api/storefront/config` returned `Cache-Control: public, max-age=60`;
the PDP probe returned `x-cache-status: STALE`; health/suggest did not expose a
local HIT/MISS header (`cf-cache-status: DYNAMIC`). Explicit HIT/MISS counts are
therefore **NOT_PROVEN** from this harness.

Cold vs warm (short run immediately after the soak, warm cache):

| Run | Duration / concurrency | Requests | RPS | p50 | p95 | p99 |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Soak (warm) | 180s / 20 | 30225 | 167.81 | 118.79ms | 140.05ms | 173.72ms |
| Post-soak short | 30s / 10 | 2420 | 80.37 | 122.51ms | 148.86ms | 194.12ms |

The short run is within ~6% on p95 and ~12% on p99; RPS halves because
concurrency halves. No material warm-cache regression was observed. A controlled
cold-cache sample was not captured in this session, so the earlier 2026-11-16
sample is not used as a cold baseline.

Conclusion: error rate, p95 gate (<1500ms), and the 800ms p95 API objective are
met for this 3-minute, single-node sample. The storefront is error-free at
20 concurrent readers, but the 30-day availability/latency SLO remains
`NOT_PROVEN` (no multi-node run and no full metrics window).

### 2026-11-20 soak (300s, c=30, ramp 5→30)

Read-only soak against the live storefront using the new `--ramp` scheduler. The
harness started at 5 in-flight GETs and increased linearly to 30 over the
300-second window. The run completed without tripping the 5% error-rate or
3000ms p95 early-abort thresholds.

| Field | Value |
| --- | --- |
| Date (UTC) | 2026-11-20T21:29:46Z start (300s window) |
| Command | `node scripts/load-smoke.mjs --base https://ecommerce.sirophp.com --duration 300 --concurrency 30 --ramp --abort-error-rate 5 --abort-p95 3000` |
| Duration / concurrency | 300s / ramp 5→30 in-flight GETs |
| Paths | `/health/live`, `/api/search/suggest?q=lin`, `/api/storefront/config`, `/product/linen-camp-shirt` |
| Headers | `X-Store-Id: default`, `X-Channel-Id: web`, `X-Locale: en`, `X-Currency: VND` |
| Requests / errors | 42865 / 587 |
| Error rate | 1.37% |
| RPS | 142.82 |
| p50 | 123.17ms |
| p95 | 144.23ms |
| p99 | 170.84ms |
| min / max | 75.74ms / 997.44ms |
| Early abort | No |

Per-path:

| Path | Requests | Errors | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: | ---: |
| `/health/live` | 10717 | 0 | 123.35ms | 142.54ms | 168.36ms |
| `/api/search/suggest?q=lin` | 10716 | 291 | 129.60ms | 148.22ms | 174.75ms |
| `/api/storefront/config` | 10716 | 296 | 127.05ms | 146.20ms | 172.95ms |
| `/product/linen-camp-shirt` | 10716 | 0 | 102.76ms | 119.61ms | 160.26ms |

Conclusion: the ramp soak passed the harness gates (error rate 1.37% <= 2%, p95
144.23ms <= 1500ms) and met the 800ms p95 API objective for this sample.
Health and PDP stayed error-free, while roughly 2.7% of suggest and storefront
config requests failed; those responses are not broken down by status in this
harness, so the cause is `NOT_PROVEN` from this evidence alone and should be
checked in access logs before claiming a per-route availability SLO. The run
peaked at 30 in-flight requests and 142.82 RPS, so the 200 RPS at c=50 target in
[`SCALE_PLAN.md`](SCALE_PLAN.md) remains unvalidated. This is a single-node
sample, not a 30-day production SLO measurement.



| Objective | Target | SLI | Primary source | Error budget |
| --- | --- | --- | --- | --- |
| Availability | 99.5% | successful `/health/live` checks / total checks | external probe, load-balancer logs, or scheduled `curl` | ~3h 36m / 30d |
| API latency | p95 < 800ms | p95 server-side API latency | `http_response_duration_ms` histogram from `/api/admin/ops/metrics`, or access logs | n/a (latency objective) |
| Checkout success | > 97% | `order.placed` (or orders in paid/processing/completed) / `checkout.start` | `analytics_events`, `/api/v2/admin/reports/overview` | ~3 failed checkouts / 100 |
| Payment failure | < 3% | `payment.failed` / (`payment.done` + `payment.failed`) | `analytics_events`, payment probes | ~3 failed payments / 100 |
| Backup RPO | 24h | age of newest verified dump | pre-migration dumps + restore rehearsal | 0 (page at breach) |
| Restore RTO | 2h | measured rehearsal restore duration | `php scripts/production-proof.php` | 0 (page at breach) |

Notes:

- `/health/live` answers "is the process alive". `/health/ready` answers "can this
  instance serve traffic" and returns `503` on dependency/provider failure; use
  it as a readiness signal, not as the availability SLI.
- The p95 SLO is a service ceiling. The stricter engineering budget in
  [`09_PERFORMANCE_BUDGETS.md`](09_PERFORMANCE_BUDGETS.md) (API p95 <= 300ms,
  place order <= 800ms) still applies.
- Checkout success needs `checkout.start` events. If analytics are disabled or
  incomplete for the window, the SLI is `NOT_PROVEN`; fall back to order-state
  counts only for diagnosis, not for claiming the SLO.
- `payment_fail_spike` is a leading anomaly alert, not the payment SLI. The SLI
  is the ratio over the whole window.

## 2. Signals available today

### Health and readiness

```sh
curl -fsS https://<host>/health/live
curl -fsS https://<host>/health/ready        # 200 ready, 503 not ready
curl -fsS https://<host>/health/providers    # provider config only, no secrets
curl -fsS https://<host>/api/version
# Focused probes:
curl -fsS https://<host>/api/v2/health/queue
curl -fsS https://<host>/api/v2/health/payments
curl -fsS https://<host>/api/v2/health/inventory
curl -fsS https://<host>/api/v2/health/outbox
```

Readiness checks cover database, migrations, queue, outbox, dead letters,
unknown payment operations, search index staleness, and provider configuration.
Responses list missing variable **names** only, never values.

### Metrics and reports (admin bearer token required)

```sh
curl -fsS -H "Authorization: Bearer <admin-token>" https://<host>/api/admin/ops/metrics
curl -fsS -H "Authorization: Bearer <admin-token>" https://<host>/api/admin/ops/observability
curl -fsS -H "Authorization: Bearer <admin-token>" https://<host>/api/admin/ops/readiness
curl -fsS -H "Authorization: Bearer <admin-token>" https://<host>/api/admin/ops-alerts
curl -fsS -H "Authorization: Bearer <admin-token>" \
  "https://<host>/api/v2/admin/reports/overview?from=<iso>&to=<iso>"
curl -fsS -H "Authorization: Bearer <admin-token>" \
  "https://<host>/api/v2/admin/reports/failures?page=1&per_page=20"
```

`/api/admin/ops/metrics` returns OpenMetrics text. Request latency is recorded by
`ProductionBoundaryMiddleware` in the `http_response_duration_ms` histogram
(labelled by method, route bucket, and status); compute p95 from the histogram
buckets in Prometheus or from access logs. A single text read is not a p95.

The `metrics:persist` command runs every 5 minutes and writes the metrics cache
to `backend/storage/framework/metrics.json`; `smart:aggregate` runs hourly at
`:17` and updates anomaly alerts. The overview report exposes order counts,
order state buckets, refunds, inventory, and failure counts. The failures report
lists failed/dead commerce jobs.

### Prometheus scrape endpoint

`GET /metrics` serves Prometheus text (`text/plain; version=0.0.4`) rendered
from the same persisted snapshot (`backend/storage/framework/metrics.json`),
not from live in-memory counters. Missing or invalid snapshots return an empty
`200`. Set `METRICS_TOKEN` and have Prometheus send
`Authorization: Bearer <token>` or `X-Metrics-Token: <token>`; missing/wrong
credentials return `401`/`403`. With no token configured the endpoint is only
served outside production; production returns `404`. The route uses correlation
IDs and a 60/minute throttle, never admin auth.

Every API and health response carries `X-Correlation-ID`. Use it to join request
logs and evidence; do not put credentials, tokens, or provider payloads in logs.

### Anomaly alerts and webhook

`smart:aggregate` opens `refund_spike`, `payment_fail_spike`, or
`fulfillment_fail_spike` when the 24h count is at least 3 and at least 3x the
prior 7-day daily baseline (at least 5 when the baseline is below 1/day).
`OPS_ALERT_WEBHOOK_URL` posts an `open` transition with `kind`, `observed`,
`baseline_x100`, `ratio_x100`, and `at`. Delivery is best-effort (3s timeout), so
the webhook is an accelerator, not the system of record.

Acknowledge from Admin -> Operations -> Anomaly alerts, or:

```sh
curl -X POST -H "Authorization: Bearer <admin-token>" \
  https://<host>/api/admin/ops-alerts/<kind>/ack
```

### Backup and restore evidence

`deploy/release.sh` takes a pre-migration dump and keeps the newest five.
Restore drills use `php scripts/production-proof.php` from `backend/` and record
checksums, row counts, and measured RPO/RTO. See
[`../operations/PRODUCTION_PROOF_RUNBOOK.md`](../operations/PRODUCTION_PROOF_RUNBOOK.md).
A restore without evidence is `NOT_PROVEN`.

## 3. Alert-to-runbook mapping

| Condition | Signal | First action |
| --- | --- | --- |
| `/health/ready` returns 503 for 2+ minutes | readiness probe | [`RUNBOOK.md`](RUNBOOK.md) §5 provider outage; §1 release verification |
| `/health/live` failures / uptime below target | external probe | [`RUNBOOK.md`](RUNBOOK.md) §1, §4 disk and prune |
| API p95 above 800ms for two consecutive windows | metrics + logs | [`09_PERFORMANCE_BUDGETS.md`](09_PERFORMANCE_BUDGETS.md) |
| Checkout success below 97% over 1h/24h | reports + `checkout.start` events | [`RUNBOOK.md`](RUNBOOK.md) §5; inspect `/api/v2/admin/reports/failures` |
| Payment failure above 3% or `payment_fail_spike` open | alerts + analytics | [`RUNBOOK.md`](RUNBOOK.md) §5, §6 |
| `refund_spike` / `fulfillment_fail_spike` open | ops alerts | [`RUNBOOK.md`](RUNBOOK.md) §6 |
| Newest verified backup older than 24h, or restore drill fails | backup evidence | [`RUNBOOK.md`](RUNBOOK.md) §2 |
| Disk pressure or pruned release/backup risk | `df`, `docker system df` | [`RUNBOOK.md`](RUNBOOK.md) §4 |

## 4. Review and ownership

- Engineering owns SLO definitions; operations owns paging and incident records.
- Review error budgets monthly and after any incident. Record threshold changes
  with the evidence that justified them.
- Do not lower a target to match an unmeasured period. Mark the period
  `NOT_PROVEN` and fix collection instead.
- Do not fabricate availability, latency, conversion, payment, RPO, or RTO
  numbers in reports.

## 5. Known gaps

- No external uptime probe is configured in this repository yet. Configure one
  before claiming the availability SLO, or derive it from load-balancer logs.
- No distributed tracing backend; correlation IDs plus request logs are the
  current join key.
- Backend coverage in CI is informational (PCOV) and has no threshold. The
  enforced coverage gate today is the storefront Vitest threshold in
  [`CI_QUALITY.md`](CI_QUALITY.md).
- Linux visual baselines are captured as CI artifacts first and committed
  afterwards; see [`CI_QUALITY.md`](CI_QUALITY.md).

## Related documents

- [`RUNBOOK.md`](RUNBOOK.md): release, backup/restore, rollback, provider outage,
  alert webhook.
- [`../operations/PRODUCTION_OPERATIONS.md`](../operations/PRODUCTION_OPERATIONS.md):
  queue, provider preflight, API observability contract.
- [`../operations/PRODUCTION_PROOF_RUNBOOK.md`](../operations/PRODUCTION_PROOF_RUNBOOK.md):
  backup/restore evidence.
- [`09_PERFORMANCE_BUDGETS.md`](09_PERFORMANCE_BUDGETS.md): engineering latency
  budgets.
- [`CI_QUALITY.md`](CI_QUALITY.md): CI gates, coverage, Linux visual flow.
