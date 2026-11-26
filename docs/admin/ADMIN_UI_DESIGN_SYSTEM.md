# Admin UI Design System

Status: `DOCUMENTATION-ONLY BASELINE`

This is the visual and interaction contract for the enterprise admin UI. It is
deliberately small: teams should compose a few predictable primitives instead of
creating a page-specific visual language. Existing frontend implementation may
choose the rendering library, but must preserve these behaviors and tokens.

## 1. Design Direction

The admin is a calm operations workspace: high information density, strong
hierarchy, restrained color, and clear task completion. Color never carries
meaning alone. Progressive disclosure is the default; expert controls appear
only when the task requires them.

## 2. Tokens

Token names are normative; values are the initial baseline and may be themed by
brand without changing semantic meaning.

### Color

| Token | Baseline | Use |
|---|---|---|
| `color.canvas` | `#F7F8FA` | Application background |
| `color.surface` | `#FFFFFF` | Cards, tables, forms |
| `color.surface-subtle` | `#F0F2F5` | Secondary panels and selected rows |
| `color.text` | `#17202A` | Primary text |
| `color.text-muted` | `#5B6773` | Supporting text |
| `color.border` | `#D7DDE3` | Dividers and controls |
| `color.focus` | `#155EEF` | Focus ring; must contrast 3:1 |
| `color.info` | `#175CD3` | Informational state |
| `color.success` | `#087443` | Completed/safe state |
| `color.warning` | `#B54708` | Attention/pending state |
| `color.danger` | `#B42318` | Error/destructive state |

State text always includes a label or icon with an accessible name. Use tinted
backgrounds only as a supplement. Validate all final themes against WCAG 2.2 AA:
4.5:1 normal text, 3:1 large text and 3:1 UI/focus indicators.

### Typography

Use the product sans-serif stack with tabular numerals for tables and finance.

| Token | Size / line height | Use |
|---|---|---|
| `type.display` | `28 / 36` | Page title, desktop only |
| `type.heading` | `20 / 28` | Section heading |
| `type.subheading` | `16 / 24` | Card/form group heading |
| `type.body` | `14 / 20` | Default admin text |
| `type.label` | `12 / 16` | Labels, metadata, table headings |
| `type.mono` | `12 / 18` | Correlation IDs and technical values only |

Never use monospace for customer-facing IDs unless it improves scanning. Money,
dates and percentages use locale-aware formatters; values are never parsed back
from formatted text. The supported locale baseline is `en`, `vi`, `ja`, `de`,
and `zh`.

### Space, sizing and shape

| Token | Value | Use |
|---|---:|---|
| `space.1`..`space.8` | `4, 8, 12, 16, 20, 24, 32, 40px` | All layout gaps |
| `control.height-sm` | `32px` | Dense table/filter controls |
| `control.height-md` | `40px` | Default buttons and inputs |
| `control.height-lg` | `48px` | Primary mobile/action controls |
| `radius.sm` | `4px` | Inputs, compact controls |
| `radius.md` | `8px` | Cards, drawers |
| `radius.lg` | `12px` | Modal and prominent panels |
| `shadow.panel` | `0 1px 3px rgb(16 24 40 / 10%)` | Raised surface |
| `shadow.overlay` | `0 12px 32px rgb(16 24 40 / 18%)` | Drawer/modal |

Interactive targets are at least 24x24 CSS px, with 40px preferred for primary
controls and touch. Use an 8px layout rhythm; do not add arbitrary one-off sizes.

## 3. Layout and Density

- Content max width: `1440px`; page gutters `24px` desktop, `16px` mobile.
- Desktop shell: navigation `240px` expanded or `64px` collapsed; top bar `64px`.
- Dense tables use `44px` rows; comfortable forms use `48px` controls and `16px` group gaps.
- Default density is compact enough for operations, but never below the target size or readable line height.
- At 320px and 400% zoom, content reflows without essential loss or page-level two-dimensional scrolling.
- Breakpoints are behavior-based: below 768px use mobile shell/full-screen task surfaces; 768-1199px use compact navigation; 1200px+ use desktop shell.

## 4. Component Contracts

### Shell and navigation

`AppShell`, `SideNav`, `TopBar`, `Breadcrumbs`, `ScopeSwitcher`, and
`CommandPalette` own layout and focus behavior only. They do not fetch business
data or make permission decisions. The active route is announced and visible in
the tree. Command search may find entity, setting and action, but results are
filtered by current capability and scope.

### Buttons and actions

Button hierarchy is `primary` (one per task), `secondary`, `tertiary`, and
`danger`. Icon-only buttons require a tooltip plus accessible name. Disable only
when the reason is actionable; hide unavailable features when the user has no
relevant capability. A disabled mutation is not a security control.

### Status and notifications

`StatusBadge` uses text plus icon and supports `success`, `info`, `warning`,
`danger`, `pending`, `unknown`, and `unavailable`. `InlineAlert` carries page
state; `Toast` carries completion/background feedback; `Progress` carries queued
work. Use `aria-live="polite"` for routine updates and assertive announcements
only for urgent failures that require immediate attention.

### Data table

Required behavior: semantic table markup on desktop, keyboard-sortable headers
only where sorting is supported, labeled selection, row focus, visible sort
state, page/per-page pagination, and preserved filters on return. Provide a
responsive card representation or an explicitly labeled table scroll region.
Do not make the whole row an unlabeled button. Bulk actions appear only when
there is a documented bulk operation.

### Forms

Every control has a programmatic label, hint where needed, required state, and
field-level error association. Group related inputs with a legend. Preserve
values after `422`, `409`, timeout or server failure. The first invalid field is
focused after submit, and the page does not lose the user’s scroll context.

### Drawer, modal and confirmation

Drawers preserve the invoking page context; modals block only when a decision is
unsafe to leave unresolved. Both trap focus, expose an accessible title and
description, close with Escape when safe, and restore focus to the trigger.
Destructive dialogs include object, scope, impact, and exact confirm verb.

### Skeleton, empty and error panels

Skeletons match expected geometry and are replaced by content without layout
jumps. Empty panels contain explanation and one next action. Error panels expose
retry only for retryable states, never retry `401`, `403`, `404` or validation
errors automatically, and display a redacted correlation ID when useful.

## 5. Interaction Standards

- Hover is enhancement only; every behavior works by keyboard and touch.
- Focus is visible, high contrast and never removed with `outline: none`.
- Motion is subtle, optional, and disabled under reduced-motion preferences.
- Autosave is allowed only for safe reversible edits and must show saved state.
- Publish, restore, refund, payment, legal hold, anonymize, cancel and external
  provider actions require explicit confirmation and idempotent/reconciliation UX
  according to the operation contract.
- Never use color, animation, toast timing, or an icon alone to communicate state.

## 6. Accessibility Release Contract

All admin, reporting, fulfillment and platform screens meet WCAG 2.2 AA:

- skip link reaches the main landmark;
- logical keyboard order, no traps, and focus return for menus/dialogs/drawers;
- screen-reader names for every control and no duplicate landmarks;
- 320px reflow and 400% zoom without content loss;
- 24x24px minimum target with adequate spacing;
- errors identify fields and correction, and async states are announced;
- axe has zero critical/serious findings on release-critical routes.

Run checks after opening navigation, command palette, menus, date pickers,
dialogs, drawers, validation errors, pagination and async panels. Evidence names
the route and exact operation IDs used by the screen.

## 7. Operation and Data Boundaries

The design system must not embed domain API calls. Feature screens own query and
mutation composition through the generated SDK; the shared layer owns request
state, error normalization, correlation IDs, idempotency, session recovery and
scope headers.

Representative operation anchors:

| Pattern | Operation IDs |
|---|---|
| Action Center | `get_api_admin_smart_action_center`, `post_api_admin_smart_execute`, `post_api_admin_smart_rescue_id` |
| Orders | `get_api_admin_orders`, `get_api_admin_orders_id`, `post_api_admin_orders_id_transition` |
| Product/catalog | `get_api_admin_products`, `post_api_admin_products`, `put_api_admin_products_id`, `get_api_admin_products_id_variants` |
| Fulfillment/inventory | `post_api_admin_fulfillment_allocate`, `post_api_admin_fulfillment_id_ship`, `get_api_admin_inventory_variant`, `put_api_admin_inventory_variant` |
| CMS | `get_api_admin_pages`, `put_api_admin_pages_id`, `get_api_admin_pages_id_preview`, `post_api_admin_pages_id_publish` |
| Reports/exports | `get_api_v2_admin_reports_overview`, `get_api_v2_admin_reports_failures`, `post_api_v2_admin_exports`, `get_api_v2_admin_exports_id` |

These are traceability anchors, not a replacement for generated types or the
full capability matrix. The current backend OpenAPI has no reliable tags and
some capability arrays are empty; frontend ownership must not paper over that.

## 8. Unresolved Decisions

- Confirm the production browser refresh-token and CSRF model before admin release.
- Add generated role/capability metadata to OpenAPI to prevent route-matrix drift.
- Reconcile OpenAPI `x-scope` coverage with all backend scope-enforced admin routes.
- Define list/read contracts for returns, fulfillment, companies, B2B entities and some inventory screens before committing their list UI.
- Publish complete state enums and allowed action payloads for returns, refunds, fulfillment, B2B and exports.
- Decide the canonical timezone preference and whether any approved currency conversion exists.
- Reconcile export authorization metadata, which is currently capability-gated in route docs but incomplete in OpenAPI.
