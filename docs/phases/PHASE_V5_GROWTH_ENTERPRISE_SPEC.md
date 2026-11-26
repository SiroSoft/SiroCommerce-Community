> LEGACY (completed phase specification; kept for history). See [docs/README.md](../README.md).

# Phase V5 Growth And Enterprise Specification

## Goal

Extend SiroCommerce with recurring commerce and enterprise controls while preserving the existing transaction, authorization, queue, idempotency, and provider boundaries.

## Workstream 1: Subscriptions And Recurring Billing

- Add subscription plans, customer subscriptions, billing intervals, lifecycle states, and next-billing evidence.
- Support start, pause, resume, cancel, and renewal operations with idempotency.
- Queue renewal attempts and persist pending, succeeded, failed, and retryable payment outcomes.
- Reuse the payment provider registry and existing payment reconciliation contracts.
- Prevent duplicate renewals and reject currency/amount mismatches.
- Add scheduled renewal selection without charging directly inside a scheduler request.
- Add authorization, retry, failure recovery, and state-transition tests.

## Workstream 2: B2B Workflow And Credit Approval

- Add company/customer membership and role-scoped purchasing access where existing schema permits.
- Add company credit limit, available balance, payment terms, and approval evidence.
- Support draft/submit/approve/reject B2B orders with idempotent transitions.
- Enforce credit reservation and release under transaction/locking rules.
- Keep customer data and company orders isolated by membership/capability.
- Add admin and company-user routes with Resources, OpenAPI, and authorization tests.

## Workstream 3: Promotions And Discount Rules

- Add provider-neutral promotion rules for fixed, percentage, cart, item, customer-group, date-window, and usage-limit cases supported by the current pricing model.
- Validate stacking/exclusivity, currency, minimum spend, product/category scope, and usage counters.
- Persist promotion application evidence on quote/order snapshots.
- Make usage reservation and rollback transaction-safe and idempotent.
- Reject expired, inactive, over-limit, or unauthorized promotion use fail-closed.
- Add focused pricing, concurrency, checkout, and refund evidence tests.

## Workstream 4: Analytics And Integration Contracts

- Extend event/analytics evidence for orders, payments, refunds, subscriptions, B2B approvals, promotions, and fulfillment outcomes.
- Add provider-neutral export/read contracts for operational analytics without exposing PII by default.
- Add webhook/event schema versioning and replay-safe integration evidence.
- Add bounded admin analytics endpoints with pagination, date windows, capability authorization, and cache controls.
- Add machine-readable integration contract documentation and generated client-safe OpenAPI surfaces where appropriate.
- Add tests for event payload schemas, redaction, deduplication, replay, and authorization.

## Shared Requirements

- Preserve `Controller -> Application -> Contracts -> Infrastructure`.
- Use native SiroPHP FormRequest, Resource, Model, Cache, Database transactions, Queue, Schedule, Events, Metrics, Mail, Storage, and Response APIs where applicable.
- Use durable outbox/idempotency for provider and integration side effects.
- Use unique migration timestamps and complete rollback methods.
- No sandbox/mock provider fallback in production.
- Update OpenAPI, route coverage, compatibility matrix, and production documentation.
- Agents do not commit independently; integration creates one phase commit after all gates pass.

## Acceptance Criteria

- `php siro commerce:check --continue --json` passes.
- PHPStan passes without new suppressions.
- Existing tests remain green and each workstream has focused regression tests.
- Duplicate, retry, timeout, authorization, concurrency, and state-transition cases are covered.
- Currency and financial evidence remain deterministic.
- Working tree is clean after the final phase commit.
