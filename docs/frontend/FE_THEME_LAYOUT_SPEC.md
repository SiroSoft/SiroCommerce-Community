# Theme Layout Spec — Rules For 10 Headers / 10 Footers / 5 Lists / 5 Pages

Status: SPEC (no code yet). Any layout outside this spec is rejected in review.
Goal: N layouts per surface without if-else sprawl, dead config, or breakage.

## 1. Vocabulary

- **Surface**: `header` | `footer` | `product-list` | `product-page` (+ future: `cart`, `checkout`).
- **Layout key**: namespaced identifier, e.g. `header-split`, `footer-mega`,
  `list-grid-wide`, `page-gallery-left`.
- **Registry**: `Record<LayoutKey, Component>` per surface. The ONLY place that
  maps key → implementation.

## 2. Naming rules (RULE-1)

1. Format: `^[a-z][a-z0-9-]{2,39}$`, always prefixed by surface
   (`header-*`, `footer-*`, `list-*`, `page-*`). Never `layout1..N`.
2. Keys are permanent. Rename = add new key + deprecate old (never reuse).
3. Defaults are fixed forever: `header-standard`, `footer-standard`,
   `list-editorial` (card `editorial`), `page-editorial` (detail `editorial`).

## 3. Single source of options (RULE-2)

Allowed keys live in exactly one constant per surface, imported by all four
layers — no hardcoded option lists anywhere else:

```text
apps/storefront/src/theme/layouts.ts   # HEADER_LAYOUTS, FOOTER_LAYOUTS, LIST_LAYOUTS, PAGE_LAYOUTS
```

| Layer | File | Behavior |
|---|---|---|
| Backend write | `StorefrontConfigValidator` | Unknown key → 422, nothing stored |
| Storefront sanitize | `services/storefront-config.ts` | Unknown key → coerce to default, never crash |
| Admin builder | Theme builder selects | Options rendered FROM the same constants |
| Tests | validator + sanitize + render | One accept + one reject case per key |

If the four layers disagree on the allowed set, the spec is violated.

## 4. Fallback rules (RULE-3)

1. Unknown/stale key in stored config → render the surface default. A theme
   saved by newer code must never white-screen on older code.
2. Registry missing an allowed key (deploy skew) → render default + `console.warn`
   in development only. No error boundaries tripped for layout choice.
3. Config surface absent (`header`/`footer` null) → backend normalizes to
   defaults before storage (already implemented in validator).

## 5. Implementation rules (RULE-4)

1. **Display-only.** Layouts must not change price, stock, payment, tax, order,
   or routing truth. Data fetching stays in the page, never in layout branches.
2. **Registry, not conditionals.** Max ONE switch per surface, inside the
   registry file. Components contain zero `layout === "x"` checks outside it.
3. **CSS hooks, not prop drilling.** Layout root carries
   `data-header-layout`, `data-footer-layout`, `data-card-variant`,
   `data-purchase-variant`. Shared CSS in `globals.css` under the
   `Admin-driven theme tokens` block; per-layout overrides go there, not in
   CSS modules scattered per component.
4. **Context over props.** Client components read via `useCardVariant()` /
   `usePurchaseVariant()`; server pages read `config.presentation` directly.
   New surfaces follow the same context pattern (`variant-context.tsx`).
5. **i18n.** No hardcoded user-facing strings in any layout. New copy goes to
   `locales/en/common.json` + vi/de/zh/ja in the same commit (parity check:
   nested-missing must stay 0).
6. **a11y per layout.** Landmarks preserved (`banner`, `contentinfo`,
   radiogroups for slots), `aria-label`s localized, keyboard order sane,
   focus visible. Each layout render test asserts its landmarks.
7. **Responsive.** Every layout works at 360px and 1380px (existing breakpoints
   `700px`/`1100px`); no horizontal scroll; mobile sticky bar keeps working.
8. **No layout-specific data needs.** If a layout needs new data (e.g. store
   hours in footer), that data becomes a validated config field first
   (validator + sanitize + admin input), never a hardcoded fetch.

## 6. Density, fonts, tokens interaction (RULE-5)

- Layouts must respect active tokens: use `var(--*)` component variables, never
  hardcoded colors/sizes that fight the theme.
- `data-density` (`compact`/`comfortable`/`spacious`) adjusts spacing only;
  layouts must not override density variables except through the density block.
- Font stacks render via `--theme-font-display/body`; layouts inherit them.

## 7. Test matrix per new layout (RULE-6, mandatory)

| # | Test | Where |
|---|---|---|
| 1 | Backend accepts key, rejects `layout-hologram` | `StorefrontConfigTest` |
| 2 | Sanitize keeps key; unknown coerces to default | `theme-config.test.ts` |
| 3 | Renders landmarks + `data-*-layout` attr | `theme-admin.test.tsx` (or surface file) |
| 4 | Key appears in admin builder options | admin suite (options render test) |
| 5 | No new TS errors, no bundle regression | `typecheck` + `check-bundle-budget` |

Coverage gates do not move backwards: backend total, storefront total, admin
total must stay green.

## 8. Versioning & rollout (RULE-7)

- ADD layout = minor, backward compatible, no migration. Old themes untouched.
- REMOVE layout = major: mark deprecated in spec, keep rendering for 2 releases,
  migrate stored configs, then delete.
- Publish flow unchanged: draft → preview → publish → revisions. New layouts
  must be previewable from the Theme builder before publish.
- Live smoke after deploy: homepage + 1 PDP + cart render 200, health green.

## 9. Definition of done for the first batch — DONE (release: theme batch-1)

- Registry: `components/chrome/header-layouts.tsx` + `footer-layouts.tsx`
  (shared parts in `header-parts.tsx`); constants in `theme/layouts.ts`.
- Demo layouts: `header-split` (nav/brand/actions), `footer-mega`
  (4-column grid), `list-grid-wide` (3-col, `data-list-layout`), 
  `page-gallery-left` (sticky gallery + 3-col supporting, `data-detail-variant`).
- List variant plumbing: `product_list_variant` config →
  `PresentationVariantProvider(list)` → `useListVariant()` →
  `ProductGrid[data-list-layout]` (client) / config prop (server sections).
- Admin builder options sourced from service constants (RULE-2); backend +
  sanitize + admin + tests agree on all key lists below.
- Full suites green + `next build` + deploy (see release notes).

Final key lists (batch 1):

```text
header:      standard | minimal | centered | split
footer:      standard | minimal | mega
list:        list-editorial | list-grid-wide        (product_list_variant)
page/detail: editorial | focused | page-gallery-left (product_detail_variant)
card:        editorial | minimal
purchase:    standard | compact
```

## 10. Non-goals

- No visual drag-and-drop builder (API + form builder is the interface).
- No per-layout data fetching or commerce logic.
- No layout-specific locales (copy is shared across layouts).
