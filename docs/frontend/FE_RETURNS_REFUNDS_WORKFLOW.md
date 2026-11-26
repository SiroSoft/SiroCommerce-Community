# FE Returns, Refunds, Fulfillment, Inventory, Orders, and Privacy Workflow

Status: `FE workflow contract`

Source of truth: `backend/docs/openapi.json`, `docs/foundation/02_DOMAIN_MODEL.md`,
`docs/foundation/03_API_SPEC.md`, `docs/frontend/FE_IMPLEMENTATION_PLAN.md`. The FE must use the
generated client and the operationIds below. It must not infer state transitions
from URL names or success messages.

## Scope and screens

| Screen | Audience | Primary behavior |
|---|---|---|
| Cart and checkout | Guest, customer | Read/update cart, quote, place order, show stale-price/stock conflicts |
| Order result/detail | Guest with cart/order access, customer, support | Show immutable totals and documented order/return information |
| Account orders/returns | Customer | Paginated orders and returns; customer identity comes from the token |
| Return queue/detail | Support, warehouse, owner/admin | Create, approve, label, inspect, approve refund, reject, complete |
| Fulfillment board/detail | Warehouse, support, owner/admin | Allocate, ship, deliver, cancel, track |
| Inventory detail | Warehouse, owner/admin | ATP/read, adjust, priorities, transfer |
| Refund/credit memo detail | Finance, support, owner/admin | Show refund result, invoice and credit memo evidence without provider secrets |
| Privacy center | Customer; legal/admin for legal hold | Export status/read and anonymization confirmation |

The role list is a navigation policy, not an authorization guarantee. The API
remains authoritative; a direct route must render `401`, `403`, `404`, `409`,
`422`, `429`, and `500` states without leaking resource existence.

## Route and operation mapping

Mapped operation count: **33**.

| FE action | Method and route | operationId | Write contract |
|---|---|---|---|
| Customer order list/detail | `GET /api/account/orders`, `GET /api/account/orders/{id}` | `get_api_account_orders`, `get_api_account_orders_id` | Safe reads; page/per_page |
| Guest/customer order detail | `GET /api/orders/{id}` | `get_api_orders_id` | Safe read; access rules are backend-owned |
| Customer return list/detail | `GET /api/account/returns`, `GET /api/account/returns/{id}` | `get_api_account_returns`, `get_api_account_returns_id` | Safe reads; page/per_page |
| Order return detail | `GET /api/orders/{id}/returns` | `get_api_orders_id_returns` | Safe read |
| Create return | `POST /api/admin/returns` | `post_api_admin_returns` | `Idempotency-Key` required |
| Return transitions | `POST /api/admin/returns/{id}/approve`, `/label`, `/inspect`, `/approve-refund`, `/reject`, `/complete` | `post_api_admin_returns_id_approve`, `post_api_admin_returns_id_label`, `post_api_admin_returns_id_inspect`, `post_api_admin_returns_id_approve_refund`, `post_api_admin_returns_id_reject`, `post_api_admin_returns_id_complete` | `Idempotency-Key` required for each |
| Order refund | `POST /api/admin/orders/{id}/refund` | `post_api_admin_orders_id_refund` | `Idempotency-Key` required by OpenAPI |
| Payment refund | `POST /api/v2/payments/{id}/refund` | `post_api_v2_payments_id_refund` | `Idempotency-Key` required by OpenAPI |
| Credit memo | `GET /api/admin/credit-memos/{id}`, `POST /api/admin/invoices/{id}/credit-memos` | `get_api_admin_credit_memos_id`, `post_api_admin_invoices_id_credit_memos` | Mutation is idempotency-controlled where marked |
| Fulfillment | `POST /api/admin/fulfillment/allocate`, `POST /api/admin/fulfillment/{id}/ship`, `/deliver`, `/cancel`, `GET /api/admin/fulfillment/{id}/track` | `post_api_admin_fulfillment_allocate`, `post_api_admin_fulfillment_id_ship`, `post_api_admin_fulfillment_id_deliver`, `post_api_admin_fulfillment_id_cancel`, `get_api_admin_fulfillment_id_track` | Writes require `Idempotency-Key`; track is a read |
| Inventory and ATP | `GET /api/admin/inventory/{variant}`, `PUT /api/admin/inventory/{variant}`, `PUT /api/admin/inventory/{variant}/priorities`, `POST /api/admin/inventory/{variant}/transfer`, `POST /api/v2/inventory/{variant}/atp` | `get_api_admin_inventory_variant`, `put_api_admin_inventory_variant`, `put_api_admin_inventory_variant_priorities`, `post_api_admin_inventory_variant_transfer`, `post_api_v2_inventory_variant_atp` | Follow each operation's OpenAPI idempotency flag |
| Cart, quote, checkout | `POST /api/carts`, `POST /api/carts/{id}/lines`, `GET /api/carts/{id}/quote`, `POST /api/checkout/place` | `post_api_carts`, `post_api_carts_id_lines`, `get_api_carts_id_quote`, `post_api_checkout_place` | Checkout place is idempotent; preserve cart token outside URLs/logs |
| Privacy | `GET /api/account/privacy/export`, `POST /api/account/privacy/anonymize` | `get_api_account_privacy_export`, `post_api_account_privacy_anonymize` | Anonymize requires `Idempotency-Key`; export is a read/status contract |

## State model and transitions

The documented order machine is `pending -> awaiting_payment -> paid ->
processing -> ready_to_ship -> shipped -> delivered`, with `failed`,
`cancelled`, `refunded`, and `partially_refunded` branches. The FE renders the
state returned by the API and only enables a named action when the returned
state and capability permit it. It never writes `state` directly.

Return workflow UI states are `loading`, `empty`, `submitted`, `approved`,
`label_pending`, `inspection_pending`, `refund_pending`, `rejected`,
`completed`, `conflict`, and `failed`. These are presentation states; the
OpenAPI currently does not publish a return-state enum. Unknown backend states
must render an explicit `unsupported state` recovery panel, not default to
completed.

Refund UI states are `not_requested`, `pending`, `succeeded`, `partially_refunded`,
`failed`, and `unknown`. `unknown` is mandatory after a timeout or lost response:
the FE must re-read the order/refund/credit-memo resource before offering retry.
No live gateway/provider behavior is assumed.

## Scope, privacy, and concurrency

- Customer screens send no customer id for customer-owned operations.
- Admin and operational screens use the central commerce scope when the endpoint
  requires it; scope mismatch is a blocking `SCOPE_ERROR`/`409` state.
- Display money from integer minor units or the API Money representation; never
  recalculate order totals from catalog prices.
- On `409`, retain the user's form, refresh the resource, show the conflicting
  version/state, and require an explicit confirmation before resubmission.
- Never display provider references, signatures, payment credentials, tokens, or
  raw evidence. Correlation IDs may be shown to support staff.

## Retry and polling policy

- Safe reads may retry once on transient network failure, with backoff and an
  abort signal. Do not retry validation, authorization, scope, or not-found errors.
- Retry a mutation only with the same idempotency key and equivalent payload.
  A key reused with a different payload is a user-visible conflict.
- After a mutation timeout, use the relevant read operation to reconcile before
  retrying. Never generate a new key merely because the response was lost.
- Poll only a documented status/read resource. The current contracts do not
  define a universal return, refund, or fulfillment polling interval; product
  code must use bounded backoff and stop on terminal state, cancellation, or
  server throttling. Honor `Retry-After` on `429`.

## Acceptance tests

1. A guest can create a cart, quote it, place it once, lose the response, and
   reconcile/retry without a duplicate order.
2. A customer cannot read another customer's order or return by changing a URL id.
3. Every return transition sends a unique key, replays safely, and preserves the
   original result; a changed payload/key collision renders `409`.
4. A refund timeout never shows success until a subsequent read proves the result.
5. A warehouse user without the backend capability sees `403` and no mutation.
6. Concurrent inventory change or stale fulfillment action renders a conflict and
   does not overwrite the newer state.
7. Privacy export/anonymization screens do not render hidden PII or provider data.
8. Mobile and desktop screens cover loading, empty, validation, unauthorized,
   forbidden, conflict, throttled, server-error, and unknown-state variants.

## Unresolved gaps

- OpenAPI does not publish return, fulfillment, refund, credit-memo, or inventory
  state enums and does not consistently publish capability names.
- The account privacy export contract does not specify whether it is immediate,
  queued, or how a downloadable result is obtained.
- Return eligibility, refund amount rules, label provider behavior, and warehouse
  inspection evidence are not defined; the FE must not invent them.
- Exact read endpoints for some admin return/refund/fulfillment queues are absent;
  list screens require a backend contract before implementation.
