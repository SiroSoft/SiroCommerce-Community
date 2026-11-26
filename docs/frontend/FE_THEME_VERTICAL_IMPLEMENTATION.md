# Frontend Theme And Vertical Implementation Plan

Owner: Frontend developer
Depends on: `docs/contracts/STOREFRONT_THEME_VERTICAL_SPEC.md`

## 1. Deliverable

Transform `apps/storefront` from a fixed lifestyle storefront into a safe,
configurable renderer. The `general` preset must reproduce the current behavior
before any live backend configuration is introduced.

## 2. New modules

```text
src/theme/types.ts
src/theme/defaults.ts
src/theme/resolve-theme.ts
src/theme/ThemeProvider.tsx
src/theme/theme-css.ts
src/verticals/types.ts
src/verticals/presets.ts
src/verticals/resolve-vertical.ts
src/homepage/types.ts
src/homepage/registry.tsx
src/homepage/resolve-homepage.ts
src/components/HomepageRenderer.tsx
src/presentation/resolve-product-presentation.ts
src/components/product/ProductCardShell.tsx
src/components/product/ProductDetailShell.tsx
src/services/storefront-config.ts
```

## 3. Theme model

Use strict unions for values that affect rendering:

```ts
type ThemeDensity = "compact" | "comfortable" | "spacious";
type ThemeRadius = "sharp" | "soft" | "round";
type ThemeCard = "flat" | "bordered" | "elevated";

type StorefrontTheme = {
  key: string;
  version: string;
  schemaVersion: number;
  brand: {
    name: string;
    wordmark?: string;
    logoUrl?: string;
  };
  tokens: {
    colors: Record<string, string>;
    typography: { display: string; body: string; density: ThemeDensity };
    shape: { radius: ThemeRadius; card: ThemeCard };
  };
};
```

`resolveTheme()` must sanitize colors, lengths, URLs, and strings. Invalid
values return the default token group, not a partially unsafe value.

## 4. CSS variable bridge

Emit only allowlisted variables:

```text
--theme-ink
--theme-muted
--theme-paper
--theme-surface
--theme-accent
--theme-accent-dark
--theme-success
--theme-danger
--theme-content-width
--theme-reading-width
--theme-radius-sm
--theme-radius-md
--theme-radius-lg
```

`globals.css` keeps compatibility aliases such as:

```css
:root {
  --ink: var(--theme-ink, #1e2420);
  --accent: var(--theme-accent, #883c29);
}
```

Do not inject arbitrary CSS text from the API.

## 5. Vertical behavior

Implement presets first as code. Backend preset IDs map to these known keys:

```text
general, apparel, home, food_beverage, digital, marketplace, b2b
```

The resolver must return `general` for unknown keys. Each preset can select:

- Card image ratio and metadata.
- Detail attribute emphasis.
- Vendor/provenance visibility.
- Delivery/subscription presentation.
- Homepage preferred section order.
- Quantity control label/mode.

The resolver cannot alter `Product.price`, `Product.currency`,
`Product.status`, `Product.capability`, `Product.stock`, or delivery truth.

## 6. Homepage registry

Move current hardcoded homepage composition to a default config:

```ts
const defaultSections = [
  { id: "campaign", type: "campaign_hero", enabled: true, order: 10, props: {} },
  { id: "collections", type: "collection_directory", enabled: true, order: 20, props: {} },
  { id: "story", type: "editorial_story", enabled: true, order: 30, props: {} },
  { id: "new-edit", type: "product_collection", enabled: true, order: 40, props: { source: "tag:new" } },
  { id: "favorites", type: "product_collection", enabled: true, order: 50, props: { source: "featured" } },
  { id: "proof", type: "service_proof", enabled: true, order: 60, props: {} },
] as const;
```

`HomepageRenderer` rules:

- Sort ascending by order, then ID.
- Skip `enabled: false`.
- Skip unknown types.
- Skip data-dependent sections with no data.
- Use stable `id` for DOM section IDs and analytics.
- Never render raw HTML or arbitrary component names.

## 7. Product slots

Slots are extension points, not business overrides. The default product card and
PDP must remain usable when no slot is supplied.

Required slot boundaries:

```text
ProductCard:
  beforeMedia, mediaOverlay, afterTitle, metadata, price, availability, actions

ProductDetail:
  beforeGallery, galleryOverlay, beforePurchase, purchaseHeader,
  options, quantity, purchaseActions, afterPurchase, supporting, related
```

`ProductDecisionPanel` remains the owner of:

- Capability and status gating.
- Options and quantity validation.
- Cart URL construction.
- Price and currency display from product data.

Slots must not render a second purchase mutation or bypass these rules.

## 8. Config loading

Create `services/storefront-config.ts` with two paths:

```ts
getDefaultStorefrontConfig(): StorefrontConfig
getStorefrontConfig(scope: CommerceScope): Promise<StorefrontConfig>
```

Server components load config with the same scope as catalog. On malformed,
missing, unauthorized, or unavailable responses, use the validated `general`
config and emit a non-blocking diagnostic only in development.

Do not let config failure block catalog browsing or checkout.

## 9. Locale work included in this track

`src/lib/i18n.ts` currently always returns English. Correct this before live
vertical content is used:

- Resolve `en`, `vi`, `ja`, `de`, and `zh` from the active locale.
- English is fallback per missing key.
- Keep key parity test.
- Do not use user-provided locale as an import path.

## 10. Test plan

Add:

```text
src/__tests__/theme-config.test.ts
src/__tests__/vertical-presets.test.ts
src/__tests__/homepage-registry.test.tsx
src/__tests__/product-presentation-slots.test.tsx
src/__tests__/scope-theme-isolation.test.ts
```

Minimum cases:

- Default and unknown theme fallback.
- Invalid color/URL/string rejection.
- All vertical keys resolve.
- Unknown vertical resolves to `general`.
- Section order/disabled/unknown behavior.
- Product slots preserve accessible names and links.
- Unsupported products remain unavailable.
- Backorder/out-of-stock status remains truthful.
- Config cache is scoped by store/channel/locale/currency.
- Config failure does not prevent catalog render.

## 11. Definition of done

- No page imports a theme JSON object directly.
- Homepage page is orchestration only.
- Product card and PDP have tested slots.
- `general` visual/functional behavior is backward compatible.
- Three vertical fixtures render at mobile 320px and desktop 1440px.
- All existing storefront unit, browser, accessibility, and build gates pass.
