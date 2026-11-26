# Backend Performance Proof

The proof runner is bounded and does not manufacture results when the target is
unavailable. It validates the dataset, URL, profile, and sample bound before any
request:

```text
php backend/scripts/bench.php --base-url=http://localhost:8090 --dataset=10000 --samples=20
```

Supported dataset labels are `10000`, `50000`, and `100000`. The runner writes
JSON and Markdown artifacts under `perf/artifacts/`. Profiles are bounded to
`smoke` (1-5 samples), `standard` (1-20), and `soak` (1-100); pass
`--profile` explicitly when using a non-standard bound. Artifacts use schema 2
and record p50/p95/p99 latency, HTTP error rate, status counts, response bytes,
client peak-memory delta, cache headers, and a `db_queries` object.

For a dataset claim to be proven, every response must declare
`X-Proof-Dataset: 10000|50000|100000`. Query/N+1 proof is opt-in and requires
`X-Query-Count` and `X-N-Plus-One: false` response headers. Missing or conflicting
server evidence is `NOT_PROVEN`, never a zero or inferred count. An unreachable
target or missing required state is `BLOCKED`; an HTTP/budget violation is
`FAIL`. `NOT_PROVEN` and `BLOCKED` exit successfully so unavailable
infrastructure is reported rather than misrepresented as a benchmark failure.

## Existing Scenario Audit

`perf/k6/product-read.js` covers only a public product list and has no response,
memory, query, or dataset assertions. `perf/k6/checkout-spike.js` uses a dummy
cart and therefore remains a duplicate-key smoke probe, not checkout proof.
`perf/k6/production-proof.js` is the bounded replacement for repeatable surface
loads; stateful/admin scenarios stay blocked until their real IDs and tokens are
provided.

The following are deliberately `BLOCKED`, rather than inferred, unless the
required target configuration exists: authenticated admin routes, quote and
signed webhook state, server memory, query count, N+1 query shapes, cache hit
rate, and queue/outbox drain. Queue/outbox proof must run against a real worker
and representative data; the HTTP runner does not pretend that a health check
is a drain benchmark.

For local k6 load, use `perf/k6/production-proof.js` with bounded `VUS` and
`ITERATIONS`, for example:

```text
k6 run -e BASE_URL=http://localhost:8090 -e DATASET=10000 -e PROFILE=standard -e SCENARIO=catalog-browse perf/k6/production-proof.js
```

The k6 script accepts only `smoke`, `standard`, and `soak` profiles and checks
the declared dataset and query/N+1 headers. It also supports `health-live` and
`health-ready`, verifies baseline security headers, and fails ordinary scenarios
that are unexpectedly rate limited (webhook bursts are the explicit exception).
It is a bounded load probe; it does
not replace the PHP evidence artifact or prove server memory, query shapes,
cache hit rate, or worker drain without target instrumentation.

`k6` output is not committed as proof. Preserve the command, target dataset,
runtime versions, and raw output alongside the generated PHP artifacts. The normal gate does not require query evidence; request it explicitly with `REQUIRE_QUERY_EVIDENCE=1` only when the target emits both `X-Query-Count` and `X-N-Plus-One`.

## B2C launch certification

The single release command is `npm run release:gate`. It is intentionally
fail-closed and writes a redacted manifest plus one log per check beneath
`backend/storage/evidence/b2c-release-gate/<run-id>/`.

```text
RELEASE_COMMIT=<commit> RELEASE_ACTOR=<operator> npm run release:gate
```

The gate runs typecheck, both storefront/admin builds, OpenAPI contract and
handoff checks, static migration/backup policy checks, health/readiness/security
headers/rate limits, provider configuration preflight, release-scoped Playwright
journeys, and the bounded k6 catalog/search/checkout thresholds. Set
`BACKUP_MANIFEST=/path/backup-manifest.json` to run the isolated backup restore
and migration `up -> down -> up` rehearsal. Without it the item is
`BLOCKED_EXTERNAL`, never silently skipped. `SKIP_PERF_GATE=1` is recorded as
`BLOCKED_EXTERNAL` and is not a release pass.

It also runs `php scripts/run-provider-certification.php` for deterministic local
shipping/tax/fulfillment/messaging contract evidence and
`php scripts/certify-payment.php`. Payment certification is expected to be
`BLOCKED_EXTERNAL` until explicit live payment evidence and live credentials
are supplied. Set `PROVIDER_LIVE_SMOKE=1` only for an isolated provider smoke
run with approved credentials; the gate never infers live certification from a
local fixture or readiness response.

Exit semantics are stable: `0` means every applicable check passed, `1` means a
proven local or target failure, and `2` means an external prerequisite was
unavailable. A launch decision cannot promote `BLOCKED_EXTERNAL`; attach the
manifest and retained logs to the release record.

Run health, security-header, catalog, and optional rate-limit checks:

```text
npm run certify:launch
BASE_URL=http://localhost:8090 CERT_RATE_LIMIT=1 npm run certify:launch
```

The rate-limit probe is opt-in because it consumes the health limiter window. A ready response may be `503` when a dependency is unavailable, but its payload must say `not_ready`.

Run bounded B2C API journeys with k6:

```text
k6 run -e BASE_URL=http://localhost:8090 -e PROFILE=standard -e SCENARIO=catalog-browse perf/k6/production-proof.js
k6 run -e BASE_URL=http://localhost:8090 -e PROFILE=standard -e SCENARIO=search -e SEARCH_TERM=linen perf/k6/production-proof.js
k6 run -e BASE_URL=http://localhost:8090 -e PROFILE=smoke -e SCENARIO=product-detail -e PRODUCT_SLUG=linen-camp-shirt perf/k6/production-proof.js
k6 run -e BASE_URL=http://localhost:8090 -e PROFILE=smoke -e SCENARIO=checkout-journey -e VARIANT_ID=siro-chanh perf/k6/production-proof.js
```

The checkout journey requires disposable seeded data and creates guest orders. Do not run it against production without cleanup and payment policy. Thresholds are p95 < 500 ms, p99 < 800 ms, and HTTP error rate < 1%.

Run storefront release checks:

```text
npm run test:e2e --workspace apps/storefront
PLAYWRIGHT_RELEASE=1 npm run test:e2e --workspace apps/storefront
PLAYWRIGHT_BASE_URL=https://staging.example.test PLAYWRIGHT_REAL_CHECKOUT=1 npm run test:e2e --workspace apps/storefront -- --grep "real guest checkout"
```

The real checkout test is skipped unless explicitly enabled and requires a seeded disposable environment. Browser binaries, a running target, database/provider credentials, and external payment/shipping services are external dependencies.
