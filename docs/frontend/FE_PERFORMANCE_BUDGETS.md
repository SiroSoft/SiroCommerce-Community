# FE Performance Budgets and Core Web Vitals

Status: `RELEASE GATE`

This document adds browser-side budgets to `docs/engineering/09_PERFORMANCE_BUDGETS.md`.
The existing server/API p95 budgets remain mandatory; passing a backend budget
does not waive a frontend regression.

## Measurement contract

Measure production-like builds over HTTPS with source maps available to the
artifact store, no dev tools throttling overrides, and deterministic seeded
data. Lab tests use Lighthouse CI on mobile and desktop profiles. Field tests
use RUM with consent and sample at least 75% of sessions per release cohort.
Report p75 for Core Web Vitals and p95 for long tasks and API-dependent screen
readiness. Every result records commit, environment, route, device profile,
cache state, network, API operation ids, and flag state.

## Budgets

| Budget | Mobile target | Desktop target | Gate |
|---|---:|---:|---|
| LCP p75 | <= 2.5 s | <= 2.0 s | block if exceeded |
| INP p75 | <= 200 ms | <= 200 ms | block if exceeded |
| CLS p75 | <= 0.10 | <= 0.10 | block if exceeded |
| FCP p75 | <= 1.8 s | <= 1.3 s | warning, block at +20% |
| JS transferred, initial route | <= 170 KB gzip | <= 200 KB gzip | block |
| CSS transferred, initial route | <= 70 KB gzip | <= 90 KB gzip | block |
| Total transferred, initial route | <= 500 KB gzip | <= 700 KB gzip | block |
| Long tasks >50 ms, route load | <= 3 | <= 2 | block |
| API-dependent screen readiness | <= 2.5 s p95 | <= 1.5 s p95 | block |

Critical routes are home, category, product, cart, checkout quote, checkout
result, account orders, admin home, admin order detail, admin product list,

## Acceptance tests

| ID | Test | Pass condition |
|---|---|---|
| PERF-01 | Lighthouse CI critical-route matrix | all budgets pass on two consecutive runs |
| PERF-02 | RUM canary after 24 hours | no p75 CWV regression >10% and no budget breach |
| PERF-03 | Build artifact size check | every route and aggregate limits pass |
| PERF-04 | Slow 4G + mid-tier mobile | usable shell, skeleton, retry, and no layout shift |
| PERF-05 | API trace correlation | each blocking request names operationId and server timing |
| PERF-06 | 10k/50k/100k proof dataset | FE pagination uses `page/per_page`; server budgets and query counts pass |
| PERF-07 | Flag-on versus flag-off | no material regression over the approved baseline |

No performance test may hide API calls, disable caching without recording it, or
use production customer data. A failure blocks release until fixed or approved
as an explicit exception with expiry and rollback plan.

## Evidence package

Retain Lighthouse reports, Web Vitals JSON, bundle analyzer output, network
waterfall, trace/correlation ids, API operation ids, dataset/profile, and flag
configuration. Link the same evidence id from the release record and screen
matrix in `docs/frontend/FE_RELEASE_EVIDENCE.md`.
