# SiroCommerce Backend Overview

Status: `READY FOR FRONTEND IMPLEMENTATION`

This is the backend entry point. Detailed behavior remains in the linked domain
specifications and the generated API contract.

## Product Shape

SiroCommerce is a modular monolith built on SiroPHP Core. The backend exposes a
versioned HTTP API for the storefront and admin applications. It is not a
microservices deployment.

```text
Storefront / Admin FE
        |
        v
SiroPHP Router + Middleware + Controllers
        |
        v
Application Services + Domain Contracts
        |
        v
Repositories / Models / MySQL
        |
        +--> Queue / Outbox / Webhook / Cache / Search
        +--> Replaceable provider adapters
```

Architecture details: `docs/foundation/01_ARCHITECTURE.md` and `docs/foundation/BE_V2_SPEC.md`.

## Module Map

| Module | Responsibility |
|---|---|
| Catalog | Products, variants, options, categories, brands, media and product types |
| Customer | Identity, guest access, addresses, preferences, loyalty and privacy |
| Pricing | Prices, promotions, coupons, currency and price snapshots |
| Cart | Cart lines, pricing snapshots and guest cart tokens |
| Checkout | Quote, inventory reservation, payment intent and order orchestration |
| Inventory | Sources, stock ledger, ATP, reservations, transfers and adjustments |
| Payment | Payment intents, capture, refund, void and reconciliation |
| Shipping | Rates, shipments, tracking and fulfillment allocation |
| Fulfillment | Picking, packing, delivery, returns and reverse logistics |
| Order | Immutable order snapshots and named state transitions |
| CMS/SEO | Pages, blocks, menus, revisions, preview, publish and restore |
| B2B | Companies, memberships, catalogs, quotes, approvals and credit |
| Reporting | Analytics, search, reports, BI exports and downloads |
| Webhook | Raw body verification, deduplication, durable dispatch and replay safety |
| Platform | Health, readiness, API version, observability and OpenAPI |
| Extension | Manifest validation, lifecycle, hooks and capabilities |

Each module owns its data and invariants. Cross-module access goes through
services, repositories, ports or published events, not another module's models.

## Request Lifecycle

1. Router matches the versioned endpoint.
2. Correlation, frontend guard, authentication, capability and throttle
   middleware run fail-closed.
3. Controller validates the HTTP input and maps it to an application service.
4. Service applies authorization, CommerceScope, transaction and idempotency.
5. Repository performs bound database operations through the owning model.
6. State changes use named transitions and write audit/outbox evidence.
7. Response uses the stable success/error envelope.

Controllers do not contain business transactions, direct SQL or provider logic.

## API Contract

Current generated contract:

- `469` route declarations in `backend/routes/api.php` and `469` OpenAPI operations across `406` paths (verified 2026-11-27).
- Last checked-in handoff snapshot: `469` route records, `910/2345` layer records PASS, readiness `NOT_READY_BLOCKED`; rerun `composer handoff:matrix` before quoting evidence.

Source of truth:

- `backend/docs/openapi.json`
- `backend/public/openapi.json`
- `backend/generated/sirocommerce.ts`
- `backend/docs/api-handoff/FE_HANDOFF.md`
- `docs/frontend/FE_IMPLEMENTATION_PLAN.md`

All responses use:

```json
{"success": true, "message": "...", "data": {}, "meta": {}}
```

Errors use `meta.error_code` and optional `meta.errors`. Frontend code must
branch on the stable error code, never on the human-readable message.

## Authentication And Authorization

- JWT access and refresh token rotation.
- Role and capability middleware is fail-closed.
- Customer ownership comes from the token, not a submitted customer id.
- Admin operations require the appropriate role/capability and FE guard where
  configured.
- Public storefront product reads use the default storefront scope.
- CMS, headless, admin catalog/order/reporting and cross-tenant reads enforce
  explicit store/channel/locale/currency scope.
- Reporting and export identifiers are validated against the active scope.

Scope details: `docs/contracts/COMMERCE_SCOPE.md`.

## Data And Reliability Rules

- MySQL 8 is the production database contract.
- Money is stored as integer minor units with explicit currency.
- Stock is owned by Inventory and changed through atomic reservations/ledger
  operations.
- Orders preserve immutable historical pricing snapshots.
- Mutating commands are idempotent where marked by OpenAPI.
- Jobs use durable attempts, leases, retry/backoff and dead-letter behavior.
- Webhooks verify signatures on the raw body before parsing, deduplicate event
  ids and safely handle replay.
- External side effects carry operation ids, audit data and retry state.
- Cache and search reads are explicitly eventually consistent.

## Operational Endpoints

- Public liveness: `/health/live`.
- Public readiness: `/health/ready` with safe disclosure.
- Detailed readiness and operational diagnostics require admin access.
- `/api/version` exposes API/core compatibility metadata.
- Correlation ids are returned for support and tracing.

## Verification Status

Verified on local MySQL 8.0.46:

- HTTP workflow: `82/82` tests pass.
- Customer engagement: `8/8` tests pass.
- Payment/webhook: `10/10` tests pass.
- Inventory: `14/14` tests pass.
- Platform API: `29/29` tests pass.
- Feature, API, workflow and integration audit matrices pass.
- PHPStan reports no errors.
- API contract and handoff gates pass.

Live payment, tax and shipping provider certification is intentionally deferred.
Provider-dependent states remain represented in the API contract, but live
provider evidence is not part of this backend completion gate.

## Developer Commands

Run from `backend/`:

```text
composer generate:openapi
composer generate:sdk
composer check:api-contract
composer check:api-handoff
composer analyse
composer test:unit
composer test:api
composer test:workflow
```

For MySQL verification, set `TEST_DB_DRIVER=mysql`, `TEST_DB_HOST`,
`TEST_DB_PORT`, `TEST_DB_USERNAME`, `TEST_DB_PASSWORD` and a dedicated
`TEST_DB_DATABASE`. Never run the schema-reset test harness against a shared or
production database.

## Related Documents

- `docs/foundation/03_API_SPEC.md`: storefront/admin API behavior.
- `docs/foundation/02_DOMAIN_MODEL.md`: domain entities and ownership.
- `docs/engineering/30_TEST_STRATEGY.md`: test strategy and evidence.
- `docs/operations/12_RELEASE_GATES.md`: release gates.
- `docs/operations/PRODUCTION_OPERATIONS.md`: operations and incident handling.
- `docs/operations/PRODUCTION_PROOF_RUNBOOK.md`: production proof procedure.
- `docs/foundation/BE_V2_SPEC.md`: implementation architecture baseline.
