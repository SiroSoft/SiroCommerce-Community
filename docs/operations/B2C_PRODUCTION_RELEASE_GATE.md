# B2C Production Release Gate

## Command

Run from the repository root with a release identity:

```powershell
$env:RELEASE_COMMIT = "<immutable commit>"
$env:RELEASE_ACTOR = "<operator or CI identity>"
$env:BASE_URL = "https://staging.example.test"
$env:BACKUP_MANIFEST = "backend/storage/evidence/<run>/backup-manifest.json"
npm run release:gate
```

The command writes `backend/storage/evidence/b2c-release-gate/<run-id>/manifest.json`
and check logs. Preserve that directory with the Playwright report, k6 raw
output, API handoff evidence, migration/restore manifest, provider preflight,
and monitoring links. Never store credentials, customer data, bearer tokens, or
provider response bodies in evidence.

## Prerequisites

- Node/npm dependencies installed from the lockfile; PHP 8.2, Composer vendor dependencies, and the required PHP extensions.
- Playwright browser binaries and a running storefront target, or local fixture mode. `PLAYWRIGHT_BASE_URL` selects an external target; otherwise Playwright starts the local storefront.
- `BASE_URL` must point to the backend target for health, security, and k6 checks. The target must expose `/health/live`, `/health/ready`, and the B2C catalog/cart/checkout APIs.
- `BACKUP_MANIFEST` must describe a verified disposable database and uploads backup. It is required for migration rollback and restore evidence.
- k6 must be on `PATH` for performance proof. Thresholds are p95 `< 500 ms`, p99 `< 800 ms`, and HTTP errors `< 1%`.
- Production provider credentials, HTTPS endpoints, webhook secrets, and isolated disposable provider accounts are required for live certification. Configuration preflight is not live provider evidence.
- `PROVIDER_LIVE_SMOKE=1` explicitly enables live shipping/tax/provider smoke; without it the gate records `BLOCKED_EXTERNAL`. Payment live certification additionally requires `APP_ENV=production`, provider credentials, and `PAYMENT_CERTIFICATION_LIVE=1`. Neither local sandbox certification nor readiness can produce a live claim.
- A real queue worker and representative data are required for queue/outbox drain evidence. Health endpoints do not prove worker drain.

## Coverage

The gate covers build/typecheck, OpenAPI contract and handoff, migration
static checks plus isolated rollback rehearsal, health/readiness, security
headers and rate limits, guest browse/search/product/cart/checkout/order-result,
authenticated account/returns/subscriptions, provider readiness, and k6
performance thresholds. Playwright fixture journeys are safe functional proof;
real checkout/provider/queue/restore evidence remains environment-bound.

## Semantics

- `PASS` is evidence-backed and applicable.
- `FAIL` is a proven defect, command failure, threshold violation, or failed target check. Do not promote.
- `BLOCKED_EXTERNAL` means a required environment, browser, target, provider, backup, worker, or k6 executable was unavailable. It is not a pass and requires an owner and follow-up evidence.
- The final decision is `PASS` only when every check is `PASS`; one or more `BLOCKED_EXTERNAL` checks produce exit code `2`, even when all offline checks pass. Provider sandbox output is labeled local contract evidence and does not change this rule.
- `NOT_RUN` is only allowed in separately maintained certification manifests with a reason. The gate itself uses blocked status for unavailable prerequisites.

Exit codes are `0` PASS, `1` FAIL, and `2` BLOCKED_EXTERNAL. The gate does not convert unavailable services into synthetic successes.

## Rollback

1. Stop promotion and record the gate manifest, release identifier, correlation IDs, and current metrics dashboard links.
2. If the release is not serving traffic, redeploy the last known-good immutable image/version. Do not edit migration identities or delete applied migration records.
3. If schema compatibility is preserved, roll back application code first. If a destructive migration was applied, stop writes, use the approved backup restore rehearsal/runbook, and reconcile orders, payments, inventory, shipments, subscriptions, and outbox rows before traffic returns.
4. Drain or quarantine queue/outbox work according to the worker runbook; retry only idempotent work with its original idempotency key.
5. Re-run health/readiness, security, guest checkout, authenticated account/return/subscription, provider, and performance checks before reopening traffic.

## Backup/Restore

Use `backend/scripts/backup-restore-preflight.php` with a checksum-complete
manifest. It stages inputs in an isolated evidence directory and runs
migrations `up -> down(reverse) -> up`; it never overwrites live data. For
production MySQL/object storage restore, encryption, immutability, retention,
 RPO/RTO, and reconciliation, use `docs/operations/PRODUCTION_PROOF_RUNBOOK.md`
 and retain the external operator evidence. The policy requires 35-day backup
 retention, 90-day evidence retention, 15-minute RPO, and 1-hour RTO.

## Incident Response

Declare an incident for checkout/payment unknowns, duplicate orders, inventory
drift, readiness 503, queue dead letters, provider timeouts, security-header
regressions, or threshold breaches. Assign an incident lead, freeze deploys,
preserve the gate manifest and logs, capture correlation IDs, and link the
metrics/trace/error dashboard. Prefer read-only or maintenance mode over
partial checkout. Reconcile payment, order, inventory, shipment, subscription,
and notification state before retrying writes. Close only after a remediation
deploy passes the complete gate and the timeline/root cause/actions are stored.

## Evidence Locations

- Gate manifest and logs: `backend/storage/evidence/b2c-release-gate/<run-id>/`
- API contract/handoff: `backend/docs/api-handoff/` and command output
- Backup/restore and migration rehearsal: the gate evidence `backup-restore/`
- Provider preflight/live smoke: retained release evidence, never secrets
- Browser artifacts: Playwright `test-results/` and report output
- Performance: raw k6 output plus generated `perf/artifacts/` evidence
- Static policy and operational controls: `docs/operations/`, `docs/engineering/`, and `docs/qa/`
