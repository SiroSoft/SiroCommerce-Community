# Storefront Theme And Vertical Architecture

Status: Ready for implementation
Owners: 1 backend developer + 1 frontend developer
Target: Configurable, multi-vertical storefront without forking the core app

## 1. Goal

Allow a store/channel to customize brand identity, homepage composition,
product presentation, and purchase presentation through validated configuration
and frontend presets. A new vertical must be created by selecting a preset and
configuring allowed content, not by copying the storefront application.

This work is presentation-only. The backend remains the source of truth for:

- Product price and currency.
- Product availability, capability, stock, and backorder state.
- Delivery and fulfillment state.
- Payment and order state.
- Commerce scope and authorization.

## 2. Non-goals

- No arbitrary HTML, CSS, JavaScript, React component names, or executable
  templates in API data.
- No replacement of `/api/settings`.
- No change to existing catalog, cart, quote, checkout, or order contracts.
- No preset is allowed to make an unavailable product purchasable.
- No theme configuration may contain secrets, cart tokens, provider credentials,
  or customer data.

## 3. Ownership

### Backend developer

- Migrations, models, repositories, validation, public read endpoint.
- Admin draft/publish/revision endpoints.
- Scope authorization, caching, ETags, idempotency, OpenAPI, seeds, tests.

### Frontend developer

- Theme types, defaults, CSS variable bridge, vertical presets.
- Homepage section registry and renderer.
- Product card/detail presentation slots.
- Scope-aware config loading and safe fallback.
- Admin-independent storefront tests and visual/browser coverage.

### Shared handoff

Backend delivers the public response contract and fixture payload first. Frontend
must be able to complete Phase 1 using the local default fixture before the live
endpoint is available.

## 4. Commerce identity

Every configuration is identified by:

```text
store_id + channel_id + locale + currency
```

Required request headers:

```text
X-Store-Id
X-Channel-Id
X-Locale
X-Currency
```

The server must authorize that an authenticated admin may write the requested
store/channel. A client-supplied scope is never sufficient authorization.

## 5. Public response contract

Endpoint:

```text
GET /api/storefront/config
```

The endpoint is a public FE-guarded read. It returns only the published
configuration for the requested scope.

Example:

```json
{
  "success": true,
  "data": {
    "scope": {
      "store_id": "store-1",
      "channel_id": "web",
      "locale": "en",
      "currency": "USD"
    },
    "theme": {
      "key": "editorial",
      "version": "1.0.0",
      "schema_version": 1,
      "tokens": {
        "colors": {
          "ink": "#1E2420",
          "muted": "#536057",
          "paper": "#F5F5F0",
          "surface": "#FFFEFA",
          "accent": "#883C29",
          "accent_dark": "#693021"
        },
        "typography": {
          "display": "editorial",
          "body": "system",
          "density": "comfortable"
        },
        "shape": {
          "radius": "soft",
          "card": "bordered"
        }
      }
    },
    "vertical": {
      "key": "general",
      "version": "1.0.0"
    },
    "brand": {
      "name": "SiroCommerce",
      "wordmark": "siro/commerce",
      "logo_url": null,
      "favicon_url": null
    },
    "navigation": {
      "menu_key": "primary",
      "items": []
    },
    "homepage": {
      "sections": []
    },
    "presentation": {
      "product_card_variant": "editorial",
      "product_detail_variant": "editorial",
      "purchase_variant": "standard"
    },
    "policies": {
      "shipping_url": "/shipping",
      "returns_url": "/returns",
      "support_url": "/contact"
    },
    "version": 1
  },
  "meta": {
    "schema_version": 1
  }
}
```

Rules:

- Unknown theme tokens are ignored by the frontend.
- Invalid values cause the whole affected token group to use the parent/default group (fail-closed, no partial unsafe merge).
- Unknown homepage section types are skipped, never dynamically imported.
- Published config must be complete enough to render the existing `general`
  storefront.
- `version` is the optimistic configuration version, separate from theme and
  vertical package versions.

### Child-theme inheritance (`extends`)

`StorefrontTheme.extends?: string` names a parent key in `apps/storefront/src/theme/presets.ts`.
`resolveTheme(value, registry)` merges parent brand/tokens before applying child overrides:

- `resolveTokens(child.tokens, parent.tokens)` — missing color keys inherit from parent; invalid color/typography/shape → parent group.
- `brand.name/wordmark/logoUrl` sanitize via `safeText`/`safeUrl`; empty wordmark inherits parent.
- Guards: unknown parent, cycle (`chain.includes(parent)`), or chain depth >3 → `defaultTheme` (`general`). `extends` is preserved on the resolved theme when supplied.

Authoring presets (`general`/`noir`/`forest`) are **clone starting points only**: copy one entry, change `key`, set `extends` to the parent, override only needed `brand`/`tokens`. They are not fetched; the backend JSON is the source of the active theme (commit `570d575`).

Minimal clone (no app fork):

```ts
// apps/storefront/src/theme/presets.ts
seasonal: { ...defaultTheme, key:"seasonal", extends:"forest", brand:{...}, tokens:{ colors:{accent:"#7A3B2E", accent_dark:"#5E2E24", ink:"#1A211D", muted:"#5F6D61", paper:"#F6F5EF", surface:"#FFFEFA"}, typography:{...}, shape:{...} } }
```
Backend `theme_key` allowlist must include the new key if it is to be publishable via Admin (`StorefrontConfigValidator`).

## 6. Homepage section contract

Allowed types in version 1:

```text
campaign_hero
collection_directory
editorial_story
product_collection
service_proof
trust_strip
category_links
banner_grid
usp_strip
faq
```

Multi-vertical section props (all optional; when present they are validated):

```json
{
  "banner_grid": { "banners": [ { "title": "...", "subtitle": "...", "href": "/...", "image": "/media/...", "cta": { "label": "...", "href": "/..." } } ] },
  "usp_strip": { "items": [ { "icon": "truck", "title": "...", "copy": "..." } ] },
  "faq": { "items": [ { "question": "...", "answer": "..." } ] }
}
```

- `banner_grid` accepts 2-4 banners; every banner needs a safe `title` and `href`.
- `usp_strip` accepts 3-4 items; every item needs `title` and `copy` (optional `icon`).
- `faq` accepts 1-20 items; every item needs `question` and `answer`.
- Existing configs that only contain the original seven types remain valid.
- The storefront renderer registry must add these three types before they render;
  unknown types continue to be skipped safely until then.

Example:

```json
{
  "id": "new-edit",
  "type": "product_collection",
  "enabled": true,
  "order": 40,
  "props": {
    "source": "tag:new",
    "title": "Just arrived",
    "eyebrow": "The new edit",
    "limit": 4,
    "cta": { "label": "Shop new arrivals", "href": "/search?tag=new" }
  }
}
```

Allowed data sources:

```text
tag:<value>
category:<value>
featured
best_selling
recent
```

The backend validates section type, order bounds, string lengths, URL policy,
and source format. It must reject arbitrary component names and raw markup.

## 7. Vertical presets

Initial keys:

```text
general
apparel
home
food_beverage
digital
marketplace
b2b
beauty
pharmacy
fashion
fnb
electronics
grocery
cosmetics
footwear
jewelry
furniture
sports
pets
baby
books
flowers
supplements
auto
```

The full industry preset catalogue is `general` plus 18 verticals. Every entry
is a full starting configuration, not a token-only preset:

| Key | One-line focus |
| --- | --- |
| `general` | Neutral SiroCommerce default with the standard homepage. |
| `beauty` | Soft editorial beauty storefront with routine-led collections and trust proof. |
| `pharmacy` | Trust-first pharmacy layout with pharmacist proof and refill guidance. |
| `fashion` | Editorial fashion lookbook with new-season collections and size/returns FAQ. |
| `fnb` | Appetite-led food and beverage layout with delivery and subscription FAQ. |
| `electronics` | Spec-first electronics storefront with compare banners and warranty proof. |
| `b2b` | Company-first wholesale layout with quote request and procurement FAQ. |
| `grocery` | Bright, value-led grocery storefront with aisles, promos and price/trust proof. |
| `cosmetics` | Pastel skincare and makeup storefront with ingredients and review-led proof. |
| `footwear` | Size-first footwear storefront with lookbook banners and fit/returns FAQ. |
| `jewelry` | Refined jewelry storefront with spotlight collections and warranty proof. |
| `furniture` | Warm furniture storefront with large imagery, delivery and assembly guidance. |
| `sports` | High-energy performance storefront with fit guidance and warranty proof. |
| `pets` | Playful pet storefront organised by species with food subscriptions and vet FAQ. |
| `baby` | Gentle baby and mother storefront with safety proof and age-based sizing. |
| `books` | Editorial books and stationery storefront with author/topic discovery and stock FAQ. |
| `flowers` | Romantic flower and gift storefront with same-day delivery and card messages. |
| `supplements` | Trust-led supplement storefront with dosage guidance and subscription options. |
| `auto` | Technical auto parts storefront with fitment guidance and garage FAQ. |

The industry presets (`beauty`, `pharmacy`, `fashion`, `fnb`, `electronics`,
`b2b`, `grocery`, `cosmetics`, `footwear`, `jewelry`, `furniture`, `sports`,
`pets`, `baby`, `books`, `flowers`, `supplements`, `auto`) are full starting
configurations. Each seeded preset carries:

- A child theme key (for example `beauty`) that `extends: "general"`, plus
  industry tokens (colors, typography, shape).
- Header (`layout`, `sticky`, `announcement`), footer columns, and policy links.
- Presentation variants (`product_card_variant`, `product_detail_variant`,
  `product_list_variant`, `purchase_variant`). Accepted cards are `editorial`,
  `minimal`, `spotlight`; details are `editorial`, `focused`,
  `page-gallery-left`, `split-buybox`; lists are `list-editorial`,
  `list-grid-wide`; purchase is `standard` or `compact`.
- A non-empty homepage section list using the seven original section types and
  the `banner_grid` / `usp_strip` / `faq` types above.

`GET /api/admin/storefront/vertical-presets` keeps the existing
`key`/`name`/`version`/`status` fields and additionally exposes
`presentation_defaults`, `homepage_defaults`, `theme_defaults` and, when seeded,
`description` and `preview`. `POST /api/admin/storefront/config/apply-preset`
accepts the documented `preset_key` (and, for Admin compatibility, the same
value in `vertical_key`). Unknown keys return `422`.

Every preset remains presentation-only: it never changes prices, stock,
availability, or checkout. The legacy presentation payload shape is:

```json
{
  "key": "apparel",
  "product_card": {
    "image_ratio": "portrait",
    "show_rating": true,
    "show_availability": true,
    "show_vendor": false
  },
  "product_detail": {
    "show_attributes": true,
    "show_delivery": true,
    "show_ingredients": false,
    "show_subscriptions": false
  },
  "purchase": {
    "quantity_mode": "standard",
    "require_options_before_purchase": true
  },
  "homepage": {
    "preferred_sections": ["campaign_hero", "collection_directory", "product_collection", "service_proof"]
  }
}
```

Preset constraints:

- `show_vendor` changes visibility only; it cannot invent a vendor.
- `show_delivery` renders only when product delivery data exists.
- `quantity_mode` changes controls, not money or inventory validation.
- `require_options_before_purchase` may disable the CTA, never enable it.

## 8. Admin lifecycle contract

Endpoints:

```text
GET    /api/admin/storefront/config
PUT    /api/admin/storefront/config
POST   /api/admin/storefront/config/preview
POST   /api/admin/storefront/config/publish
POST   /api/admin/storefront/config/restore/{revision}
GET    /api/admin/storefront/config/revisions
GET    /api/admin/storefront/config/revisions/diff
GET    /api/admin/storefront/themes
GET    /api/admin/storefront/vertical-presets
POST   /api/admin/storefront/config/apply-preset
```

Write requirements:

- CMS-equivalent authorization: admin/owner/marketer plus `cms` capability.
- `Idempotency-Key` required on publish, restore, and apply-preset.
- `expected_version` required on update, publish, restore, and apply-preset.
- Return `409` for stale versions.
- Drafts are never returned by the public endpoint.
- Every publish/restore/apply operation creates a revision and audit record.

Update request:

```json
{
  "expected_version": 1,
  "theme_key": "editorial",
  "vertical_key": "apparel",
  "brand": {},
  "tokens": {},
  "navigation": {},
  "homepage": { "sections": [] },
  "presentation": {},
  "policies": {}
}
```

## 9. Persistence model

Additive migrations:

### `storefront_themes`

```text
id, key, name, version, schema_version, status,
token_schema_json, allowed_section_types_json, defaults_json,
created_at, updated_at
```

### `storefront_vertical_presets`

```text
id, key, name, version, status,
theme_defaults_json, presentation_defaults_json,
homepage_defaults_json, validation_schema_json,
created_at, updated_at
```

### `storefront_configs`

```text
id, store_id, channel_id, locale, currency,
theme_key, vertical_key, status, version,
config_json, scheduled_at, published_at,
created_by, updated_by, created_at, updated_at
```

Unique key:

```text
store_id, channel_id, locale, currency
```

### `storefront_config_revisions`

```text
id, config_id, version, snapshot_json, action,
actor_id, idempotency_key, store_id, channel_id,
locale, currency, created_at
```

Do not alter the global `settings` table for this feature.

## 10. Cache and fallback

Public cache key:

```text
storefront:config:{store_id}:{channel_id}:{locale}:{currency}:{published_version}
```

Public response:

```text
Cache-Control: public, max-age=60
ETag: <canonical-published-response-hash>
X-Commerce-Scope: <scope-key>
X-Storefront-Config-Version: <version>
```

Fallback order:

1. Exact published scope config.
2. Store/channel default.
3. Global default `general` preset.
4. Frontend safe defaults.

Invalidate on publish, restore, and apply-preset. Draft/preview responses must
not be publicly cached.

## 11. Acceptance criteria

### Backend

- Scope A cannot read or write scope B configuration.
- Public reads return published data only.
- Draft changes are invisible until publish.
- Stale `expected_version` returns `409` without mutation.
- Repeating the same idempotency key returns the same result.
- Publish changes ETag and version.
- Restore creates a new revision instead of deleting history.
- Invalid tokens, blocks, URLs, and section types are rejected.
- OpenAPI contains all new routes and schemas.
- Existing catalog, CMS, settings, and storefront context tests remain green.

### Frontend

- Existing default storefront renders identically enough to preserve current
  functional and accessibility contracts.
- Theme config changes CSS variables without changing business data.
- Preset changes product presentation without changing product truth.
- Unknown theme/preset/section values fail closed to `general` defaults.
- Homepage sections are ordered, enabled/disabled, and rendered through a fixed
  allowlisted registry.
- Product slots cannot bypass capability, status, currency, or quantity rules.
- Scope changes invalidate config, catalog, CMS, and cached presentation data.
- All locales use the selected locale with English fallback per missing key.
- Mobile 320px, tablet 768px, and desktop 1440px browser suites pass.

## 12. Delivery phases

### Phase 0: Contract lock, 0.5 day

- Backend creates fixture response and OpenAPI draft.
- Frontend creates local `general` config matching current output.
- Both developers agree on section and token allowlists.

### Phase 1: Local FE architecture, 2-3 days

- Theme types/defaults/resolver.
- Vertical presets.
- CSS variable bridge.
- Homepage registry.
- Product card/detail slots.
- No backend dependency yet.

### Phase 2: Backend foundation, 2-3 days

- Four tables and seed defaults.
- Public config endpoint.
- Scope validation, cache, ETag, OpenAPI.

### Phase 3: FE live integration, 1-2 days

- Load public config server-side.
- Safe fallback on 401/403/404/503/malformed payload.
- Scope-qualified cache and invalidation.

### Phase 4: Admin lifecycle, 3-4 days

- Draft edit, preview, publish, restore, revisions, diff, apply-preset.
- Admin UI may follow after API and FE read integration are stable.

### Phase 5: Hardening, 1-2 days

- Cross-scope tests.
- Browser matrix.
- Performance and accessibility audit.
- Seeded demo evidence and release notes.

## 13. Required commands

```text
composer test --working-dir backend
composer check:api-contract --working-dir backend
npm run typecheck --workspace apps/storefront
npm run lint --workspace apps/storefront
npm run test --workspace apps/storefront
npm run i18n:check --workspace apps/storefront
npm run build --workspace apps/storefront
npm run test:e2e --workspace apps/storefront
```
