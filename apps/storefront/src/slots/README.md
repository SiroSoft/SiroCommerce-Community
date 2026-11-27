# Storefront slots

Named, type-safe extension points for first-party and third-party plugins.
Plugins register React components against a slot name; the core storefront
renders them without being modified.

## Slot names

`header`, `footer`, `pdp`, `product-card`, `pdp.title`, `pdp.below-atc`,
`pdp.below-gallery`, `cart`, `checkout`, `my-account`, `email`,
`order-status`.

`SlotName` is derived from the `SLOT_NAMES` tuple, so unknown names fail at
compile time and are rejected at runtime.

## Two registration models

### 1. Build-time registry (first-party)

Use `createSlotRegistry` / `registerSlot` when the set of components is known
before the app boots. The result is immutable and can be passed to
`SlotProvider`.

```tsx
import { SlotProvider, registerSlot, type SlotRegistry } from "@/slots";

let registry: SlotRegistry = {};

registry = registerSlot(registry, "pdp", {
  id: "acme.reviews@1",
  priority: 50,
  component: AcmeReviews,
});
```

`registerSlot` returns a validated, sorted registry. It throws on duplicate ids
inside a slot, unknown slot names and non-finite priorities/orders.

### 2. Runtime registry (plugins)

Use the runtime API when a plugin is wired up during app bootstrap, or when
tests need to add/remove components without rebuilding a registry.

```tsx
import {
  getSlotRegistry,
  registerSlotComponent,
  resetSlotRegistry,
  unregisterSlotComponent,
} from "@/slots";

registerSlotComponent("pdp", {
  id: "acme.reviews@1",
  priority: 50,
  component: AcmeReviews,
});

unregisterSlotComponent("pdp", "acme.reviews@1"); // true/false
const snapshot = getSlotRegistry();
resetSlotRegistry(); // tests; clears all runtime registrations
```

Rules enforced by `registerSlotComponent`:

- `name` must be a known `SlotName`.
- `priority` must be a finite number.
- `component` must be a function/class component or a React exotic component
  (`memo`, `forwardRef`, `lazy`). Plain values are rejected.
- A duplicate `id` within the same slot is **rejected** and the existing
  registration is left untouched. Re-registering requires
  `unregisterSlotComponent` first. Use a versioned id to ship a new behavior
  instead of silently replacing one.
- The registry is only updated after validation succeeds.

`SlotProvider` subscribes to the runtime registry, so a registration made after
mount re-renders the affected slots. No stale cache is kept.

When `SlotProvider` is given an explicit `registry`, it merges that registry
with runtime registrations before validating. Duplicate ids across the two
sources throw. This lets the core keep passing its first-party registry while
plugins still register at runtime.

Render a slot:

```tsx
<SlotProvider registry={registry}>
  <Slot
    name="pdp"
    scope={scope}
    locale={locale}
    data={{ product }}
    as="section"
    className="pdp-extras"
    fallback={<p>No extensions</p>}
    errorFallback={<p>Extension unavailable</p>}
  />
</SlotProvider>
```

Components receive typed `SlotProps`:

```ts
interface SlotProps {
  scope?: string;
  locale?: string;
  data?: Record<string, unknown>;
}
```

Registration order:

- `priority` ascending (lower renders first). Required and finite.
- `order` ascending as a tie-breaker. Optional.
- `id` lexicographic as the final tie-breaker.

The slot's own `fallback` renders only when it has no registrations. Every
registration is wrapped in an error boundary; a component that throws renders
its `errorFallback` (or nothing) without removing other components from the slot
or crashing the page.

`as` is optional. When omitted, components render without an extra wrapper.
When present, `className` and `wrapperProps` are applied to that element.

## Plugin workflow

The storefront is the only place components are registered. The backend enables
or disables an extension, but it never ships render code.

1. Backend: the extension is enabled for the store/channel and exposes a stable
   extension key plus its options. Money, totals, tax and order state remain
   backend-owned.
2. Storefront build: add a first-party module under `src/plugins/` that imports
   the React component and declares its metadata. Do not inject remote HTML or
   code at runtime.
3. Register: call `registerSlotComponent` once during bootstrap, gated by the
   backend extension flag, with a stable versioned id.
4. Render: core slot locations already call `<Slot name="..." />`; no page code
   changes are needed.

Example: [`example-slot.tsx`](../plugins/example-slot.tsx) exports a typed notice
component and an explicit `registerExampleSlot()` opt-in. Importing the module
does **not** register anything, so production builds have no hidden side
effects.

```tsx
import { registerExampleSlot } from "@/plugins/example-slot";

if (extensions["example.notice"].enabled) {
  registerExampleSlot();
}
```

## SSR and hydration

- Register before rendering on both the server and the client, ideally during
  app bootstrap. The server markup and the first client render must use the same
  registrations or React will report a hydration mismatch.
- The runtime registry is module-scoped. Register once at startup, not inside a
  per-request handler, to avoid leaking one tenant/request into another. For
  per-request composition, build an explicit registry and pass it to
  `SlotProvider`; runtime entries are merged on top.
- Do not read `window`, `document`, cookies or storage at module scope. Keep
  registration pure and do waits/effects inside components.
- `registerSlotComponent`, `unregisterSlotComponent`, `getSlotRegistry` and
  `resetSlotRegistry` never touch browser globals and are safe during SSR.
- `resetSlotRegistry()` is for tests. It clears all runtime registrations;
  pass a validated seed registry to install a new baseline if needed.

## Hooks and utilities

- `useSlots()` returns the validated registry from context.
- `useSlot(name)` returns the sorted registrations for one slot.
- `createSlotRegistry(seed)` validates a fresh registry.
- `registerSlot(registry, name, registration)` appends and validates.
- `validateRegistry(registry)` normalizes and sorts, throwing on invalid input.
- `getSlotRegistry()` returns the current immutable runtime snapshot.
- `registerSlotComponent(name, registration)` adds one runtime component.
- `unregisterSlotComponent(name, id)` removes one runtime component.
- `resetSlotRegistry(seed?)` clears or reseeds the runtime registry.
- `mergeSlotRegistries(...registries)` validates a combined registry.

## Versioning

Use a versioned id such as `vendor.feature@1`. Bump the major version for a
breaking prop/behavior change and register a new id. Keep the old id while
existing storefronts still depend on it, then remove it in a later release.
Priority and order are deployment concerns, not part of the plugin ABI.

## Rules

- Never inject raw HTML or JavaScript. Do not use `dangerouslySetInnerHTML`,
  inline `<script>`, `eval` or `new Function`.
- Do not mutate prices, discounts, tax, shipping or order totals. Money remains
  owned by the core; plugins are display-only unless a documented core API says
  otherwise.
- Do not read `window`, `document`, cookies or storage at module scope. Do it
  inside effects, or use the provided props.
- Keep rendering deterministic and SSR-safe. A slot must render on the server
  without browser globals.
- Treat `data` as untrusted input. Validate and narrow before use.
- Give every registration a stable, unique id within its slot and handle your
  own loading/empty states without hiding core content.
