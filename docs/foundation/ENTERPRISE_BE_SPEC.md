# SiroCommerce Enterprise Backend Specification

Status: enterprise baseline, not a certification. This document defines the
controls and evidence required before SiroCommerce can be operated as a
business-critical commerce platform. Legal counsel, the payment acquirer, and
the hosting provider remain responsible for confirming jurisdiction-specific
obligations.

## 1. Enterprise Outcomes

The backend must be:

- financially correct: no duplicate charge, over-refund, inventory oversell,
  or untraceable adjustment;
- secure by default: least privilege, explicit trust boundaries, redaction,
  secret management, and verifiable provider signatures;
- resilient: bounded retries, durable outbox, reconciliation, restore proof,
  and tested failure recovery;
- operable: SLOs, alerts, runbooks, audit trails, ownership, and on-call paths;
- change-safe: versioned contracts, reviewed migrations, rollback plans, and
  supply-chain evidence;
- extensible without loss of control: hooks/providers are capability-scoped,
  isolated, observable, and removable.

This is a modular monolith implementation baseline. It is not permission to
claim PCI DSS, SOC 2, ISO 27001, GDPR, or other certification without an
independent assessment and applicable legal evidence.

## 2. Governance And Accountability

### 2.1 Decision Rights

| Decision | Accountable owner | Required reviewers |
|---|---|---|
| Domain/integrity change | Data owner | Engineering, QA |
| Payment/provider change | Payment owner | Security, Operations |
| Customer/PII change | Privacy owner | Security, Legal where required |
| Auth/RBAC/secrets change | Security owner | Engineering, Operations |
| Schema destructive change | Engineering owner | Data, Release |
| Production release | Release approver | All impacted owners |
| Critical exception | Business owner | Security and independent reviewer |

No person may approve their own critical exception. Every owner is a named
role, not only a team alias. Delegation and expiry are recorded.

### 2.2 Required Records

Every material change has a change record, risk assessment, affected controls,
test evidence, migration/rollback plan, and approval. Every incident has a
timeline, impact, root cause, corrective actions, and control updates.

## 3. System Context And Trust Boundaries

```text
Browser/mobile/client       untrusted
        | TLS + API auth
API edge/rate limiter        controlled boundary
        | validated commands
Commerce application         trusted execution boundary
        | ports only
Payment/shipping/tax vendors external trust boundary
        | signed webhooks
Queue/outbox workers         controlled asynchronous boundary
Database/object storage      restricted data boundary
Admin/operator                privileged human boundary
```

Trust crossing requires authentication, authorization, validation, timeout,
correlation ID, audit record, and failure semantics. Provider responses are
untrusted input even after signature verification.

## 4. Data Classification And Lifecycle

| Class | Examples | Controls |
|---|---|---|
| Public | product name, public price, sitemap | integrity, cache policy |
| Internal | metrics, non-sensitive configuration | authenticated access |
| Confidential | order totals, addresses, customer history | RBAC, encryption in transit/at rest, retention |
| Restricted | passwords, JWT secrets, provider credentials, payment tokens | vault/KMS, no logs, rotation, break-glass audit |

Rules:

1. Data inventory maps every table, field class, owner, purpose, retention, and
   deletion/anonymization behavior.
2. Customer export/delete requests are authenticated, scoped, audited, and
   tested against legal/financial retention holds.
3. Payment card data is out of scope for storage. Use provider-hosted/tokenized
   flows; never store PAN, CVV, magnetic data, or raw provider secrets.
4. Logs and traces redact credentials, tokens, payment data, and unnecessary
   PII before persistence.
5. Backups inherit the highest classification of their contents, are encrypted,
   access logged, tested for restore, and expire according to policy.

## 5. Security Architecture

### 5.1 Identity And Access

- Access JWTs are short-lived; refresh tokens rotate and are revocable.
- Admin access requires role plus capability at the service boundary.
- Production privileged access requires MFA through the deployment/identity
  platform and is never shared.
- Service accounts are non-human, least privilege, scoped per environment, and
  rotated without code changes.
- Access reviews run at least quarterly and after role/team changes.
- Break-glass access has an incident/change ID, short expiry, and post-review.

### 5.2 Secrets And Cryptography

- Secrets come from an approved secret manager or environment injection, never
  source control, fixtures, logs, URLs, or error responses.
- Rotation supports overlap: new credential is deployed, verified, then old is
  revoked. Rotation is tested before expiry.
- TLS is required at external boundaries. Certificate expiry is monitored.
- Passwords use a current adaptive password hash and an upgrade path.
- Hashes are used for guest tokens, webhook dedupe payloads, and evidence
  integrity where reversible storage is not needed.

### 5.3 Application Security

- Validate/allowlist every write, sort, filter, file, provider event, and hook.
- Use parameter binding; no dynamic SQL from request input.
- Enforce request size, rate limits, replay windows, CORS, CSRF policy for
  cookie-authenticated flows, and security headers.
- Error responses expose stable codes, not SQL, provider secrets, stack traces,
  or internal file paths.
- Dependencies are locked, scanned, reviewed, and patched by severity SLA.

## 6. Privacy And Payment Boundary

The system maintains a data-flow diagram for customer, payment, shipping, tax,
analytics, email, and support data. Each vendor has purpose, fields sent,
region, retention, subprocessors, and deletion/incident contact recorded.

Payment architecture:

```text
Client -> tokenized/provider-hosted payment UI -> provider token
Backend -> provider API using restricted credential
Provider -> signed webhook -> raw event verifier -> queue -> reconciliation
```

Backend stores only provider token/reference, operation state, amount/currency,
and redacted evidence. Any change that expands the card-data boundary requires
security and payment-owner approval before implementation.

## 7. Reliability And SLOs

Initial production targets, measured monthly per tenant/store and excluding
approved maintenance:

| Service indicator | Target | Alert threshold |
|---|---:|---:|
| Read API availability | 99.9% | <99.95% rolling 30m |
| Checkout command availability | 99.95% | <99.99% rolling 15m |
| Successful non-provider checkout | 99.9% | error budget burn 2x |
| Webhook accepted within 2s | 99.9% | p95 >2s |
| Outbox age | <60s p95 | any >5m |
| Queue dead letters | 0 unreviewed | any new critical job |
| Backup completion | 100% scheduled | one missed run |
| Restore rehearsal | quarterly | overdue rehearsal |

SLOs require an owner, measurement source, alert, runbook, and error budget.
The system must not spend error budget on unmeasured behavior.

## 8. Disaster Recovery And Business Continuity

Initial tier-1 targets:

```text
RPO: 15 minutes for transactional data
RTO: 60 minutes for checkout and admin order operations
```

The final values are approved by the business owner and may be stricter.

Required controls:

- encrypted automated database backups with immutable retention;
- uploads/config/schema/version metadata included in recovery plan;
- cross-zone or cross-region copy where the hosting tier supports it;
- restore rehearsal at least quarterly and after major storage changes;
- documented degraded mode for provider, queue, cache, mail, and search outage;
- DNS/certificate/secret recovery dependencies recorded;
- recovery order: database, application, queue/outbox, providers, read paths;
- post-restore reconciliation for orders, payments, inventory, shipments, and
  outbox messages.

## 9. Incident, Problem, And Change Management

Severity:

| Level | Example | Response |
|---|---|---|
| SEV-1 | duplicate charge, data loss, checkout unavailable | immediate incident commander |
| SEV-2 | major degradation, payment reconciliation backlog | owner within 15 minutes |
| SEV-3 | bounded feature failure/workaround | business hours response |
| SEV-4 | defect without material impact | backlog and trend review |

Incident records contain detection, customer/business impact, timeline in UTC,
actions, communications, evidence, containment, recovery, root cause, and
corrective/preventive controls. Post-incident review is blameless and creates
tracked actions with owners and due dates.

Changes use four risk levels. High-risk changes require a maintenance plan,
rollback rehearsal or proven rollback, migration lock analysis, observability,
and an explicit release window. Emergency changes receive retrospective review
within one business day.

## 10. Secure SDLC And Supply Chain

Required for every merge:

- peer review by a non-author for sensitive paths;
- formatting/lint, static analysis, unit/integration tests, and secret scan;
- dependency lockfile and vulnerability scan;
- migration review with forward/backward compatibility;
- API/OpenAPI compatibility check;
- SBOM and build provenance for release artifacts;
- signed/tagged release artifact with source commit and build environment;
- no generated/runtime artifacts or secrets in source control.

Critical vulnerability remediation is immediate, high is within the approved
security SLA, and accepted risk requires an exception with expiry.

## 11. Observability And Audit Trail

Audit events are append-only and include event ID, actor/service, action,
target, before/after summary, reason, correlation ID, source IP where lawful,
result, and timestamp in UTC. Sensitive values are hashed/redacted.

Audit logs are separate from debug logs, access-controlled, clock-synchronized,
retained by policy, exported to immutable storage where required, and monitored
for deletion/tampering. The audit trail must cover:

```text
login/role/access changes, config/secret rotation, product/price changes,
inventory adjustments, payment operations, refunds, webhook decisions,
order/fulfillment transitions, extension lifecycle, exports/deletes,
backup/restore, break-glass access, and release approvals
```

## 12. Financial And Operational Controls

- Orders, payments, refunds, inventory ledger, and payout references reconcile
  daily or at an approved interval.
- Reconciliation reports identify missing, duplicate, pending, unknown, and
  mismatched records with owner and resolution state.
- Manual inventory/payment adjustments require capability, reason, actor, and
  before/after values.
- Refunds require cumulative-limit checks and an auditable provider operation.
- Price/promotion changes record who changed what, effective window, and rule
  version; historical orders never recalculate.

## 13. Vendor And Extension Risk

Every payment, tax, shipping, mail, search, analytics, and extension vendor has
an inventory record containing owner, data shared, permissions, SLA, incident
contact, version, renewal, exit plan, and criticality.

Critical extensions must pass compatibility, timeout isolation, migration,
disable, uninstall, rollback, and data-export tests. A vendor outage must not
silently corrupt order, payment, inventory, or audit state.

## 14. Compliance Mapping

The audit folder maps implementation controls to evidence. The minimum mapping
families are:

| Family | Relevant baseline |
|---|---|
| Access/security | least privilege, auth, secrets, vulnerability management |
| Change management | review, CI gates, migration/rollback, release approval |
| Availability | SLO, alerting, queue/outbox, backup, restore, DR |
| Integrity | money, inventory, payment, fulfillment, reconciliation |
| Privacy | classification, minimization, retention, export/delete |
| Auditability | immutable audit records, evidence retention, sign-off |
| Vendor risk | provider/extension inventory and exit controls |

This mapping is a control baseline, not a claim that any external framework is
certified. A certification project must add its auditor-specific control set.

## 15. Enterprise Readiness Gates

BE_V2 is enterprise-ready only when all are true:

1. Critical controls are `PASS` with reproducible evidence.
2. No critical/high unexpired exception blocks the release.
3. MySQL concurrency, load, backup restore, rollback, and F01-F11 drills pass.
4. Payment/card-data boundary is reviewed and provider responsibilities are
   documented.
5. SLO dashboards, alerts, runbooks, on-call, and escalation are live.
6. Access review, data inventory, retention, vendor inventory, and incident
   process have accountable owners.
7. Release artifact is reproducible, scanned, versioned, and approved.
8. Business owner accepts residual risk and signs the release record.

Only after this gate passes may frontend implementation begin. Frontend does
not lower backend controls or create an exception implicitly.
