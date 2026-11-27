# Product Labels (`siro/product-labels`)

Free, public (Community), MIT. Store/channel scoped product label and badge
rules rendered on product cards (`product-card` slot) and just under the PDP
title (`pdp.title` slot).

## Rules

A rule has: `rule_key` (unique per store/channel, immutable),
`text` (1–80 plain-text chars, no `<`/`>`), `kind`
(`new` | `sale` | `best_seller` | `custom`), `conditions` (JSON object, see
below), `priority` (0–1000, lower renders first), `active`.

### How matching works

All conditions in a rule must pass (AND). A rule's `kind` is a styling class
that also names its canonical condition — the canonical condition is implied
when the rule does not spell it out:

| kind | Implied condition (unless overridden) |
|---|---|
| `new` | `is_new_days: 30` — created within 30 days |
| `sale` | `on_sale: true` — compare price above price |
| `best_seller` | `best_seller: true` — in the top 5 by units sold in the last 90 days (scope-filtered, via `DashboardBiReader::topProducts`) |
| `custom` | none — with empty conditions the rule matches every product in scope |

### Conditions reference

| Key | Type | Meaning |
|---|---|---|
| `is_new_days` | int 1–365 | product `created_at` within N days |
| `on_sale` | bool | `compare_at_price_minor > price_minor` |
| `discount_min_pct` | 0–100 | discount percent >= value |
| `category` | string \| list | category slug matches (any) |
| `tag` | string \| list | product has any of these tags |
| `product_ids` | list (max 100) | product id is listed (curated best sellers) |
| `price_min_minor` / `price_max_minor` | int >= 0 | price bounds, inclusive |
| `rating_min` | 0–5 | product rating >= value |
| `stock_max` | int >= 0 | product stock <= value (low stock) |
| `best_seller` | bool | product is in the best-seller set |

A missing product attribute fails the condition that needs it. At most
`max_labels_per_product` (default 3) labels render per product, in priority
order, then oldest rule first.

Product attributes are read through the core catalog contract
(`CatalogService::show`) resolved via the container. The extension never
reads core tables or instantiates core models directly, and never writes
outside its own `ext_*` tables.

## Config keys (manifest `config`)

| Key | Default | Meaning |
|---|---|---|
| `default_new_days` | 30 | implied `is_new_days` for `kind: new` |
| `max_labels_per_product` | 3 | render cap per product |
| `default_priority` | 100 | priority when a rule omits it |

No secrets. Nothing in config or rules is a credential.

## Routes (`/api/ext/siro/product-labels/`)

| Method | Path | Auth |
|---|---|---|
| GET | `admin/stats` | `cap:product-labels:manage` |
| GET | `admin/rules` | `cap:product-labels:manage` |
| POST | `admin/rules` | `cap:product-labels:manage` + Idempotency-Key |
| GET | `admin/rules/{id}` | `cap:product-labels:manage` |
| PUT | `admin/rules/{id}` | `cap:product-labels:manage` + Idempotency-Key |
| DELETE | `admin/rules/{id}` | `cap:product-labels:manage` + Idempotency-Key |
| GET | `public/labels?product_slug=…` | public, throttled |

POST replays with the same scoped Idempotency-Key return the canonical rule
instead of duplicating it. PUT replays are naturally idempotent (same payload
→ same state). DELETE returns `{"deleted": true|false}` and is safe to replay.
Unknown product slugs return `labels: []` (200) so the slot renders nothing.

## Audit

Every create/update/delete writes one append-only row to
`ext_product_label_audit` (`actor` = admin email, `action`, `rule_id`,
`rule_key`, `payload_json`). The loader adds auth/cap/throttle/idempotency
but no audit middleware, so the plugin keeps its own scoped audit table
rather than writing core tables.

## Install / uninstall

- Install → enable runs both migrations (`ext_product_label_rules`,
  `ext_product_label_audit`). Disable keeps all data; re-enable resumes.
- Uninstall (keep data) keeps both tables; reinstall reuses the rules.
- Uninstall (remove data) drops both `ext_*` tables; core tables are
  untouched.

## Storefront

`apps/storefront/src/plugins/product-labels/` — `register.ts` registers the
same component family on `product-card` (badge, top-right of the card image)
and `pdp.title` (inline badges under the title). The badge is a temporary
minimal component marked `TODO-UX-PRIMITIVE` until the shared Experience
primitives land; it renders nothing until the public endpoint answers, so it
never shifts layout on listing cards.
