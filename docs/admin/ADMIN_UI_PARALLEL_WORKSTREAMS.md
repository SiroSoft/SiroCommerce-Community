# Admin UI Parallel Workstreams

> LEGACY (planning snapshot; gate/evidence rows may predate current code). Current route inventory: 468 declarations; checked-in handoff snapshot 468 routes. See [docs/README.md](../README.md).

Status: `4-DEV IMPLEMENTATION HANDOFF`

This split allows parallel work without creating competing transport, scope,
permission or cache implementations. `apps/admin` is based on the Siro Admin
Next starter and `apps/storefront` is the storefront baseline; Workstream 0
still owns the shared generated-client integration and is a dependency for all
domain slices.

## Four-Dev Ownership

| Developer | Primary ownership | First milestone |
|---|---|---|
| Admin Dev 1 | Admin foundation, Siro Admin Starter audit, shared primitives, session/auth, CommerceScope, query/cache/error coordinator, catalog | Admin shell + login/session/scope + real product list/create/edit |
| Admin Dev 2 | Merchant operations, Home/Action Center, orders, transitions, fulfillment and inventory | Login -> Action Center -> order detail -> process -> fulfillment -> inventory update |
| Storefront Dev 1 | Storefront design system, layout, discovery, SEO, accessibility and performance | Home -> category/search -> product detail |
| Storefront Dev 2 | Purchase journey, cart, quote, checkout, payment states, order result and later account | Product -> cart -> quote -> checkout -> order result |

Admin Dev 1 owns the shared platform boundary. Admin Dev 2 and both storefront
developers consume it; they must not create a second transport, session, scope,
query-key or error-normalization layer. Storefront Dev 1 owns shared storefront
visual primitives; Storefront Dev 2 consumes them for purchase flows.

## Admin Starter Audit

No Siro Admin Starter source is currently present in this workspace. Admin Dev 1
must audit the selected starter before creating a shell using this decision:

- `REUSE`: primitive or shell matches accessibility, responsive, auth and scope
  contracts without behavior forks.
- `ADAPT`: visual/layout integration is needed, but the primitive can remain
  shared and domain-neutral.
- `COMMERCE-ONLY`: catalog, CommerceScope, capability, reconciliation and
  commerce state behavior must be implemented in this repository.

The audit result and starter commit/version belong in the first foundation PR.

## Team Milestone 1

The first team gate is two real vertical journeys, not a screen count:

```text
Customer: Home -> Category/Search -> Product -> Cart -> Quote -> Checkout
          -> Place Order -> Order Result

Merchant: Login -> Home/Action Center -> New Order -> Order Detail -> Process
          -> Fulfillment -> Inventory updated
```

Both journeys must use the generated SDK and backend, be responsive, and cover
loading, empty, success, `401`, `403`, `409`, `422`, `429`, `500` and
`unknown_outcome` where applicable. Returns/refunds/B2B/CMS advanced/BI and
GraphQL UI remain follow-up slices until their contract gaps are closed.

## Proposed Ownership

| Workstream | Owner boundary | Primary operation families | Depends on |
|---|---|---|---|
| 0. Platform foundation | `packages/{api-client,auth,scope,config}` | All operations through generated SDK | Generated SDK and OpenAPI |
| 1. Shared admin shell | `apps/admin/src/{shell,navigation,primitives}` | `get_api_auth_me`, `get_api_admin_stores`, `get_api_admin_stores_store_channels` | 0 |
| 2. Catalog | `apps/admin/src/domains/catalog` | Product/options/variants/media/component/import IDs in `ADMIN_UI_ROLE_DATA_QA.md` | 0, 1, shared table/form/dialog |
| 3. Orders and fulfillment | `apps/admin/src/domains/{orders,fulfillment}` | Orders, transitions, returns, fulfillment and tracking IDs | 0, 1 |
| 4. Inventory and B2B | `apps/admin/src/domains/{inventory,b2b}` | Inventory/ATP, transfers, B2B company/catalog/credit/approval IDs | 0, 1 |
| 5. Finance and exports | `apps/admin/src/domains/finance` | Invoice, evidence, credit memo, refund, legal hold and export IDs | 0, 1; provider fixtures; owns export jobs |
| 6. CMS and growth | `apps/admin/src/domains/growth` | CMS, SEO, redirects, analytics, reports and search IDs; consumes export results | 0, 1, 5 for export result integration |
| 7. QA and release evidence | `apps/{admin,storefront}/src/qa`, fixtures and artifact store | Cross-domain operation traceability and acceptance IDs | 0 plus each vertical slice |

An owner may add files only inside the listed boundary or through a reviewed
shared primitive change. No workstream owns a second session coordinator,
scope store, query-key scheme, generated DTO set or error normalizer.

## Dependency Graph

```text
OpenAPI + generated SDK
          |
          v
   0 platform foundation
       /       \
      v         v
  1 admin shell  7 QA fixture harness
   / |  |  |  \
  v  v  v  v   v
  2  3  4  5   6 domain slices
   \ |  |  |  /
      v v v v
   7 contract/e2e/a11y/perf evidence
              |
              v
          release gate
```

Workstream 0 must land the client boundary, normalized errors, scope
generation, query-key contract and mutation policy before domain branches create
data hooks. Workstream 1 can build shell layout against deterministic identity
and scope fixtures while 0 is being integrated, but must not invent transport.
Workstream 7 can create fixture schemas and test naming conventions in parallel;
it cannot mark browser gates PASS before executable jobs and artifacts exist.

## Shared Primitives Contract

The shared library is intentionally small and domain-neutral:

- `AdminShell`, `ScopeSwitcher`, protected route and forbidden state.
- `DataTable`, `Pagination`, `FilterBar`, `EmptyState`, `Skeleton` and retry state.
- `FormField`, `ValidationSummary`, `ConfirmDialog`, `StatusBadge`, `Toast` and correlation display.
- Accessible `Modal`, `Drawer`, `Tabs`, `Combobox`, date range and live-region announcements.
- Money, locale, timezone and date formatting using the existing finance/i18n rules.
- `ApiErrorView` keyed by status and `meta.error_code`, never human-readable message.

Primitives accept data and callbacks. They do not import domain operation IDs or
make requests. A primitive change requires a focused unit/component test and an
accessibility check at 320px and desktop widths.

## Folder and Module Rules

```text
apps/admin/src/
  shell/          navigation, primitives and admin route composition
  domains/        catalog, orders, fulfillment, inventory, b2b, finance, growth
apps/storefront/src/
  discovery/      home, category, search, product
  purchase/       cart, quote, checkout, order result
packages/
  api-client/     generated SDK adapter and request coordinator
  auth/           session and refresh coordination
  scope/          CommerceScope and generation switching
  ui/             domain-neutral accessible primitives
  config/         runtime environment configuration
apps/*/src/fixtures/ deterministic envelopes, identities, scopes, provider states
apps/*/src/qa/      trace IDs, evidence helpers, route/operation manifests
apps/*/src/locales/ en, vi, ja, de, zh
apps/*/src/types/   boundary-safe shared types only
```

Domain modules expose route components and query/mutation functions named after
operation IDs. They may not add global state, alter `platform/query`, or edit a
different domain's fixtures. Locale files are shared-owned; add keys without
rewriting another locale's unrelated content.

## Data and Cache Ownership Matrix

| Data | Query owner | Key namespace | Invalidation |
|---|---|---|---|
| Identity/capabilities | `platform/session` | `environment + get_api_auth_me` | Logout, refresh revocation, role change |
| Available/active scope | `platform/scope` | Session/tab state, not response cache | Explicit switch; clear all scoped data and drafts |
| Products/options/variants/media | `domains/catalog` | Operation ID + full scope + filters/page | Product/media/option mutation in same scope only |
| Orders/returns/fulfillment | `domains/orders`, `domains/fulfillment` | Operation ID + full scope + resource/filter | Transition/refund/return invalidates matching detail and lists |
| Inventory/ATP/transfers | `domains/inventory` | Operation ID + full scope + variant/source | Inventory mutation invalidates variant, ATP and affected list |
| CMS/reports/analytics/search | `domains/growth` | Operation ID + full scope + bounded filters | Publish/report/search mutation invalidates only target namespace |
| Finance/export jobs | `domains/finance` | Operation ID + scope + job/resource ID | Poll only while authorized and active; stop on logout/scope switch |

The same resource ID in two scopes is never the same cache entry. Do not persist
protected response bodies in localStorage, IndexedDB, sessionStorage or URL
state. Cart tokens are not part of Admin UI state and must never enter logs or
analytics.

## Workstream Checklists

### 0. Platform Foundation

- Import only the generated SDK and expose one request coordinator.
- Apply `Accept`, auth, FE guard, correlation, complete scope and documented idempotency headers.
- Implement refresh single-flight, one safe replay, logout broadcast and redaction.
- Normalize status plus `meta.error_code`; support 401/403/404/409/422/429/500/503.
- Enforce operation-ID query keys, scope generation and cancellation/ignore rules.
- Add contract tests for SDK parity, envelope, headers, pagination and scope.

### 1. Admin Shell

- Load server-provided identity/capabilities and permitted scopes.
- Hide unavailable navigation but render deliberate direct-route `401`/`403` states.
- Make scope switching explicit, complete and accessible; do not render scoped data before bootstrap.
- Keep top-level structure `Home -> Sell -> Grow -> Store -> Health` unless capability permits otherwise.

### 2-6. Domain Slices

- Register every screen and mutation with exact operation IDs before implementation.
- Use only platform query/mutation adapters and shared primitives.
- Include loading, empty, success, validation, forbidden, conflict, throttled and unexpected states.
- Add success, cross-scope, stale-role, validation, conflict and provider-state fixtures as applicable.
- Document eventual consistency for search, analytics and export polling.
- Mark live provider certification `SKIP`; never remove unavailable or retry UI.

### 7. QA and Release

- Maintain the screen matrix: role, route, viewport, locale, currency, timezone, flag, fixture, operation IDs and expected statuses.
- Use trace key `<release>-<screen>-<operationId>-<acceptanceId>-<artifactType>`.
- Run contract, unit/component, E2E, axe/manual a11y, responsive, performance and security jobs.
- Keep `NOT RUN`, `SKIP` and exceptions explicit with owner, issue, reason and expiry.

## PR Integration Rules

1. A PR changes one workstream boundary unless it is a reviewed shared primitive or platform change.
2. PR descriptions list affected operation IDs, roles, scope behavior, cache keys, fixtures, acceptance IDs and evidence status.
3. Generated OpenAPI/SDK changes are separate reviewable artifacts; never hand-edit `backend/generated/sirocommerce.ts`.
4. A domain PR must rebase on the current platform contract and must not modify another domain's query keys or fixture names.
5. Shared primitive changes require consumers to keep existing semantics and include a focused regression test.
6. Merges require typecheck, unit/component, i18n, contract and lint gates. A flaky failure is not silently retried into a pass.
7. E2E, accessibility and performance gates are release gates, not a reason to claim a domain PR has browser certification.
8. Never commit credentials, provider fixtures containing secrets, tokens, cart tokens, raw PII or downloaded provider artifacts.

## Conflict Avoidance

- Platform files are owned by Workstream 0; domain branches consume them through stable interfaces.
- `packages/api-client` and `packages/scope` own shared query-key and scope contracts; migrate the storefront baseline once by Workstream 0, not independently by every domain.
- Fixture IDs are namespaced by domain, for example `catalog/product-list/scope-a` and `orders/detail/conflict`; do not reuse generic IDs.
- Additive locale keys are preferred; avoid broad formatting or locale-file rewrites.
- Route and operation manifests are generated or centrally reviewed; do not maintain per-branch URL lists.
- If OpenAPI, route middleware and capability docs disagree, stop the affected slice and file a contract-gap issue. Do not resolve by weakening UI or backend checks.

## Gate Matrix

| Gate | Merge requirement | Release requirement | Evidence now |
|---|---|---|---|
| BE contract/handoff | Backend parity remains green | Same release artifact hashes | Source inventory: 468 route declarations; checked-in handoff snapshot 468 records, readiness `NOT_READY_BLOCKED` |
| FE contract | Operation IDs, envelopes, headers, scope and cache tests | Complete screen-operation matrix | NOT RUN |
| Unit/component | Changed slice plus shared primitive tests | Full suite, no unexplained flakes | Package has Vitest only; NOT RUN for Admin UI |
| E2E | Slice smoke may be added with controlled runner | Role, scope, mutation recovery and critical journeys | NOT RUN; package has no Playwright script |
| A11y | Changed screens scanned | Axe/manual WCAG 2.2 AA, keyboard, screen reader, reflow | NOT RUN |
| Performance | No unmeasured blocking request regressions | Lighthouse/RUM/CWV and bundle/API budgets | NOT RUN |
| Security/privacy | Redaction and auth fixtures | Refresh/CSRF/CSP/CORS/deployment review | NOT RUN; contract gaps open |
| Live providers | No live call required | Certification remains `SKIP` with owner/reason; deterministic states pass | `SKIP` by policy |

## Definition of Done

- The workstream owns a bounded folder and exact operation-ID inventory.
- It consumes the shared generated-client, session, scope, cache and error boundaries.
- Role/capability and scope behavior is tested for both hidden UI and backend denial.
- Cache keys and invalidation are complete-scope and operation-ID qualified.
- Fixture, contract, component and applicable E2E evidence is traceable.
- Shared QA gates pass; release-only gates are not mislabeled as complete.
- No live-provider `SKIP`, unresolved contract gap or missing FE artifact is hidden.
- The integration PR leaves application behavior isolated from unrelated workstreams and contains no application-code changes outside the assigned boundary.

## Unresolved Blockers

- Shared packages still need the generated SDK integration and request coordinator.
- Playwright/browser matrix, Lighthouse, RUM and release evidence commands are not yet wired into the monorepo root.
- Browser refresh storage and CSRF contract are unresolved for production hardening.
- OpenAPI does not expose complete role/capability metadata for every operation.
- OpenAPI `x-scope` covers 63 operations in current source (2026-11-22) while backend enforcement may cover more.
- Live payment, shipping, download and webhook/provider certification is intentionally `SKIP` and requires fixture-only acceptance.
