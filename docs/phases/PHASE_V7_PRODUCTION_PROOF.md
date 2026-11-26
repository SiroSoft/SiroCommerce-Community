> LEGACY (completed phase proof pack; kept for history). See [docs/README.md](../README.md).

# Phase V7 Backend Production Proof

This is an evidence pack for backend release approval. It is not a PCI, SOC 2,
ISO 27001, GDPR, or availability certification.

## Release Gate

Run from `backend/`:

```text
php siro commerce:check --continue --json
php vendor/bin/phpunit --no-coverage tests/Unit/ProductionBoundaryTest.php tests/Unit/HeadlessGraphQlTest.php
php vendor/bin/phpstan analyse --level=max --no-progress
php scripts/security-audit.php
php scripts/production-proof.php --out=storage/evidence/<run-id> --backup-created-at=<UTC ISO-8601>
php siro commerce:ops readiness
```

Block release on any failed check, unknown payment, unresolved critical dead
letter, failed audit-chain verification, migration drift, or missing backup /
restore evidence. Attach command output, commit SHA, dependency lockfile,
SBOM/provenance, migration plan, rollback artifact, approver and UTC timestamp
to the release record.

## SLOs And Alerts

| Indicator | Target | Alert | Owner/runbook |
|---|---:|---:|---|
| Read API availability | 99.9% monthly | <99.95% for 30m | API on-call / `api-degraded` |
| Checkout command availability | 99.95% | <99.99% for 15m | Commerce on-call / `checkout-degraded` |
| Webhook accepted within 2s | 99.9% | p95 >2s | Payments / `payment-provider` |
| Outbox age | p95 <60s | any message >5m | SRE / `outbox-stalled` |
| Unreviewed dead letters | 0 | any new critical job | SRE / `dead-letter` |
| Unknown payment operations | 0 | any new row | Payments / `payment-reconcile` |
| Backup completion | 100% | one missed run | Data owner / `backup-restore` |
| Index/migration readiness | 100% | drift or lag at deploy | Release / `migration-rollback` |

Metrics are emitted by the HTTP, GraphQL, webhook, payment, queue and outbox
paths and are exported through the protected operations metrics endpoint.
Tenant/store labels must be bounded; never label metrics with email, token,
order number, raw URL, or provider payload.

## Runbooks

### `outbox-stalled` / `dead-letter`

1. Page SRE and the owning domain team; stop automated replay if side effects
   are uncertain.
2. Inspect `commerce:ops outbox status` and `commerce:ops jobs status`; preserve
   correlation IDs and the original idempotency key.
3. Verify provider state before `commerce:ops outbox replay --id=...` or
   `queue:retry`; use dry-run first and record actor/reason in audit evidence.
4. Escalate any duplicate, missing, or irreversible side effect as SEV-1.

### `payment-reconcile`

Run `commerce:ops payments` without `--apply`. Unknown and mismatched records
remain blocked until the provider response, amount, currency and idempotency key
are independently verified. Apply only after payment-owner approval and retain
the reconciliation output.

### `api-degraded` / `checkout-degraded`

Check readiness, database latency/error rate, migration version, queue age and
provider latency. Disable nonessential writes or provider retries before
increasing limits. Roll back only with the approved artifact and migration
rollback plan; reconcile orders, payments and inventory after recovery.

### `backup-restore` / `migration-rollback`

Confirm encrypted backup completion and immutable retention, then perform the
quarterly restore rehearsal in an isolated environment. Verify schema version,
uploads/config, queue/outbox, payment reconciliation and audit-chain integrity.
Do not mark ready from a successful process exit alone.

## Evidence Matrix And Residual Risk

| Control | Reproducible evidence | Residual risk/blocker |
|---|---|---|
| Auth/capability | boundary tests, route inventory | production MFA/access review is external evidence |
| Redaction | provider/analytics and trace redaction tests | log sink retention and sampling require platform proof |
| Limits | request body, throttle, file and GraphQL tests | distributed Redis fail-closed policy must be configured |
| Correlation/audit | `X-Correlation-ID`, audit verify, ops output | immutable export and clock sync require platform proof |
| Queue/outbox/payments | readiness, reconciliation, dead-letter commands | live worker/provider drills require staging credentials |
| Dependency/release | lockfile, PHPStan, security audit, this gate | SBOM/signing/provenance must be attached by CI |

## Approval Checklist

- [ ] Named security, payment, data, operations and release approvers recorded.
- [ ] No unexpired critical/high exception blocks this release.
- [ ] MySQL concurrency, load budget, backup restore and F01-F11 drills pass.
- [ ] Migration lock analysis and rollback artifact reviewed.
- [ ] Provider signatures, timeouts, retries and idempotency verified.
- [ ] SLO dashboards, alerts, on-call escalation and runbooks are live.
- [ ] Production environment has non-placeholder secrets and `FE_SHARED_SECRET`.
- [ ] Business owner accepts residual risk in the release record.
