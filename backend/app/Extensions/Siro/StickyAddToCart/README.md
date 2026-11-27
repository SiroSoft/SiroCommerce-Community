# siro/sticky-add-to-cart

Free module for a sticky Add to Cart bar on the product detail page.
Follows `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`; it does not modify core.

## What it does

- Renders a bar in the `pdp.below-atc` slot (directly below the main Add to
  Cart button on the PDP). The bar stays out of the way until the shopper
  scrolls past the main button, then pins to the bottom of the viewport
  (desktop only — see below).
- The bar shows the product name, the price (when `show_price` is on) and an
  Add to Cart button. The button is a plain form POST to `/cart/add`, exactly
  like the core PDP button, so cart creation and `POST /api/carts/{id}/lines`
  stay core-owned. The plugin adds the product's **default variant**
  (quantity 1); variant selection stays in the main Add to Cart flow.
- The bar never computes prices, totals, tax or shipping; it only displays
  the price the core product API returns.
- Mobile: the bar is hidden on viewports ≤ 700px because the core
  `.mobile-add` bar already provides a fixed bottom Add to Cart there; two
  fixed bars would overlap.

## Settings (one row per store/channel)

Stored in `ext_sticky_atc_settings` (`UNIQUE (store_id, channel_id)`),
migration `20261126000001_create_ext_sticky_atc_settings.php`.

| Key | Default | Meaning |
|---|---|---|
| `active` | `false` | New scopes start inactive — the bar renders nothing until enabled |
| `button_label` | `Add to Cart` | Button text, 2–40 characters |
| `show_price` | `true` | Show the product price in the bar |

Manifest `config` carries these defaults only (`siro/sticky-add-to-cart.<key>`
namespace). There is no ConfigurationRegistry seeding in the repo, so the
scoped table above is what makes admin settings persist.

## Routes

Mounted under `/api/ext/siro/sticky-add-to-cart/`:

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `admin/settings` | `sticky-atc:manage` | Read scoped settings + admin table |
| `GET` | `admin/stats` | `sticky-atc:manage` | Overview stats panel |
| `PUT` | `admin/settings` | `sticky-atc:manage` | Save scoped settings (requires `Idempotency-Key`) |
| `GET` | `public` | none, throttled | Active flag, button label, show_price |

`PUT admin/settings` accepts:

```json
{
  "button_label": "Add to Cart now",
  "show_price": true,
  "active": true
}
```

Saving is an upsert on the scope, so replaying the same request returns the
same canonical row. Validation rejects a label shorter than 2 or longer than
40 characters and a non-boolean `show_price`/`active` with HTTP 422.

`GET public` requires the full commerce scope (`X-Store-Id`, `X-Channel-Id`,
`X-Locale` and `X-Currency` headers or the matching query parameters) and
returns `{active, button_label, show_price}` (inactive bars return
`active: false` and an empty label).

Audit note: no audit-log middleware exists for extension routes in Wave 0
(the loader only maps `auth`/`cap:`/`throttle:` + idempotency), so settings
mutations are written to the error log with scope
(`[sticky-atc] settings updated scope=...`) until Platform adds an audit
contract.

## Storefront slot

The manifest declares the `pdp.below-atc` storefront slot. Register it
explicitly during app bootstrap; importing the plugin has no side effects:

```tsx
import { registerStickyAddToCart } from "@/plugins/sticky-add-to-cart/register";

registerStickyAddToCart();
```

The component fetches the public settings and the product
(`GET /api/products/{slug}`, public) with `cache: "no-store"` and renders
nothing until the settings are active, the product is purchasable
(`status` `active` or `backorder`), and the placeholder has scrolled out of
view. It renders nothing on empty/error responses.

UI is intentionally minimal (`TODO-UX-PRIMITIVE` in
`apps/storefront/src/plugins/sticky-add-to-cart/index.tsx`): when the
Experience lane ships the shared sticky-bar primitive, this markup is
replaced by it — no permanent primitive lives in this plugin.

## Uninstall behavior

- **Disable**: unmounts the extension routes, keeps all settings data.
- **Uninstall keep data**: registry row is disabled, `ext_sticky_atc_settings`
  stays intact and a reinstall reuses it.
- **Uninstall remove data**: migration `down()` drops
  `ext_sticky_atc_settings`; core tables are untouched.

No secret, license key or remote endpoint is required
(`license.required=false`).
