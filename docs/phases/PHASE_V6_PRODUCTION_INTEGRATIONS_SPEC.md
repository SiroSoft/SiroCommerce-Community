> LEGACY (completed phase specification; kept for history). See [docs/README.md](../README.md).

# Phase V6 Production Integrations And Ecosystem Specification

## Goal

Close the highest-value production residuals after V5 without weakening fail-closed behavior, provider isolation, financial evidence, privacy, or existing API compatibility.

## Workstream 1: Production Provider Integrations

- Harden payment, tax, and shipping provider adapters for real production configuration.
- Use native SiroPHP HTTP client, secrets/config, timeout, retry, queue, and webhook verification APIs.
- Keep provider calls behind contracts and persist request/response evidence without secrets.
- Support pending/unknown/retryable states and reconciliation for timeout or ambiguous responses.
- Reject missing production credentials and sandbox transports in production.
- Add provider contract tests with deterministic fake transports only in tests.

## Workstream 2: Customer Preferences And Loyalty

- Add customer notification preferences with safe defaults and opt-out enforcement.
- Add loyalty account, points ledger, earn/redeem/expire operations if supported by current customer/order data.
- Make points and preference mutations idempotent and transaction-safe.
- Keep customer ownership, guest boundaries, PII filtering, and lifecycle mail integration intact.
- Add customer-scoped Resources, routes, OpenAPI, and authorization tests.

## Workstream 3: Promotion Refund And Gift Credit

- Reconcile promotion usage when orders are cancelled, refunded, or partially refunded.
- Add gift credit/store-credit contract only where existing payment/order evidence can support it.
- Make credit issuance, redemption, rollback, expiry, currency, and ownership fail closed.
- Preserve promotion evidence on invoice/credit memo/refund records.
- Add concurrent redemption, partial refund, rollback, expiry, and idempotency tests.

## Workstream 4: BI Export And Data Contracts

- Add provider-neutral, paginated exports for orders, payments, refunds, subscriptions, B2B, promotions, fulfillment, and inventory.
- Support bounded date windows, cursor/checkpoint export, schema versions, redaction, and resumable jobs.
- Queue large exports using native SiroPHP Queue and expose status/download metadata safely.
- Add JSONL/CSV contract output without leaking credentials, tokens, or unnecessary PII.
- Add export authorization, replay, failure/retry, and data-consistency tests.

## Workstream 5: API SDK, Performance, And Security

- Add version-safe API contract/client metadata for current `/api` and `/api/v2` surfaces.
- Improve generated OpenAPI/client-safe schemas, pagination/filter documentation, and error contracts.
- Run bounded performance checks for catalog, search, reporting, headless, and checkout reads.
- Add security regression coverage for auth, capabilities, rate limits, PII, file/media, webhook, and GraphQL boundaries.
- Add indexes/cache/eager-loading improvements only with query/response tests proving compatibility.

## Shared Requirements

- Preserve `Controller -> Application -> Contracts -> Infrastructure`.
- Use native SiroPHP FormRequest, Resource, Model, Cache, Database, Queue, Schedule, Events, Metrics, Mail, Storage, HTTP client, and Response APIs where applicable.
- Use outbox/idempotency for all external side effects.
- Use unique migration timestamps and complete rollback methods.
- No production sandbox/mock fallback.
- Agents do not commit independently; integration produces one phase commit.

## Acceptance Criteria

- `php siro commerce:check --continue --json` passes.
- PHPStan passes without suppressions.
- Existing tests remain green; every workstream adds focused regression coverage.
- Production credential/config failures are explicit and fail closed.
- Concurrency, retry, timeout, replay, authorization, privacy, and migration rollback cases are tested.
- OpenAPI, compatibility evidence, production documentation, and working tree are clean.
