# Siro Theme Package Spec (`siro.theme/1`)

Status: P1 + P2 zip export implemented (JSON/zip import, JSON/zip export, admin UI). Companion to
[`EXTENSION_ECOSYSTEM_SPEC.md`](./EXTENSION_ECOSYSTEM_SPEC.md); themes are
presentation packages, never executable code.

## 1. Goal

A theme made for SiroCommerce (not Bagisto) can be packaged once, imported as a
draft, previewed, published per store/channel, and exported again. Import must not
carry another store's scope, secrets or executable content.

Non-goals: running Bagisto Blade themes, arbitrary JavaScript/HTML injection,
marketplace billing (P2).

## 2. Package format

JSON file `.siro-theme.json` (P1). A `.zip` with local assets is P2; P1 references
uploaded/CDN asset URLs.

```json
{
  "schema": "siro.theme/1",
  "id": "acme/editorial-pharmacy",
  "key": "editorial-pharmacy",
  "name": "Editorial Pharmacy",
  "version": "1.0.0",
  "description": "Warm paper, serif display, trust-first pharmacy layout.",
  "preview_url": "https://cdn.example.com/themes/editorial-pharmacy.png",
  "extends": "general",
  "vertical": "pharmacy",
  "tokens": {
    "colors": { "ink": "#1E2420", "muted": "#536057", "paper": "#F5F5F0", "surface": "#FFFEFA", "accent": "#883C29", "accent_dark": "#693021" },
    "typography": { "display": "editorial", "body": "system", "density": "comfortable", "base_size": 16 },
    "shape": { "radius": "soft", "card": "bordered" }
  },
  "brand": { "name": "Northfield Pharmacy", "wordmark": "northfield", "logo_url": "/brand/northfield.svg" },
  "navigation": { "menu_key": "primary", "items": [{ "label": "Shop", "href": "/category/shop" }] },
  "header": { "announcement": true, "layout": "centered", "sticky": true },
  "footer": { "layout": "minimal", "show_help": true, "show_policies": true, "columns": [] },
  "homepage": {
    "sections": [
      { "id": "campaign", "type": "campaign_hero", "enabled": true, "order": 10, "props": {} },
      { "id": "faq", "type": "faq", "enabled": true, "order": 20, "props": { "items": [{ "question": "Delivery?", "answer": "2-5 days." }] } }
    ]
  },
  "presentation": { "product_card_variant": "editorial", "product_detail_variant": "focused", "product_list_variant": "list-editorial", "purchase_variant": "standard" },
  "purchase": { "quote_mode": "auto" },
  "policies": { "shipping_url": "/shipping", "returns_url": "/returns", "support_url": "/contact" },
  "experiments": []
}
```

Rules:
- `schema` must be `siro.theme/1`.
- `id` is `vendor/theme-key`; `key` (optional) defaults to the part after `/` and
  must match the storefront theme-key rule (`^[a-z][a-z0-9_-]{2,39}$`).
- `name`, semver `version`, and at least one homepage section are required.
- `extends` defaults to `general`; only a valid theme key or registered parent.
- The package **must not** contain `scope`, `version` (config version), secrets or
  HTML/JS. Scope is taken from the importing store/channel, not the file.
- `preview_url` and asset URLs must be `https://` or root-relative paths.
- Tokens/brand/navigation/header/footer/homepage/presentation/purchase/policies/
  experiments are validated by the same `StorefrontConfigValidator` as the admin
  builder; invalid values are rejected with 422.
- Import is a **draft**: it never publishes or auto-saves over the current draft
  until the operator saves/publishes (optimistic `expected_version` applies).

## 3. Import flow

Admin → Storefront → Theme → **Import Siro theme** → choose `.json`:
1. Client checks JSON shape (schema/name/version/sections).
2. `POST /api/admin/storefront/config/import-theme` with
   `{ theme: <package>, expected_version? }` and `Idempotency-Key`.
3. Server converts the package into storefront config input, drops scope,
   validates, and saves a **draft**.
4. Admin previews, edits, then Save/Publish. Revision history records the change.

CLI/admin API use the same domain converter; no duplicated merge rules.

## 4. Export flow

`GET /api/admin/storefront/config/export-theme` returns a `siro.theme/1` package
built from the current draft/published config:
- id `siro/<theme.key>`; name from brand; version `1.0.0` (operator can rename);
- all design/content fields, no store scope, no secrets, no media binaries;
- asset references remain URLs.
Admin can download and hand the file to another Siro store.

## 4.1 Assets

P1 accepts URLs only (`logo_url`, banner `image`, `preview_url`). Uploading a zip
with `assets/` and rewriting them through the media pipeline is P2. Never execute
package files; never trust SVG/JS from a theme.

## 5. Public demo and preview

- `/preview` lists allowlisted built-in presets from
  `GET /api/storefront/theme-presets` (presentation subset only).
- “Try this theme” sets an httpOnly `siro_theme_preview` cookie for 1 hour and
  redirects to `/`. The storefront merges the preset into the active config
  without publishing or writing storefront config.
- Preview responses are `noindex, nofollow`; the banner links to the gallery and
  `/preview/exit` clears the cookie. Checkout is redirected to `/cart` while
  previewing so a visitor cannot place a real order in demo mode.
- Preview never changes prices, stock, payments, orders or the published config.\n- Product cards and the campaign hero use the preset media set (fashion/F&B have\n  three images, other verticals one) so the demo visibly changes product art.
- The 18 presets are the allowlist; arbitrary theme keys fall back to the
  published storefront.

## 6. Validation and security

- Reuse storefront config validation (colors, typography, sections, href policy,
  XSS scan); unknown fields are dropped.
- Reject `javascript:`/`data:` hrefs, scripts, styles, nested HTML.
- No DB writes before validation; import is one draft save with idempotency.
- Cross-store portability: scope from package is ignored; the active scope wins.
- Theme packages are signed only when distributed commercially (P2); they are
  data, so signature is optional for self-authored themes.

## 7. Tests

- Unit: valid package → config input; invalid schema/key/version/empty sections;
  scope stripped; unsafe href rejected; export/import roundtrip.
- API: import returns draft, requires Idempotency-Key, optimistic version conflict
  returns 409, invalid package returns 422.
- Admin: import file sets local draft without auto-save; validation errors shown.

## 8. Roadmap

- P1: JSON/zip import, assets, admin UI, JSON/zip export (implemented).
- P2: theme marketplace/licensing, preview screenshots, theme dependency
  (`requires`), signed commercial themes.
