# API Reference Index

This file is the navigation index for the API contract. It is intentionally
short: endpoint schemas, parameters, examples, security requirements and error
responses are generated in OpenAPI and the TypeScript SDK.

## Source Of Truth

| Artifact | Purpose |
|---|---|
| `docs/openapi.json` | Generated backend contract |
| `public/openapi.json` | Published contract copy |
| `generated/sirocommerce.ts` | Generated TypeScript client |
| `docs/api-handoff/FE_HANDOFF.md` | Runtime usage rules |
| `docs/api-handoff/coverage-matrix.json` | Per-operation test evidence |

Current contract size: **405 OpenAPI paths / 468 exact method-path route records** (OpenAPI `info.version` 0.2.1, regenerated 2026-11-27).

## Main Domains

| Domain | Contract scope |
|---|---|
| Catalog | Products, variants, options, categories, brands, media, product types |
| Cart and Checkout | Guest cart token, quote, place order, checkout idempotency |
| Payment | Capture, void, refund, reconciliation, provider webhooks |
| Orders | Read, transitions, cancellation, customer ownership |
| Fulfillment | Allocation, shipments, tracking, returns, digital delivery |
| Customer | Account, addresses, preferences, loyalty, wishlist, reviews |
| Inventory | Stock, ATP, source allocation, transfers, adjustments |
| B2B | Companies, membership, catalogs, quotes, approvals, credit |
| CMS and SEO | Pages, revisions, menus, preview, metadata, headless reads |
| Reporting | Reports, analytics, search, BI exports and downloads |
| Privacy and Finance | Export, anonymization, legal hold, invoices, credit memos |
| Platform | Health, readiness, API version and provider operations |

## Required Client Rules

1. Read operation ids and schemas from OpenAPI; do not infer them from URLs.
2. Send `Accept: application/vnd.siro.v1+json` for v1 and the documented v2
   media type for v2 operations.
3. Send `Authorization` for authenticated operations and `X-Cart-Token` for
   guest-cart scoped operations.
4. Send an `Idempotency-Key` on every operation marked idempotent.
5. Treat `meta.error_code` as the machine-readable error contract.
6. Respect pagination maximums, date windows, rate-limit headers and
   `Retry-After`.
7. Never log access tokens, cart tokens, provider signatures, payment data or
   unnecessary customer PII.

## Verification

Run from `backend/`:

```text
composer test:unit
composer test:integration
composer test:api
composer test:workflow
composer check:api-contract
composer check:api-handoff
```

The current executable tests pass and the strict semantic matrix is
`READY`: every exact method/path has independently reviewable Unit,
Integration, API/Component, Contract and Workflow evidence.
