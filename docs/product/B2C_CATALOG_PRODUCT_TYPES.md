# B2C Catalog Product Types

Status: implemented and live. Scope: default/web store, `en` catalog,
`USD` + `VND` priced scopes (30 products per scope).

## Supported types

| Type | Demo product | Data | Storefront behavior |
|---|---|---|---|
| `simple` | most products | one variant, price, inventory | standard purchase flow |
| `configurable` | Linen Mandarin-Collar Shirt | Size (S/M/L, L surcharge) x Color (Natural/Charcoal) = 6 variants with own SKU, price, stock | option selects with surcharge labels; price, SKU, and stock follow the resolved variant; the submitted line is the variant id |
| `bundle` | Morning Ritual Gift Set | `product_components` rows (qty, required) resolved to names, prices, images | "In this bundle" summary; one add buys the set at the bundle price |
| `grouped` | Linen Starter Set | `product_components` rows resolved to children with price, image, stock, backorder flag | "Choose each piece"; each child has its own add form; backorderable children stay enabled at zero stock |
| `downloadable` | Home Brew Guide eBook | `digital_assets` row (access key, download limit) | `delivery.mode = downloadable`; standard purchase flow |
| `virtual` | Gift Wrapping Service | no physical fulfillment | `delivery.mode = virtual`; standard purchase flow |
| backorder | Packable Rope-Sun Hat | `stock = 0`, `allow_backorder = 1` | "Available to reserve", Backorder CTA |
| out_of_stock | Natural Latex Mattress | `status = out_of_stock` | "Currently unavailable", disabled CTA, waitlist note |

`booking` is intentionally unsupported (`productType: "unsupported"`,
`capability: "unavailable"`): slots, capacity, and date-aware pricing are a
separate domain, not a flag.

## API contract (product detail)

`GET /api/products/{slug}` publishes, in addition to the base fields:

- `options`: `[{name, values: [labels]}]`
- `variant_options`: `[{variant_id, sku, options: {Size, Color}, price_minor,
  compare_price_minor, currency, stock}]`
- `quantity_pricing`: `[{minimum, price, currency}]` flattened across variants
- `option_pricing`: `[{option, value, surcharge, currency}]` (positive only)
- `bundle_components`: `[{id, variant_id, name, slug, quantity, required,
  price_minor, currency, image}]`
- `grouped_items`: `[{product_id, variant_id, name, slug, price_minor,
  currency, image, stock, backorderable}]`
- `delivery`: `{mode: physical|downloadable|virtual, state: available}`

Parents advertise the cheapest variant as the entry price. All set reads are
batched (no N+1; see `CatalogCompletenessTest`).

## Seeder rules (`B2cCatalogSeeder`)

- The catalog is fully derived from `catalog()`: the seeder wipes its own
  scope rows first, then inserts. A slug that moves between products can never
  orphan or duplicate a row.
- Variant, inventory, price-list, gallery ids stay within `VARCHAR(26)`.
- Type changes are explicit per product (`type`, `status`, `stock`,
  `backorder`, `sizes`/`colors`, `tiers`, `bundle`, `grouped`, `digital`).

## Acceptance

- `B2cCatalogSeederTest`: 60 products, 70 variants, 180 media, 6 linen
  variants, 10 components, 2 digital assets, backorder/out_of_stock states.
- `CatalogCompletenessTest`: detail stays within the query budget with zero
  N+1 reads.
- Live: each type renders its PDP contract and a real purchase reaches the
  bag with the correct variant price.
