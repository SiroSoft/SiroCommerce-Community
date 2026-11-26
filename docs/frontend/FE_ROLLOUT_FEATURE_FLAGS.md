# FE Feature Flags, Rollout, and Rollback

Status: `RELEASE CONTROL`

Flags are operational controls, not a substitute for authorization. Backend
capabilities and the generated OpenAPI contract remain authoritative. A hidden
button must never be the only enforcement boundary.

## Flag contract

Every flag has an immutable key, owner, purpose, type, default, created date,
expiry date, dependencies, kill-switch behavior, audience rule, telemetry name,
and rollback owner. Flag evaluation is deterministic for a subject and remains
stable for a session. Do not include secrets or customer PII in flag context.

Required flag states are `off`, `internal`, `pilot`, `cohort`, and `on`. Unknown
or unavailable flag state fails closed to `off` for writes and sensitive data.
The production default is off unless the release record explicitly promotes it.

## Rollout gates

1. Before enablement: contract tests, unit/component tests, E2E critical journey,
   WCAG scan, performance budgets, analytics validation, and rollback rehearsal
   are attached to the flag record.
2. Internal: staff-only, 100% of intended internal users, minimum 30 successful
   journeys and zero P0/P1 defects.
3. Pilot: 1% or named tenant cohort for at least 60 minutes; error rate,
   checkout conversion, API latency, CWV, and accessibility telemetry remain
   within baseline tolerance.
4. Cohort: 10%, 25%, 50%, then 100%, with at least 24 hours between steps unless
   the release owner records an incident-approved acceleration.
5. Promotion requires two consecutive healthy observation windows and evidence
   links. Financial, checkout, auth, and data-export flags require explicit
   product and engineering approval at every step.

Default abort thresholds are: new P0/P1, error rate +1 percentage point,
checkout failure +0.5 percentage points, duplicate mutation, data exposure,
CWV p75 +10%, or any WCAG release gate failure. Teams may set stricter limits.

## Rollback

The kill switch must be tested before first production enablement and complete
within 5 minutes of approval. Rollback means setting the flag off, stopping
promotion, preserving correlation ids, and verifying old and new routes. It
must not silently replay payment, refund, order, inventory, consent, or export
mutations. Reconcile in-flight writes through the documented idempotency and
recovery workflow.

## Acceptance tests

| ID | Test | Gate |
|---|---|---|
| FLAG-01 | deterministic assignment across refresh and devices for same cohort | 100% stable sample |
| FLAG-02 | unknown, stale, malformed, and unavailable config | fails closed; no crash |
| FLAG-03 | off path and on path both run critical smoke | 0 fail |
| FLAG-04 | kill switch during loading, mutation, and error state | safe UI and no duplicate write |
| FLAG-05 | unauthorized capability with flag on | API 401/403 still enforced |
| FLAG-06 | telemetry includes flag key/version and operationId without PII | schema and privacy pass |
| FLAG-07 | expiry audit | expired flags are removed or renewed before release |

Evidence includes configuration snapshot, audience counts, timestamps, approval,
dashboards, test results, rollback drill, and final state. The release must
name every flag that can change a tested screen.
