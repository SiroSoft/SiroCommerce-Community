# Theme Extension Guide (admin-driven, no redeploy for content changes)

Owner: Frontend developer + merchandiser
Runtime source of truth: `GET /api/storefront/config` (published) → `sanitizeStorefrontConfig` → `ThemeProvider` + `PresentationVariantProvider`.

## 1. What changes without a deploy

| Surface | Admin path | Config field |
|---|---|---|
| Colors, radius, card, density | Theme builder → Theme tokens | `theme.tokens` |
| Fonts + base size | Theme builder → Theme tokens | `typography.display_stack/body_stack/base_size` → `--theme-font-display/body/size` |
| Brand, wordmark, logo | Theme builder → Theme identity | `brand.*` (logo renders in header + footer) |
| Custom / child theme | Theme key + extends | `theme_key` + `theme_extends` (extends a preset, overrides only diffs) |
| Vertical (apparel/food/B2B…) | Apply preset | `vertical_key` |
| Header/footer chrome | Theme builder → Header & footer | `header.{announcement,layout,sticky}`, `footer.{layout,show_help,show_policies,columns}` |
| Homepage order/visibility | Theme builder → Homepage sections | `homepage.sections[]` (order = list position × 10) |
| Card/detail/purchase look | Theme builder → Presentation | `presentation.*_variant` |
| Menu + policy links | Theme builder → Navigation & policies | `navigation.items`, `policies.*_url` |

Workflow is always draft → (preview) → publish, with `expected_version` optimistic
concurrency, idempotency keys, revisions, diff, and restore. Nothing here changes
price, stock, payment, tax, or order truth (presentation-only by contract).

## 2. Adding a homepage section type (needs a deploy)

1. Backend allowlist: `StorefrontConfigDefaults::allowedSections()` (+ per-type
   `props` rules in `StorefrontConfigValidator::publicConfig`, like
   `product_collection.source`).
2. Storefront renderer: new component under `components/merchandising/`,
   register in `homepage/registry.tsx`, extend `HomepageSectionType`.
3. Admin: append the type to `HOMEPAGE_SECTION_TYPES` in
   `services/storefront-theme.service.ts` — the builder picks it up.
4. Tests: backend validator accept/reject + `theme-config.test.ts` sanitize case.

## 3. Adding a presentation variant (needs a deploy)

1. Extend the union (`ProductCardVariant` / `ProductDetailVariant` /
   `PurchaseVariant` in `theme/types.ts`).
2. Implement the look: `data-card-variant` / `data-purchase-variant` hooks and
   conditional branches (see `ProductCard minimal`, PDP `focused`,
   `PurchaseFlow compact`). Keep variants display-only.
3. Accept the value in `sanitizeStorefrontConfig`, backend
   `StorefrontConfigValidator`, and the admin Presentation card options.
4. Client components read variants via `useCardVariant()` /
   `usePurchaseVariant()` (no prop drilling); server pages read
   `config.presentation` directly.

## 4. Adding a theme preset or vertical (needs a deploy)

- Preset: clone an entry in `theme/presets.ts` (keep `extends`, override tokens).
  Child themes from admin can extend any preset key without code.
- Vertical: add to `verticals/presets.ts` + backend `writeConfig` allowlist; the
  admin preset dropdown lists backend `vertical-presets`.

## 5. Validation contract (both sides fail closed)

- Colors: strict `#rrggbb`. Font stacks: ≤200 chars, font-name characters only,
  no `url(`/`expression`/markup — suspicious values fall back to theme default.
- `base_size`: integer 12–22. Layouts/variants: fixed allowlists, unknown values
  coerce to defaults (storefront) or 422 (admin write).
- Hrefs (nav, footer columns, policies): same-origin `/…` or `http(s)` only,
  ≤512 chars, never `javascript:`/`data:`.
- Section ids unique, ≤50 sections, order 0–10000.

## 6. Local try-out

1. `PUT /api/admin/storefront/config` with `{expected_version, theme_key, …}` +
   `Idempotency-Key` → draft.
2. `POST /api/admin/storefront/config/publish` → live in ≤60s (public cache TTL).
3. Roll back via revisions restore — history is append-only.
