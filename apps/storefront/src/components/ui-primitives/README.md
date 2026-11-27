# UI primitives — shared components for batch-1 plugins

Import from `@/components/ui-primitives`. Do **not** re-implement modal /
popover / badge / sticky-bar behaviour inside a plugin — use these, and if one
is missing a prop you need, ask the Experience lane (coordinator) instead of
forking a copy.

Styling lives in `apps/storefront/src/app/globals.css` (section
"UI primitives", tokens only — no hard-coded colours). The global
`prefers-reduced-motion` rule already disables animation/transition for these.

## Modal — popup dialog (Email Capture Popup)

```tsx
import { Modal } from "@/components/ui-primitives";

const [open, setOpen] = useState(false);
<button type="button" onClick={() => setOpen(true)}>Get 10% off</button>;
<Modal open={open} onClose={() => setOpen(false)} title="Get 10% off"
  description="Leave your email and we will send the code.">
  <form onSubmit={subscribe}>{/* email field + submit */}</form>
</Modal>
```

- `open`, `onClose`, `title` (required, becomes the accessible name).
- `size`: `sm | md | lg` (default `md`). `closeLabel` (default `"Close"`).
- `dismissible` (default `true`): ESC + overlay click close. `false` = close
  button only.
- `initialFocus`: `first | close | none` (default `first`).
- Guarantees: focus trap, focus returns to the trigger, body scroll locked
  while open and restored on close, `role="dialog"` + `aria-modal="true"`,
  close button ≥ 44px.

## Popover — non-modal panel anchored to a trigger

```tsx
import { Popover } from "@/components/ui-primitives";

<Popover triggerLabel="Size guide" panelLabel="Size guide">
  {/* table / text */}
</Popover>
```

- `triggerLabel`, `panelLabel` (required), `placement`: `below | above`.
- Controlled: `open` + `onOpenChange`; uncontrolled: `defaultOpen`.
- Closes on ESC, click-outside, or focus leaving the popover. Focus returns
  to the trigger. Does **not** trap Tab or lock scroll (it is not a modal).

## Badge — product label (`product-card`, `pdp.title` slots)

```tsx
import { Badge } from "@/components/ui-primitives";

<Badge kind="sale" label="-20%" />
<Badge kind="new" label="New" />
<Badge kind="best-seller" label="Best seller" />
<Badge kind="custom" tone="neutral" label="Limited" />
```

- `kind`: `new` (accent) | `sale` (danger) | `best-seller` (success) |
  `custom` (+ `tone`: `accent | success | danger | neutral`).
- `size`: `sm | md` (default `md`). Text truncates, `max-width: 100%` —
  a badge never covers card content. Colours are theme tokens only.

## StickyBar — fixed bottom ATC bar (`pdp.below-atc` slot)

```tsx
import { StickyBar } from "@/components/ui-primitives";

<StickyBar
  title={product.name}
  price="$68.00"               // pre-formatted string; never compute money here
  actionLabel="Add to cart"
  onAction={addToCart}
  visible={showBar}            // lane decides when to show it
/>
```

- `role="region"`, action button ≥ 44px, safe-area aware.
- Never covers content: while `visible` it adds `ui-has-sticky-bar` to
  `<html>` and CSS reserves exactly the bar height as body bottom padding.
  Removed on hide/unmount.
- Optional: `actionDisabled`, `actionBusy` + `actionBusyLabel`,
  `secondaryLabel` + `onSecondary`, `ariaLabel` (default = `title`).

## LiveRegion — screen-reader announcements

```tsx
import { LiveRegion } from "@/components/ui-primitives";

<LiveRegion message={notice} />            {/* polite, visually hidden */}
<LiveRegion message={error} politeness="assertive" visible />
```

Empty `message` renders nothing.

## Hooks (`./focus`)

- `useFocusTrap(ref, active)`, `useBodyScrollLock(locked)`,
  `useFocusReturn(active)` — the same trio Modal uses; reuse them if a plugin
  builds its own overlay instead of using Modal.
- `usePrefersReducedMotion()` — honour it before running JS-driven animation.
- `FOCUSABLE_SELECTOR`, `focusableElements(container)`.

## Demo / axe E2E

Demo page (test mode only, 404 in production): `/dev/ui-primitives`.
Axe spec: `apps/storefront/e2e/ui-primitives.spec.ts`.
Unit tests: `apps/storefront/src/__tests__/ui-primitives.test.tsx`.
