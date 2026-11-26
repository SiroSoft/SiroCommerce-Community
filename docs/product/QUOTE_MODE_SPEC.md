# Quote Mode Spec (per store/channel)

Some stores need an explicit quote step (B2B, high shipping/tax complexity);
most B2C stores do not. Make it configuration, not a fixed flow.

## Config

Storefront configuration gains `purchase.quote_mode`:
- `required` (default, current behaviour): cart → quote → checkout.
- `auto`: cart → checkout; the server quote still runs automatically so totals
  stay server-confirmed, but the separate quote step/page is skipped.
- `off`: same flow as `auto` **and** hide quote entry points (footer/header
  “Shipping quote” links, `/quote` page redirects to `/checkout`).

Scope: store/channel/locale/currency (normal storefront config scope).
Validation: unknown value → 422. Defaults and existing configs read as
`required` (backward compatible). Vertical presets set sensible defaults
(B2B = required; most B2C = auto).

## Backend

- `StorefrontConfigDefaults` includes `purchase.quote_mode = required`.
- Validator accepts only the three values; store adapter persists; API returns
  it inside `purchase`.
- Admin theme builder “Purchase” card gains a select (Required quote step /
  Auto quote / No quote step) bound to `purchase.quote_mode`; save/preview/publish
  unchanged.

## Storefront

- `PurchaseFlow` receives `quoteMode` from config; `auto|off` skip the quote
  step but still call quote before place (never trust client totals).
- `/quote` page: `required` renders as today; `auto|off` redirects to
  `/checkout`.
- Footer “Shipping quote” link renders only when `required`.
- Checkout still shows the full server breakdown (subtotal, discounts, shipping,
  tax, total) regardless of mode.

## Tests

Config default/validation (invalid 422)/persistence/API shape; admin select
saves value; storefront: required shows quote step, auto goes to checkout and
still quotes, off hides footer link and /quote redirects; totals/breakdown
unchanged; presets carry expected defaults.
