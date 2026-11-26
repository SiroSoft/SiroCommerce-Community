# Promotion Rules Spec (free tier)

Bagisto/Aimeos win on cart/catalog price rules. This adds a small, auditable
rule engine with the same “backend is truth” contract as coupons.

## Data

`promotion_rules`: `id`, `name`, `kind` (`cart_percent|cart_fixed|catalog_percent`),
`value` (percent 1..90 or minor amount), `min_subtotal_minor` nullable,
`category_slugs_json` nullable, `starts_at`/`ends_at` nullable,
`max_uses` nullable, `uses` default 0, `state` (`active|disabled`),
timestamps.

## Calculation

- Evaluated in quote/place after coupons and member pricing, before gift
  tender (never changes shipping/tax).
- `cart_percent` / `cart_fixed`: apply to the discounted merchandise subtotal.
- `catalog_percent`: apply only to lines whose category matches
  `category_slugs_json` (empty = all) and record per-line evidence.
- **Best single rule wins** (largest discount); rules never stack.
- Cap discount at the remaining subtotal; never below zero.
- Window and `max_uses` enforced server-side; usage increments once per placed
  order inside the same transaction as the order.
- Response evidence: `promotion_rule: { id, name, kind, discount }` in the
  quote breakdown and the order totals snapshot.

## API

Admin (admin/owner/marketer): `GET/POST /api/admin/promotion-rules`,
`PUT/DELETE /api/admin/promotion-rules/{id}`. Validation: percent 1..90,
fixed 1..1e9 minor, window start < end, category slugs validated against
published categories.

Public: no new endpoint; the quote response carries the rule evidence.

## Admin UI

Coupons page gains a “Price rules” tab: table + create/edit dialog (kind,
value, min subtotal, categories multi-select, window, max uses, state), with
ConfirmDialog for delete.

## Tests

Best-rule selection, no stacking, window boundaries, category scoping,
`max_uses` exhaustion, cap at subtotal, quote/place parity, idempotent replay
does not double-increment usage, disabled rules ignored.

## Non-goals

No coupon stacking (unchanged), no BOGO, no per-customer limits (Pro), no
automatic campaigns.
