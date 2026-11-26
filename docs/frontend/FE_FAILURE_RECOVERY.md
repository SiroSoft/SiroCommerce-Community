# FE Failure and Recovery State Machines

Status: `cross-cutting FE workflow contract`

This document applies to storefront checkout/cart/orders, customer account and
privacy, returns/refunds/credit memos, fulfillment/inventory, B2B, CMS, and
reporting. Transport is the generated client only. The normalized error is
HTTP status plus `meta.error_code`; never branch on `message`.

## Universal request state machine

```text
idle -> loading/submitting -> succeeded
                         \-> validation_error
                         \-> unauthorized/forbidden
                         \-> not_found
                         \-> conflict
                         \-> throttled
                         \-> transient_failure -> retrying -> loading/submitting
                                                   \-> unknown_outcome -> reconcile
```

`unknown_outcome` is mandatory for a timed-out or disconnected mutation. The FE
must reconcile with an authoritative read before presenting a retry that could
repeat an external side effect.

## Recovery policy

| Condition | FE action | Retry rule |
|---|---|---|
| `400/422` | Show field/contract validation; preserve input | No automatic retry |
| `401` | Refresh/rotate token once, then redirect to login | Replay only if request is still safe and authorized |
| `403` | Hide/disable action and show capability message | No retry without changed authorization |
| `404` | Show missing/removed state without resource details | No blind retry |
| `409` | Re-read, show current version/state, preserve draft | Explicit user choice; same key only for same payload |
| `429` | Show throttled state and retry countdown | Honor `Retry-After`; bounded retry |
| `500`/network timeout | Correlation-id support panel | Reads may retry once; writes reconcile first |
| `304` | Keep cached report | No error state |
| Unknown error code/state | Safe generic recovery panel | No blind mutation retry |

Every request carries correlation id, and every OpenAPI-marked idempotent write
carries `Idempotency-Key`. A key is stable across response-loss retries and bound
to the same logical action/payload. Key reuse with a different payload is a
visible conflict. Cart tokens stay out of URLs, logs, and analytics.

## Domain state machines

### Checkout and order

```text
cart -> quoted -> placing -> order_created
                         \-> unknown_outcome -> read order/cart -> retry same key
order: pending -> awaiting_payment -> paid -> processing -> ready_to_ship
                                      -> failed/cancelled
ready_to_ship -> shipped -> delivered -> partially_refunded/refunded
```

The order states are from `docs/foundation/02_DOMAIN_MODEL.md`; the checkout display states
are FE states. Price, tax, stock, and totals come from the quote/order response.

### Return, refund, and credit memo

```text
return_submitted -> approved -> label/inspection -> refund_pending
                                           \-> rejected
refund_pending -> succeeded/partially_refunded/failed/unknown
unknown -> read authoritative order/refund/memo -> retry or support escalation
```

Return and provider states are not fully enumerated by OpenAPI. The FE must use
returned values and keep unknown states safe and non-destructive.

### B2B approval and CMS

```text
B2B draft -> submitted -> pending_approval -> approved/rejected/converted
CMS clean -> dirty -> saving -> saved -> publishing/restoring -> reconciled
```

These are UI state machines where the backend does not publish complete enums.
`409` is always a merge/re-read path, never last-write-wins.

### Export job

```text
idle -> queued -> processing -> completed
                  \-> failed -> retrying -> queued
completed -> expired
```

Poll only the documented export status endpoint, stop on terminal status, and
download only after completion. Do not expose storage paths or failure details.

## Scope, role, and polling controls

- Centralize store/channel/locale/currency selection; CMS and reporting must
  render scope evidence. A scope mismatch is not recoverable by client rewriting.
- Role/capability checks improve navigation only. Backend `401/403` wins.
- Use page/per_page, not offset pagination, and show stale/eventually consistent
  read indicators where the contract says so.
- Poll with bounded exponential backoff, abort on route change/logout, honor
  `Retry-After`, and do not poll endpoints that are not documented as status/read.
- Never reveal PII, payment credentials, provider signatures/references, tokens,
  raw evidence, or internal failure details in user-facing UI.

## Cross-workflow acceptance tests

1. Every screen has deliberate loading, empty, validation, unauthorized,
   forbidden, conflict, throttled, server-error, and unknown-state fixtures.
2. A lost response for checkout, return, refund, B2B conversion, CMS publish,
   or export retry reconciles before another side effect is attempted.
3. A changed idempotency payload returns a recoverable conflict and preserves the
   user's draft.
4. Token expiry refreshes once; repeated `401` cannot loop indefinitely.
5. Scope changes invalidate scoped caches and cannot display another scope's data.
6. Correlation IDs are retained for support without logging secrets or cart tokens.
7. Mobile and desktop keyboard/accessibility flows preserve drafts and recovery
   actions.

## Unresolved gaps

- No shared FE error-code registry, retry-after policy for all operations, or
  universal status endpoint is published.
- Several OpenAPI operations expose generic envelopes and optional idempotency,
  so exact terminal-state and replay guarantees require backend clarification.
- Provider webhook timing, payment gateway semantics, return labels, export
  expiration, and queue recovery are intentionally unspecified; this document
  does not invent live-provider behavior.
