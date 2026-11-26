# Admin UI Information Architecture

Status: `DOCUMENTATION-ONLY BASELINE`

This document is the information architecture and navigation contract for the
merchant/admin frontend. It is intended for parallel frontend work. It does not
authorize an operation, define API response shapes, or replace backend policy.
The backend and generated SDK remain authoritative.

## 1. Contract Sources

- `docs/admin/04_ADMIN_UX_SPEC.md`: baseline navigation, Action Center, progressive disclosure and UX targets.
- `docs/frontend/FE_CAPABILITY_MATRIX.md`: role/capability visibility and exact operation IDs.
- `docs/frontend/FE_SCOPE_SWITCHING.md`: the central `store + channel + locale + currency` scope.
- `docs/frontend/FE_IMPLEMENTATION_PLAN.md`: delivery boundaries and API client rules.
- `docs/frontend/FE_ACCESSIBILITY_ACCEPTANCE.md`: WCAG 2.2 AA release gate.
- `docs/frontend/FE_FAILURE_RECOVERY.md`: shared request and mutation states.
- `backend/docs/openapi.json`: operation IDs, schemas, security and scope markers.
- `backend/routes/api.php`: current route role middleware; OpenAPI has no dependable tag taxonomy.

The frontend calls generated SDK operations by `operationId`, never by a
hand-maintained URL decision. Navigation visibility is an optimization; a
direct route must still render the normalized `401`, `403`, `404`, `409`,
`422`, `429` and `500` states.

## 2. IA Principles

1. Default business navigation is `Home -> Sell -> Grow -> Store -> Health`.
2. Home is an Action Center, not a dashboard widget dump.
3. Show only work relevant to the current identity, capability and active scope.
4. Put the common path first; expose advanced fields and secondary actions on demand.
5. Every list has one obvious next action and every detail has one primary action.
6. Destructive or externally consequential actions require explicit, specific confirmation.
7. Never infer permissions, status transitions, totals, stock, or finance meaning from labels or URLs.

## 3. Application Shell

### Desktop

- Skip link, then an application landmark with a collapsible left navigation rail.
- Top bar: menu toggle, global search/command palette, scope switcher, notifications,
  help/support entry, and identity menu.
- Main region: breadcrumb, page title, optional scope/evidence line, page actions,
  then content.
- A right-side contextual panel is reserved for detail metadata, activity, and
  secondary tasks; it must not be required to complete the primary task.
- Navigation collapse is a preference only. It must not hide the active section
  from assistive technology.

### Mobile and tablet

- Top bar retains menu, page title and the most important status/action.
- Navigation becomes a modal drawer; close returns focus to the menu button.
- Page actions move into a labeled overflow menu or a sticky bottom action bar.
- Tables become cards or a horizontally scrollable data region with an accessible
  alternative; do not force the entire page to two-dimensional scroll.
- Drawers become full-screen sheets below 768 CSS px. Modals remain centered only
  when their content fits without clipping.

### Global context

The scope switcher displays all four values: `store`, `channel`, `locale`, and
`currency`. It is disabled during a switch and clears/invalidates scoped data and
drafts according to `FE_SCOPE_SWITCHING.md`. It must never select scope from a
URL, resource ID, cached response, or arbitrary user string.

## 4. Navigation Taxonomy

Labels are stable product labels. Route segments are lowercase, plural nouns for
collections and singular resource IDs for details. Role/capability visibility is
evaluated from the server-provided identity model and backend response.

| Section | Route | Child routes | Audience and contract anchors |
|---|---|---|---|
| Home | `/admin` | Action Center, search handoff | All admin identities. `get_api_admin_smart_action_center` for Smart recommendations when available; otherwise compose authorized reads. |
| Sell | `/admin/sell` | `/orders`, `/orders/:orderId`, `/fulfillment`, `/returns`, `/customers/:customerId` | Support, warehouse, finance, owner/admin. Orders: `get_api_admin_orders`, `get_api_admin_orders_id`; fulfillment and returns operations in `FE_RETURNS_REFUNDS_WORKFLOW.md`. |
| Grow | `/admin/grow` | `/promotions/coupons`, `/reviews`, `/cms/pages`, `/cms/pages/:pageId`, `/analytics`, `/seo`, `/b2b/quotes` | Marketer, support/sales, owner/admin. Coupons: `get_api_admin_coupons`, `post_api_admin_coupons`, `post_api_admin_coupons_id_disable`; CMS operations are scope-bound. |
| Store | `/admin/store` | `/products`, `/products/:productId`, `/inventory`, `/customers`, `/b2b/companies`, `/finance/invoices`, `/finance/credit-memos`, `/settings/stores` | Owner/admin plus delegated warehouse/finance/support surfaces. Product and inventory IDs are listed in the capability matrix; do not imply unavailable list endpoints. |
| Health | `/admin/health` | `/reports`, `/analytics/events`, `/exports`, `/search`, `/operations`, `/developer` | Only render to the corresponding role/capability. Reports: `get_api_v2_admin_reports_overview`, `get_api_v2_admin_reports_advanced`, `get_api_v2_admin_reports_failures`; exports: `post_api_v2_admin_exports`, `get_api_v2_admin_exports_id`, `get_api_v2_admin_exports_id_download`, `post_api_v2_admin_exports_id_retry`. |

`Developer`, `Extensions`, `API`, `Webhooks`, `Jobs`, `Events`, `Trace`, and
`System` are not default top-level navigation. Put protected operational tools
under Health and expose them only to the exact authorized audience. Plugins may
mount only into an approved extension slot; they may not add a top-level item.

### Navigation visibility matrix

| Surface | Default audience | Hide when absent |
|---|---|---|
| Orders and customer support | `admin`, `owner`, `support`, `warehouse` as operation permits | Entire child item if no readable operation |
| Fulfillment/inventory | `admin`, `owner`, `warehouse` | Mutation controls independently; reads do not imply writes |
| Finance | `admin`, `owner`, `finance` | Payment, invoice, credit memo and legal-hold actions |
| Growth/CMS/analytics | `admin`, `owner`, `marketer` plus capability | CMS, analytics and SEO child items |
| Reports/exports | `admin`, `owner`, `finance` plus `reports`/`exports` | Report and export jobs |
| Search | Search audience plus `search`; reindex only `admin`, `owner` | Reindex/status controls separately |
| Developer/operations | `admin`, `owner` and exact backend operation gate | Entire protected tooling surface |

The role names above are navigation defaults, not a security model. The current
OpenAPI often reports empty capability metadata; use `routes/api.php` and the
capability matrix until generated authorization metadata is available.

## 5. Action Center

`/admin` ranks content in this order:

1. Urgent operations: failed/recoverable orders, blocked fulfillment, inventory risk, authorization or scope problems.
2. Important work: pending approvals, returns, CMS publish/review work, exports and unresolved conflicts.
3. Growth opportunities: conversion, reviews, analytics and catalog improvements.

Each card has one sentence of context, a status, an owner/audience, timestamp or
snapshot evidence where supplied, and a direct resolution action. Resolution is
one click from the card. Recommendations from
`get_api_admin_smart_action_center` are evidence-linked; writes through
`post_api_admin_smart_execute` or `post_api_admin_smart_rescue_id` always show
impact and confirmation. Never invent urgency from a missing metric.

No-data behavior is useful: show a quiet “No action required” state plus the
next common task, not empty chart placeholders. A failed Action Center read
does not block navigation; show retry and link to the underlying surface.

## 6. Route, Breadcrumb and URL Rules

- Collections: `/admin/{section}/{resource}`; detail: `/admin/{section}/{resource}/:id`.
- Nested workflow context is expressed in the breadcrumb, not duplicated in the URL when the API uses a shared resource ID.
- Examples: `/admin/sell/orders`, `/admin/sell/orders/:orderId`, `/admin/store/products/:productId`, `/admin/grow/cms/pages/:pageId`.
- Query parameters are for filters, sort, `page`, and `per_page` only. Use `page`/`per_page` with maximum 100, never offset.
- Preserve safe list filters when returning from a detail screen. Do not put cart tokens, sensitive finance data, or customer-owned identity data in URLs.
- Breadcrumbs: `Home / Section / Collection / Resource`. The current resource is plain text, not a link. On mobile, show back plus the current title and expose the full breadcrumb to screen readers.
- Route labels come from i18n and resource data. Never expose raw operation IDs as labels.

## 7. List and Detail Patterns

### Lists

Use: page heading, one primary create/action button, search, relevant filters,
result count/status, data table, pagination and a deliberate empty/error state.
Filters are progressive: show the two or three highest-value filters first and
put advanced filters behind “More filters”. Persist only safe list state.

Table defaults: selectable rows only when bulk actions are supported, stable
primary identifier, status text plus non-color cue, amount with currency,
updated time with timezone context, and a final actions column. Do not overload
rows with every API field. On mobile, promote identifier/status/primary amount;
put secondary metadata in the card expansion.

### Details

Use: breadcrumb, title/status, primary action, summary facts, focused task panel,
then tabs or disclosure sections for secondary information. Keep the primary
action visible at the top and at the end of a long form. Activity/audit evidence
is secondary and redacted. Do not recalculate order totals or finance values.

A detail route must distinguish not-found, forbidden, out-of-scope and unknown
state without probing another scope. On `409`, re-read, preserve the draft,
show the current version/state, and ask the user to decide.

## 8. Forms and Action Surfaces

- Forms start with the minimum required fields. Variants, tax, SEO, attributes,
  pricing rules, evidence and advanced settings are disclosures.
- Use inline edit for safe price/stock/status changes where the operation supports
  it; show saving/saved state and undo only for genuinely reversible changes.
- Use a drawer for a bounded secondary task that benefits from list context.
  Preserve the list underneath and return focus on close.
- Use a modal for confirmation, short forms, or a blocking conflict. Use a full
  page for complex creation/editing, CMS, reports, and multi-step workflows.
- Destructive dialogs name the object, action, impact, scope and recovery. No
  generic “Are you sure?” copy. Require an explicit confirmation control for
  publish, restore, refund, legal hold, anonymize, cancellation and external
  side effects.
- Field errors use `meta.errors`, preserve submitted values, focus the first
  invalid field, and never branch on human-readable `message`.

## 9. System States and Notifications

Every async region defines loading, empty, success, validation, `401`, `403`,
`404`, `409`, `422`, `429`, `500`, timeout/unknown outcome and retry behavior.

- Loading: skeleton matching the final structure; do not use a page-wide spinner for a local update.
- Empty: explain why, state the next action, and provide one primary action.
- Error: say what failed, whether customer data/action may be affected, what to do next, and show a redacted correlation ID for support where appropriate.
- Unknown mutation outcome: reconcile with the authoritative read before offering another side effect.
- Toasts confirm reversible or completed background work; they are not the only error channel. All text is translated.
- Inline banners are for page-affecting state; field errors stay beside fields; modal errors stay in the modal.
- Export jobs use `queued -> processing -> completed/failed/expired` and poll only the documented status operation.

## 10. Ownership Boundaries

| Concern | Admin UI owns | Backend/platform owns |
|---|---|---|
| Navigation | presentation, hiding unavailable items, route guards | authentication, capability, scope and object authorization |
| State labels | loading/empty/error presentation and safe unknown display | canonical resource state and legal transitions |
| Forms | progressive disclosure, field mapping, draft preservation | validation, business rules, totals, stock, pricing and side effects |
| Scope | selection UI and cache isolation | permitted scopes and scope enforcement |
| Retry | accessible affordances and bounded safe retry UX | idempotency, conflict semantics and external provider outcome |
| Diagnostics | operation ID/correlation ID and redacted support context | raw evidence, provider details, audit records and secrets |
