> LEGACY (completed phase specification; kept for history). See [docs/README.md](../README.md).

# Phase V4 Enterprise Operations Specification

## Goal

Expand SiroCommerce from a strong transaction core into a more complete enterprise commerce backend while preserving the existing module boundaries and native SiroPHP runtime guarantees.

## Workstream 1: Tax, Currency, And Financial Reconciliation

- Add a provider-neutral tax calculation contract with deterministic evidence.
- Preserve order, invoice, credit memo, and payment currency/minor-unit evidence.
- Support tax-inclusive/exclusive calculations where existing order data permits.
- Reconcile payment, refund, credit memo, invoice, and tax totals idempotently.
- Reject currency mismatches and over-refunds fail-closed.
- Keep tax provider calls behind an adapter and persist pending/unknown outcomes.
- Add transaction/locking tests for concurrent reconciliation and partial refunds.

## Workstream 2: Shipping Logistics And Carrier Operations

- Extend the shipping provider contract for rates, labels, tracking, cancellation, and return labels.
- Persist shipment, package, tracking, carrier response, and webhook evidence.
- Add provider-neutral status normalization and replay-safe webhook handling.
- Keep production provider configuration fail-closed; no sandbox fallback in production.
- Queue slow provider operations and preserve pending/retryable states.
- Add authorization, idempotency, timeout, retry, and provider-failure tests.

## Workstream 3: Customer Commerce

- Add customer account order history and self-service order/return visibility.
- Add saved addresses and customer preference surfaces where existing contracts support them.
- Preserve owner/guest authorization and avoid order existence leaks.
- Integrate lifecycle notifications without duplicate mail delivery.
- Add customer-scoped pagination and response Resources.
- Add feature tests for authorization, guest access, pagination, and state restrictions.

## Workstream 4: API, Reporting, And Search

- Add version-safe API contracts for newly expanded operations without breaking `/api` behavior.
- Improve route/OpenAPI completeness and error/security schemas.
- Add bounded admin reporting for orders, sales, refunds, inventory, and operational failures.
- Add paginated, indexed catalog/order/customer search using native query builder/cache facilities.
- Enforce role/capability authorization and prevent PII leakage in reports/search.
- Add performance, pagination, filter, authorization, and API contract tests.

## Shared Requirements

- Preserve `Controller -> Application -> Contracts -> Infrastructure`.
- Use native SiroPHP FormRequest, Resource, Model, Cache, Database transactions, Queue, Schedule, Events, Metrics, Storage, and Response APIs where applicable.
- Use outbox/idempotency for external side effects.
- Use unique migration timestamps and complete rollback methods.
- Update OpenAPI, route coverage, compatibility matrix, and production documentation.
- No agent commits independently; integration produces one phase commit after all gates pass.

## Acceptance Criteria

- `php siro commerce:check --continue --json` passes.
- PHPStan passes without new suppressions.
- Existing tests remain green and new tests cover all four workstreams.
- Provider failures, retries, duplicate events, authorization boundaries, and concurrency are tested.
- No sandbox/mock production fallback is introduced.
- Working tree is clean after the final phase commit.
