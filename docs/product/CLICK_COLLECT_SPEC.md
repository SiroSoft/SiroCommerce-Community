# Click & Collect — Spec (P1.3)

Buy online, pick up in store. Same rails serve F&B pickup, pharmacy counters,
B2B will-call.

## Model

- `pickup_points`: `code` (unique, doubles as display id), `name`,
  `source_id` (inventory source holding the counter stock, default
  `'default'`), address fields, `sla_hours` (default 24), `state`
  (`active|disabled`). Admin CRUD; public list shows active only.
- `orders.pickup_point_code` (nullable). Presence = pickup order; shipping is
  always 0 for pickup. No separate method column (one source of truth).
- `fulfillment_orders`: `pickup_point_code`, `ready_at`, `ready_by`,
  `picked_up_at` (all nullable; delivery fulfillments leave them null).

## Quote (`GET /api/carts/{id}/quote?fulfillment_method=pickup&pickup_point=HN-01`)

- `fulfillment_method=pickup` requires `pickup_point`; a point without the
  method → 422 (fail-closed, no silent ignore).
- Point must exist + `active`, else 422. Every cart line must be fully
  available at the point's `source_id`, else 422 with per-line shortfalls.
- Shipping forced to 0; response adds
  `pickup: {code, name, address, sla_hours, ready_by}` where
  `ready_by = now + sla_hours`. Method is place-time truth (see below).

## Place (`POST /api/checkout/place`)

- Accepts `fulfillment_method=pickup` + `pickup_point_code`. Same validations
  re-run (point could be disabled between quote and place). Shipping forced
  to 0 on both the snapshot and fresh-totals paths.
- Order row stores `pickup_point_code`. Checkout inventory reservation targets
  the point's `source_id` instead of `'default'`.
- Delivery orders are untouched (no point, default pool, engine shipping).

## Handover (staff, idempotent, keyed)

Fulfillment: `allocated → ready_for_pickup → picked_up` (`cancelled` from
either, same rules as today). New endpoints mirroring ship/deliver style:

- `POST /api/admin/fulfillment/{id}/ready` — staff staged goods at the
  counter. Stores point code (from the order), `ready_at`, `ready_by`.
  Replay returns the row.
- `POST /api/admin/fulfillment/{id}/collect` — customer handed the goods.
  Sets `picked_up_at`. Replay returns the row. Requires `ready_for_pickup`.
- Events `fulfillment.ready_for_pickup` / `fulfillment.picked_up` feed the
  existing notification rails (new `pickup_ready` template; collect reuses
  the delivered copy family).

Order states gain `ready_to_ship → ready_for_pickup → picked_up`
(terminal, fires `OrderDelivered` like `delivered`). Delivery paths unchanged.
`picked_up` is NOT reachable from `shipped` and vice versa — a fulfillment is
either shipped or picked up, never both.

## Storefront / admin

- Storefront: `pickup` shipping option + point selector (from the public
  list) + ready-by/SLA display; method + point flow quote → checkout → place.
- Admin: fulfillment workspace gains Ready / Complete pickup buttons;
  order detail shows the pickup point + ready-by; new order states get
  transition labels; points managed on a small admin page.

## Limits (v1)

- No per-point opening hours / holiday calendar (SLA is hours, wall-clock).
- No partial pickup across points (one point per order).
- No customer "I'm here" ping; no QR handover code (staff verifies by order
  number at the counter).
