# Frontend API Handoff

Status: `FE_IMPLEMENTATION_GO; FE_RELEASE_NOT_READY`

The generated OpenAPI and SDK are the source of truth for endpoint shapes. The
backend contract is implementation-ready because `check-api-handoff.php`
reports zero backend semantic blockers in `coverage-matrix.json`. This is not a
claim that the frontend application has passed browser, accessibility,
performance or production release evidence. Track implementation discoveries
in `../../docs/frontend/FE_CONTRACT_GAPS.md` and use `../../docs/frontend/FE_IMPLEMENTATION_PLAN.md` for the
vertical-slice order.

## Environments and headers

Use `API_BASE_URL` per environment. Keep environment selection outside compiled
client code:

| Environment | API base URL policy | Data policy |
|---|---|---|
| Development | local HTTP backend or explicit dev URL | deterministic test fixtures; no production data |
| Staging | HTTPS staging URL | isolated staging tenant and credentials |
| Production | HTTPS production URL | production tenant; least-privilege credentials |

Development defaults to the local backend; staging and production must be explicit.

- `Accept: application/vnd.siro.v1+json`
- `Content-Type: application/json` for JSON writes
- `Authorization: Bearer <access-token>` on authenticated routes
- `X-Siro-FE: <shared-secret>` where the admin FE guard is enabled
- `X-Correlation-Id: <client-generated-id>` when tracing a user action
- `Idempotency-Key: <unique-key>` for checkout place, payment mutations, and
  other operations marked idempotent in OpenAPI
- `X-Cart-Token: <cart-access-token>` for cart, quote, and checkout operations
  that are scoped to a guest cart

## Envelope and errors

Success is `{success:true,message,data,meta}`. Paginated reads return
`meta.page`, `meta.per_page`, `meta.total`, and `meta.last_page`. Errors are
`{success:false,message,meta}`; `meta.error_code` is stable for client logic and
`meta.errors` contains field messages when relevant.

Use status classes as follows: `400` malformed request, `401` unauthenticated,
`403` forbidden/capability, `404` missing resource, `409` state or optimistic
conflict, `422` validation, `429` throttled, `500` unexpected server failure.
Do not branch on human-readable `message`.

## Examples

```http
GET /api/products?page=1&per_page=20&locale=en
Accept: application/vnd.siro.v1+json
```

```http
POST /api/checkout/place
Accept: application/vnd.siro.v1+json
Content-Type: application/json
Idempotency-Key: checkout-cart-123-attempt-1

{"cart_id":"cart_test_0001","payment_method":"cod"}
```

Repeat the same idempotency key and equivalent request to receive the original
result. Never reuse a key for a different mutation payload. On `409`, surface a
recoverable conflict and refetch the resource; on `429`, honor `Retry-After`.

Guest cart tokens are opaque, scoped to one cart, and must not be logged or
placed in URLs. Authenticated customer identity is derived from the access
token; clients must not submit an arbitrary customer id.

## Pagination, webhooks and GraphQL

Use `page` and `per_page` (maximum 100). Webhooks are server-to-server: retain
the raw body for HMAC verification, send the provider signature, and treat the
provider event identifier as replay-idempotent. Return quickly; processing may
be queued. GraphQL is POST body-only and has documented query size, depth and
complexity limits.

## Compatibility and changelog

`/api` is contract v1 and receives additive changes only. `/api/v2` operations
are explicitly marked in OpenAPI. Generate the SDK from OpenAPI; do not hand-edit
generated files. A breaking change requires a new API version, deprecation
metadata, migration notes, and contract tests before release.

## Generated artifacts

- OpenAPI source: `backend/docs/openapi.json`
- Public OpenAPI: `backend/public/openapi.json`
- TypeScript SDK: `backend/generated/sirocommerce.ts`
- Machine-readable route matrix: `backend/docs/api-handoff/coverage-matrix.json`
- Latest handoff result: `backend/docs/api-handoff/latest-result.json`

Regenerate and verify from `backend/`:

```text
composer generate:openapi
composer generate:sdk
composer check:api-contract
composer check:api-handoff
```

## API surface groups

The generated contract currently contains 172 OpenAPI paths and 186 exact
method/path route records across
catalog, cart/checkout, payment/webhook, orders, fulfillment/returns, customer,
inventory/ATP, B2B, CMS/SEO/headless, reporting/analytics, BI export, privacy,
health and provider operations. Use OpenAPI tags and operation ids rather than
hard-coding a manually maintained route list in the FE.

Changelog: 2026-09-21 added exact route/layer coverage evidence, deterministic
fixtures, envelope assertions, cart-token security, OpenAPI/SDK parity gates,
and complete five-layer semantic evidence. Current handoff is `READY` with
186 route records and 930/930 Unit, Integration, API/Component, Contract and
Workflow evidence records passing. See `../../docs/frontend/FE_IMPLEMENTATION_PLAN.md` for
the frontend delivery plan.
