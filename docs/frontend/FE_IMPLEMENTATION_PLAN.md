# SiroCommerce Frontend Implementation Plan

Status: `READY FOR FE IMPLEMENTATION`

This plan defines the frontend delivery order. The generated OpenAPI document is
the source of truth for exact paths, operation ids, request schemas, response
schemas, auth requirements, pagination and error codes.

## Source Of Truth

- `backend/docs/openapi.json`: generated API contract.
- `backend/generated/sirocommerce.ts`: generated TypeScript client.
- `backend/docs/api-handoff/FE_HANDOFF.md`: runtime rules and headers.
- `backend/docs/api-handoff/coverage-matrix.json`: route evidence.
- `docs/contracts/COMMERCE_SCOPE.md`: store/channel/locale/currency scope rules.
- `docs/contracts/CMS_REVISION_WORKFLOW.md`: CMS publish and restore behavior.
- `docs/contracts/BI_EXPORT_DATA_CONTRACT.md`: export job and download behavior.
- `docs/frontend/FE_I18N_FINANCE.md`: locale, currency, timezone and finance display gates.
- `docs/frontend/FE_ACCESSIBILITY_ACCEPTANCE.md`: WCAG 2.2 AA acceptance.
- `docs/frontend/FE_PERFORMANCE_BUDGETS.md`: browser budgets and Core Web Vitals.
- `docs/frontend/FE_ROLLOUT_FEATURE_FLAGS.md`: rollout, flag and rollback controls.
- `docs/frontend/FE_RELEASE_EVIDENCE.md`: release evidence and operation traceability.

Do not hand-write a second route list or infer response shapes from URLs.

## Delivery Phases

### Phase 1: Foundation

- Configure `API_BASE_URL` per environment.
- Add the generated TypeScript client as the only transport layer.
- Implement request headers: `Accept`, `Content-Type`, correlation id,
  authorization, FE guard where required, commerce scope, cart token and
  idempotency key.
- Implement one normalized API error type using HTTP status plus
  `meta.error_code`; never branch on `message`.
- Add token storage, refresh rotation, logout and unauthorized redirect.
- Add query cache and mutation invalidation policy.

Acceptance: an unauthenticated request, token refresh, a 401, a 422 and a 429
are handled without page-specific transport code.

### Phase 2: Storefront Core

- Catalog: products, product detail, categories, search and localization.
- Cart: create cart, add/update/remove lines, guest cart token persistence.
- Checkout: quote, shipping selection, place order and idempotent retry.
- Order result: order detail, guest order access and customer order history.

Acceptance: a guest can browse, create a cart, quote, place an order and safely
retry the same mutation without creating a duplicate order.

### Phase 3: Customer Account

- Register, login, refresh and logout.
- Profile, addresses, preferences, wishlist, reviews and loyalty.
- Subscriptions, consents, privacy export and anonymization status.
- Customer notifications and engagement history.

Acceptance: customer-owned resources cannot be read or mutated with another
customer id; UI uses token identity only.

### Phase 4: Commerce Operations

- Admin dashboard and role-aware navigation.
- Product, variant, option, media and catalog import management.
- Inventory sources, ATP, reservations, transfers and adjustments.
- Orders, fulfillment, shipments, returns, refunds and credit memos.
- Promotions, coupons and B2B companies/catalogs/quotes.

Acceptance: unavailable capabilities are hidden or disabled, and direct route
access renders the documented 401/403 error state.

### Phase 5: CMS And Growth

- Pages, blocks, menus, SEO metadata and revisions.
- Preview, publish and restore flows with idempotent mutations.
- Analytics events, search, reports, dashboards and BI export jobs.
- Export progress, retry, download and expired-job states.

Acceptance: CMS and reporting data are always scoped by store/channel/locale/
currency; cross-store identifiers produce the stable scope error.

### Phase 6: Platform UX

- Version and feature capability discovery.
- Public liveness and authenticated detailed readiness.
- Operational health views with redacted provider evidence.
- Correlation-id display for support/debug workflows.

Live provider certification is excluded from FE completion for this phase, but
provider-dependent states must still render loading, pending, failed and retry
states from the documented response contracts.

## Role And Route Matrix

| FE area | Roles | Main API groups |
|---|---|---|
| Storefront | Guest, customer | Catalog, cart, quote, checkout, orders |
| Account | Customer | Auth, account, wishlist, loyalty, privacy |
| Commerce admin | Owner, admin | Catalog, orders, inventory, promotions |
| Fulfillment | Warehouse | Inventory, ATP, shipments, returns |
| Growth | Marketer | CMS, promotions, reviews, analytics |
| Reporting | Analytics, owner, admin | Reports, search, BI exports |
| Support | Support, owner, admin | Customer engagement, orders, returns |
| Platform | Developer, owner, admin | Health, readiness, version, operations |

The UI role matrix is advisory only. The backend remains authoritative for
authorization and scope enforcement.

## Client Rules

- Use `page` and `per_page`; never use offset pagination.
- Send `Idempotency-Key` for every OpenAPI-marked idempotent write.
- Reusing a key with a different payload is a user-visible recoverable conflict.
- Keep cart tokens out of URLs, logs and analytics payloads.
- Do not submit customer ids for customer-owned operations.
- Treat search and cached reads as eventually consistent.
- Honor `Retry-After` on 429 responses.
- Preserve and display `X-Correlation-ID` for support diagnostics.
- Do not expose provider signatures, payment data or unnecessary PII.

## FE Definition Of Done

- Every screen uses an operation id from the generated client.
- Every loading, empty, validation, unauthorized, forbidden, conflict,
  throttled and server-error state has a deliberate UI.
- Store/channel/locale/currency scope is selected centrally, not per component.
- Mutations have idempotency behavior where required.
- Contract fixtures cover success and stable error codes.
- Mobile and desktop layouts are verified for all Phase 2-4 journeys.
- No direct `fetch`/Axios calls bypass the generated client.
- FE build passes typecheck, lint, unit tests and route smoke tests.

## Recommended Work Split

1. Foundation and generated client integration.
2. Storefront core and checkout.
3. Customer account and privacy.
4. Admin catalog, orders and inventory.
5. Fulfillment, returns and B2B.
6. CMS, analytics, reporting and exports.
7. Cross-cutting QA, accessibility, responsive review and release smoke tests.

## Non-functional release references

The cross-cutting exit for Phase 6 and every later release requires the five FE
non-functional documents above. Their acceptance IDs and evidence naming rules
are normative; `docs/engineering/30_TEST_STRATEGY.md` remains the test-suite gate and
`docs/operations/12_RELEASE_GATES.md` remains the overall release gate.
