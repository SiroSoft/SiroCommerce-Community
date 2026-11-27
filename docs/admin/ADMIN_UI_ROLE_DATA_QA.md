# Admin UI Role, Data and QA Handoff

Status: `DOCUMENTATION-ONLY HANDOFF`

This is the parallel-development contract for the Admin UI. It does not grant
permissions and does not replace backend authorization. The backend remains
authoritative for authentication, roles, capabilities, CommerceScope, object
ownership, transitions, idempotency and error codes.

## Evidence Boundary

| Evidence | Source | Current meaning |
|---|---|---|
| BE contract | `backend/docs/openapi.json`, `backend/public/openapi.json`, `backend/generated/sirocommerce.ts` | Exact operation IDs, schemas, security and scope metadata. Regenerate; never hand-edit the SDK. |
| BE route evidence | `backend/docs/api-handoff/coverage-matrix.json` | Last checked-in handoff snapshot: 468 route records with Unit, Integration, API/Component, Contract and Workflow evidence; `910/2340` layer records PASS, readiness `NOT_READY_BLOCKED`. Rerun `composer handoff:matrix` before quoting evidence. |
| FE design rules | `docs/frontend/FE_CAPABILITY_MATRIX.md`, `docs/frontend/FE_SCOPE_SWITCHING.md`, `docs/frontend/FE_SECURITY_PRIVACY.md` | Required browser behavior, not proof that a screen exists. |
| FE package evidence | `apps/admin/package.json`, `apps/storefront/package.json`, `packages/*` | Admin starter and storefront baseline are present; shared generated-client integration and browser release evidence remain workstream deliverables. |
| FE release evidence | `docs/frontend/FE_RELEASE_EVIDENCE.md` | Browser, a11y, responsive, performance and release artifacts are `NOT RUN` until controlled jobs exist. |
| Provider evidence | `docs/frontend/FE_CAPABILITY_MATRIX.md` provider exclusion | Live provider certification is `SKIP`, not PASS. Contract fixtures and safe unavailable states remain mandatory. |

Do not report a BE PASS as a FE PASS. Every screen evidence row must name its
role, complete scope, fixture, operation ID, expected status and artifact.

## Role and Capability Matrix

Navigation is an optimization. The server response and a backend `403` remain
authoritative. The identity/capability model may hide actions, but it may never
enable an action solely from a role label or URL.

| Admin surface | Roles / capability | Operation IDs | Scope |
|---|---|---|---|
| Commerce shell and stores | `admin,owner` | `get_api_admin_stores`, `post_api_admin_stores`, `get_api_admin_stores_store_channels`, `post_api_admin_channels` | Store administration; use active scope where contract marks it |
| Catalog products | `admin,owner`; digital assets additionally `products` | `get_api_admin_products`, `post_api_admin_products`, `put_api_admin_products_id`, `delete_api_admin_products_id`, `get_api_admin_products_id_options`, `post_api_admin_products_id_options`, `post_api_admin_products_product_options_option_values`, `get_api_admin_products_id_media`, `post_api_admin_products_id_media`, `put_api_admin_products_id_media_order`, `delete_api_admin_products_product_media_media`, `get_api_admin_products_id_variants`, `post_api_admin_products_id_variants`, `post_api_admin_product_components`, `post_api_admin_digital_assets` | Catalog operations are scope-qualified when `x-scope` is present |
| Catalog import | `admin,owner,marketer` | `post_api_admin_catalog_import`, `post_api_v2_admin_catalog_imports` | Active scope; import progress is eventually consistent |
| Orders | `admin,owner,support,warehouse` for reads; `admin,owner,warehouse` for transition; `admin,owner` for refund | `get_api_admin_orders`, `get_api_admin_orders_id`, `post_api_admin_orders_id_transition`, `post_api_admin_orders_id_refund`, `post_api_admin_orders_id_promotion_allocations_reconcile` | `get_api_admin_orders`, `get_api_admin_orders_id`, `post_api_admin_orders_id_transition` and refund are scope-sensitive per contract/service |
| Inventory and fulfillment | `admin,owner,warehouse` | `get_api_admin_inventory_variant`, `put_api_admin_inventory_variant`, `put_api_admin_inventory_variant_priorities`, `post_api_admin_inventory_variant_transfer`, `post_api_admin_fulfillment_allocate`, `post_api_admin_fulfillment_id_ship`, `post_api_admin_fulfillment_id_deliver`, `post_api_admin_fulfillment_id_cancel`, `get_api_admin_fulfillment_id_track` | Scope at request dispatch; never infer ATP from cached client state |
| Returns | Reads/label `admin,owner,support,warehouse`; approve/reject `admin,owner,support`; refund `admin,owner,finance`; complete/inspect `admin,owner,warehouse` | `post_api_admin_returns`, `post_api_admin_returns_id_label`, `post_api_admin_returns_id_approve`, `post_api_admin_returns_id_reject`, `post_api_admin_returns_id_approve_refund`, `post_api_admin_returns_id_complete`, `post_api_admin_returns_id_inspect` | Same active scope as order context |
| Finance | `admin,owner,finance` | `post_api_admin_invoices`, `get_api_admin_invoices_id`, `get_api_admin_invoices_id_evidence`, `post_api_admin_invoices_id_issue`, `post_api_admin_invoices_id_credit_memos`, `get_api_admin_credit_memos_id`, `post_api_admin_customers_id_privacy_legal_hold` | Scope-sensitive finance reads/writes; destructive actions require confirmation and audit reason. Order refund remains `admin,owner` per its route middleware. |
| Promotions and reviews | `admin,owner,marketer` | `get_api_admin_coupons`, `post_api_admin_coupons`, `post_api_admin_coupons_id_disable`, `get_api_admin_reviews`, `post_api_admin_reviews_id_moderate` | Scope where operation metadata requires it |
| CMS and SEO | `admin,owner,marketer` plus `cms` for CMS actions | `get_api_admin_pages`, `post_api_admin_pages`, `put_api_admin_pages_id`, `get_api_admin_pages_id_preview`, `post_api_admin_pages_id_blocks`, `put_api_admin_blocks_id`, `get_api_admin_cms_revisions_type_id`, `post_api_admin_pages_id_publish`, `post_api_admin_pages_id_restore_revision`, `post_api_v2_headless_pages`, `post_api_admin_redirects`, `get_api_admin_seo_health`, `get_api_admin_seo_type_id`, `put_api_admin_seo_type_id`, `get_api_admin_seo_type_id_schema` | All CMS/headless operations use the complete four-part scope |
| Analytics and reporting | `admin,owner,marketer` plus `analytics`; reports `admin,owner,finance` plus `reports` | `get_api_admin_analytics_funnel`, `get_api_admin_analytics_events`, `get_api_admin_analytics_export`, `get_api_v2_admin_reports_overview`, `get_api_v2_admin_reports_failures`, `get_api_v2_admin_reports_advanced` | Scope plus bounded date/filter parameters |
| Exports | `admin,owner,finance` plus `exports` | `post_api_v2_admin_exports`, `get_api_v2_admin_exports_id`, `get_api_v2_admin_exports_id_download`, `post_api_v2_admin_exports_id_retry` | Scope where advertised; download/provider availability is `SKIP` for live certification |
| Search | Search: `admin,owner,support,warehouse,finance` plus `search`; reindex/status: `admin,owner` | `get_api_v2_admin_search`, `get_api_v2_admin_search_status`, `post_api_v2_admin_search_reindex` | Scope/filter values are explicit; search is eventually consistent |
| B2B | Product/catalog staff `admin,owner`; approval actions `admin,owner`; authenticated B2B users for customer flow | `post_api_admin_b2b_companies`, `post_api_admin_b2b_companies_id_members`, `post_api_admin_b2b_companies_id_approval_scopes`, `post_api_admin_b2b_companies_id_credit_account`, `get_api_admin_b2b_companies_id_credit`, `post_api_admin_b2b_companies_id_contracts`, `post_api_admin_b2b_companies_id_catalogs`, `post_api_admin_b2b_companies_id_catalogs_catalog_items`, `post_api_admin_b2b_companies_id_negotiated_prices`, `post_api_v2_b2b_orders_id_approve`, `post_api_v2_b2b_contracts_id_approve` | Company ownership and scope are backend checks |
| Platform and diagnostics | `admin,owner` for admin operations; public health is not an admin grant | `get_api_admin_dev_doctor`, `post_api_admin_dev_recover`, `get_api_admin_dev_trace_id`, `get_api_admin_ops_observability`, `get_api_admin_ops_readiness`, `get_api_admin_ops_metrics`, `get_api_admin_ops_hooks`, `get_api_admin_smart_action_center`, `get_api_admin_smart_conversion`, `post_api_admin_smart_execute`, `get_api_admin_smart_inventory`, `post_api_admin_smart_rescue_id`, `get_api_admin_smart_search`, `get_api_admin_smart_setup_guide` | Redact provider and infrastructure details; writes require explicit confirmation |

The exact operation-ID classification remains `docs/frontend/FE_CAPABILITY_MATRIX.md`. Current source has 468 route declarations/OpenAPI operations; the matrix's 186-operation mapping is incomplete. If this summary and generated metadata differ, stop and update the handoff rather than inventing a browser rule.

## Data and Query Ownership

| Owner module | Owns | Must not own |
|---|---|---|
| `platform/session` | Access token memory, single-flight refresh, logout, identity and capability snapshot | Authorization decisions from decoded JWT, tokens in persistent storage or telemetry |
| `platform/scope` | `activeScope`, available server-provided scopes, generation and switch transaction | Scope chosen from URL, resource ID, cached data or arbitrary input |
| `platform/api` | Generated SDK adapter, headers, correlation IDs, idempotency, envelope/error normalization and cancellation | Direct `fetch`/Axios, URL-derived permissions, hand-written response types |
| `platform/query` | Operation-ID query keys, status/loading/error state, deduplication and invalidation | Global response-body persistence, unscoped keys, cross-scope merging |
| `domains/catalog` | Product/options/variants/media/import query and mutation ownership | Session, scope or generic transport policy |
| `domains/orders` | Order, transition, refund, fulfillment and return queries/mutations | Payment/shipping provider certification or client-side state transitions |
| `domains/growth` | CMS, SEO, analytics, reports, search and export feature queries | Capability enforcement or provider secrets |
| `domains/finance` | Invoices, evidence, credit memos, legal hold and finance confirmations | Raw payment data, downloadable provider tokens or audit bypasses |
| `ui/primitives` | Tables, forms, dialogs, status, pagination, focus and error presentation | Domain API calls or role-specific policy |

Every query key has this prefix:

```text
environment + operationId + store_id + channel_id + locale + currency + parameters
```

Mutations invalidate only the same scope namespace. Lists use `page` and
`per_page` (maximum 100), never offsets. Scope generation is checked when a
response arrives; stale responses are aborted or ignored. A scope switch clears
or namespaces drafts, lists, details, dashboards and polling before target data
renders.

## Scope Rules

The canonical context is `store_id + channel_id + locale + currency`, sent as
`X-Store-Id`, `X-Channel-Id`, `X-Locale`, and `X-Currency`. Use headers, not
equivalent query parameters. Never send both forms with different values. Do
not issue a scoped request while any value is missing, and never fall back to a
default.

The current OpenAPI explicitly marks 38 `x-scope` operations. The transport
should send the active scope to all admin commerce requests, while the known
metadata gap remains open: backend-enforced scope families may exceed the 38
markers. Add a contract assertion for scope metadata parity before calling
cache isolation complete.

## Generated Client Boundary

1. `backend/docs/openapi.json` is the schema source; `backend/generated/sirocommerce.ts` is generated output.
2. Domain code calls generated methods by exact `operationId`; it does not construct URL strings or duplicate DTOs.
3. One request coordinator wraps the generated client for auth, FE guard, correlation, scope, idempotency, cart-token and redaction policy.
4. No page or domain module may call `fetch`, Axios, or the generated client directly outside `platform/api`.
5. Regenerate with `composer generate:openapi` and `composer generate:sdk`; verify with `composer check:api-contract` and `composer check:api-handoff`.
6. A client operation absent from OpenAPI, or an operation ID not present in the generated SDK, is a contract blocker.

## Fixture Matrix

| Fixture | Required behavior | Evidence class |
|---|---|---|
| `admin-owner-scope-a`, `admin-owner-scope-b` | Same IDs remain isolated across scopes; switch discards old responses | FE contract/component/e2e; BE scope evidence is separate |
| `role-capability-denied` | Hidden nav plus direct route `403` without resource disclosure | FE component/e2e; backend authorization remains authoritative |
| `expired-access-single-flight` | One refresh, safe replay once, concurrent requests share lock | FE contract/e2e |
| `validation-envelope` | `422` and `meta.errors` map to fields; message is not parsed | FE contract/component |
| `conflict-equivalent-key` | `409` refetches and preserves safe input; different payload never reuses key | FE contract/e2e |
| `throttled-retry-after` | `429` honors `Retry-After`, bounded jitter, no fan-out | FE contract/component |
| `unexpected-redacted` | `500`/`503` gives generic accessible state and correlation ID only | FE security/component |
| `empty-paginated` | Empty state, bounded pagination and no offset behavior | FE component/contract |
| `provider-pending-failed-unavailable-expired` | Payment, shipping, downloads and webhook-adjacent screens render safe states and retry rules | FE fixture evidence; live provider `SKIP` |
| `cms-untrusted-content` | Sanitized rich content, no script or unsafe navigation | FE security/e2e |

## QA and Release Gates

| Gate | Required assertion | Current status |
|---|---|---|
| Contract | 468 operation IDs, envelope/error codes, pagination, headers, idempotency and scope match OpenAPI | BE source inventory 468; handoff snapshot 468 records, `NOT_READY_BLOCKED`; FE NOT RUN |
| Unit/component | Loading, empty, success, 401, 403, 404, 409, 422, 429, 500/503, focus and redaction fixtures | FE NOT RUN; package only exposes Vitest |
| E2E/workflow | Role routes, scope switching, catalog/order/inventory, CMS/report/export and recovery journeys | FE NOT RUN; storefront exposes Playwright suites and admin exposes `test:e2e`, but the full role/scope matrix is not evidenced |
| Accessibility | Axe 0 critical/serious, keyboard order/no traps, 320px/400% zoom, labels, announcements and dialogs | FE NOT RUN; WCAG 2.2 AA gate |
| Performance | Lighthouse/RUM/CWV, bundle sizes, API readiness and 10k/50k/100k pagination proof | FE NOT RUN; provider-independent lab evidence only |
| Security/privacy | No token/cart token/PII artifacts; refresh/CSRF/CSP deployment controls reviewed | FE NOT RUN; refresh-cookie and CSRF contract gaps remain |
| Provider | Live provider calls and certification | `SKIP` by policy; fixture states are mandatory |
| Release evidence | Trace key `<release>-<screen>-<operationId>-<acceptanceId>-<artifactType>` and immutable artifacts | Block release until complete |

## Definition of Done

- Every admin screen maps to exact generated operation IDs and a role/capability row.
- No action is enabled by hidden navigation, URL parsing or decoded JWT claims.
- All scoped reads and writes carry the complete active scope and isolated keys.
- Mutation idempotency, conflict recovery and stable error-code handling are tested.
- No direct transport bypasses the generated-client boundary.
- Deterministic fixtures cover success and applicable failure/provider states.
- Typecheck, unit/component, contract and i18n gates pass before merge.
- E2E, keyboard/screen-reader, responsive, performance and security evidence passes before release.
- Every `SKIP`, `NOT RUN` or exception has a reason, owner, issue and expiry; live-provider `SKIP` never becomes a release claim.
