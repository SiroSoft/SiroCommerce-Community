# Enterprise Admin UI Workflows

Status: documentation-only implementation contract for parallel FE teams

## Contract Rules

- `backend/docs/openapi.json` is authoritative for request/response shapes and operation IDs. `backend/routes/api.php` is authoritative for route role middleware. `backend/docs/api-handoff/coverage-matrix.json` is the route-count evidence.
- The UI calls generated SDK operations by `operationId`; it never derives authorization or state transitions from URL text, labels, or human-readable messages.
- The shared envelope is `{success:true,message,data,meta}` or `{success:false,message,meta}`. Branch on HTTP status and `meta.error_code`; use `meta.errors` for field errors.
- Authenticated admin requests use the coordinator headers documented in `FE_HANDOFF.md`. Scoped commerce requests use `X-Store-Id`, `X-Channel-Id`, `X-Locale`, and `X-Currency` as one coherent `CommerceScope`.
- Use `page` and `per_page` (maximum 100), never offset pagination. Do not invent fields not present in OpenAPI schemas.
- A mutation timeout, disconnect, or lost response is `unknown_outcome`. Read an authoritative resource before offering another side effect. Reuse an idempotency key only for the same payload.

## Universal Failure And Recovery UX

This behavior applies to every screen below, with the listed read operation used for reconciliation where one exists.

| Condition | Required UI | Transport rule |
|---|---|---|
| `401` / `UNAUTHORIZED` | Clear protected data, preserve a safe return target, show sign-in state | One coordinated refresh/replay for an eligible safe request; never loop or replay an unknown mutation |
| `403` / `FORBIDDEN` | Accessible "You do not have access" state; do not reveal resource existence | No retry; record operationId and correlation ID only |
| `409` / `CONFLICT` | Preserve draft, show current resource/version/state after refetch, require explicit choice | Same key only with equivalent payload; never last-write-wins |
| `422` / `VALIDATION_ERROR` | Map `meta.errors` to controls, focus first invalid control, preserve values | No automatic retry |
| `429` | Throttled state with retry affordance/countdown | Honor `Retry-After`, bounded jittered retry, no fan-out |
| `500` / network failure | Generic failure and support correlation ID; keep safe draft | Reads may retry once; writes reconcile first |
| Unknown error code/state | Safe generic recovery panel and disabled destructive actions | No blind retry |

All screens also have loading, empty, success, and not-found states where the operation can return them. Error text never exposes SQL, provider details, tokens, raw evidence, or PII.

## Domain Workflows

### 1. Admin Home And Action Center

- **Screens and ownership:** `/admin` Home; `/admin/action-center` Action Center; `/admin/smart/*` is owned by the Admin Home team. API ownership is `get_api_admin_smart_action_center`, `get_api_admin_smart_conversion`, `get_api_admin_smart_inventory`, `get_api_admin_smart_search`, `get_api_admin_smart_setup_guide`, `post_api_admin_smart_execute`, and `post_api_admin_smart_rescue_id`.
- **Roles/capabilities:** `admin,owner`; no capability name is published for smart routes. Treat recommendations as evidence-linked and require confirmation before writes. Backend `403` wins.
- **Scope:** Home bootstrap is not marked `x-scope`; send active scope for admin commerce context and never display a scope-mixed result. Action Center must identify the active scope when returned by the contract, without inventing a scope field.
- **State machine:** `idle -> loading -> ready | empty | failed`; action `ready -> confirming -> submitting -> succeeded | conflict | unknown_outcome -> reconcile`. Rescue and execute actions are disabled for unknown recommendation/state values.
- **Idempotency:** Use the OpenAPI idempotency flag for execute/rescue. If the operation is not marked idempotent, do not promise replay safety; reconcile before retry.
- **Acceptance:** Keyboard focus reaches recommendation details and confirmation; mobile cards retain action context; stale/unknown recommendations cannot trigger a destructive action; correlation ID is available in recovery UI.
- **BLOCKED_BY_CONTRACT:** Recommendation payload/evidence shape, action enum, execution result semantics, and smart-route capability metadata are not complete. Do not invent KPI or action fields.

### 2. Catalog, Products, Variants, And Media

- **Screens and ownership:** `/admin/catalog/products` list/create; `/admin/catalog/products/:id` detail/edit; `/admin/catalog/products/:id/options`; `/admin/catalog/products/:id/variants`; `/admin/catalog/products/:id/media`; `/admin/catalog/import`; `/admin/catalog/assets`. API ownership: `get_api_admin_products`, `post_api_admin_products`, `put_api_admin_products_id`, `delete_api_admin_products_id`, `get_api_admin_products_id_options`, `post_api_admin_products_id_options`, `post_api_admin_products_product_options_option_values`, `get_api_admin_products_id_variants`, `post_api_admin_products_id_variants`, `post_api_admin_product_components`, `get_api_admin_products_id_media`, `post_api_admin_products_id_media`, `put_api_admin_products_id_media_order`, `delete_api_admin_products_product_media_media`, `post_api_admin_catalog_import`, `post_api_v2_admin_catalog_imports`, and `post_api_admin_digital_assets`.
- **Roles/capabilities:** Product routes are primarily `admin,owner`; product creation has `admin,owner,marketer` in the route file; digital assets require `products` capability in addition to route authorization. Exact operation middleware is binding.
- **Scope:** Product, options, variants, media, components, and catalog imports use active `store_id + channel_id + locale + currency`; current OpenAPI `x-scope` explicitly covers the listed product/media/options/variant/component/import operations. Never switch scope from an ID or slug.
- **State machine:** Read `loading -> ready | empty`; edit `clean -> dirty -> saving -> saved | validation_error | conflict`; delete/import/asset mutation `confirming -> submitting -> succeeded | unknown_outcome -> reconcile`. Unknown backend product/media state remains visible and non-destructive.
- **Idempotency:** Follow each OpenAPI operation's declared flag. Send a stable key for marked imports/assets/mutations; do not claim replay guarantees for optional or unmarked writes. On media order conflicts, refetch before applying a new order.
- **Acceptance:** Product forms preserve values after `422/409`; media reorder is keyboard operable and has a non-drag alternative; tables reflow at 320 CSS px/400% zoom; uploads never expose storage paths or provider errors; scoped cache keys include all four scope values.
- **BLOCKED_BY_CONTRACT:** Import progress/error row schema, digital-asset delivery behavior, complete product state/field semantics, media upload constraints, and some per-operation capability metadata are not a UI contract. Do not invent them.

### 3. Orders, Fulfillment, Returns, And Refunds

- **Screens and ownership:** `/admin/orders` list; `/admin/orders/:id` detail; `/admin/fulfillment` board/detail; `/admin/returns` queue/detail; `/admin/orders/:id/refund`; `/admin/finance/invoices/:id` and `/admin/finance/credit-memos/:id`. API ownership: `get_api_admin_orders`, `get_api_admin_orders_id`, `post_api_admin_orders_id_transition`, `post_api_admin_orders_id_refund`, `post_api_admin_fulfillment_allocate`, `post_api_admin_fulfillment_id_ship`, `post_api_admin_fulfillment_id_deliver`, `post_api_admin_fulfillment_id_cancel`, `get_api_admin_fulfillment_id_track`, `post_api_admin_returns`, `post_api_admin_returns_id_approve`, `post_api_admin_returns_id_label`, `post_api_admin_returns_id_inspect`, `post_api_admin_returns_id_approve_refund`, `post_api_admin_returns_id_reject`, `post_api_admin_returns_id_complete`, `post_api_v2_payments_id_capture`, `post_api_v2_payments_id_refund`, `post_api_v2_payments_id_void`, `post_api_admin_invoices`, `get_api_admin_invoices_id`, `get_api_admin_invoices_id_evidence`, `post_api_admin_invoices_id_issue`, `post_api_admin_invoices_id_credit_memos`, and `get_api_admin_credit_memos_id`.
- **Roles/capabilities:** Orders: `admin,owner,support,warehouse`; order transition/fulfillment/inventory: `admin,owner,warehouse`; return approve/reject: `admin,owner,support`; return inspect/complete: `admin,owner,warehouse`; approve-refund and finance evidence: `admin,owner,finance`; payment operations: `admin,owner`. Live payment/shipping provider certification is excluded by policy.
- **Scope:** Admin orders and order refund/transition are explicitly scoped in OpenAPI. Send active scope to all admin commerce requests; do not probe another scope after `403/404`.
- **State machine:** Order `pending -> awaiting_payment -> paid -> processing -> ready_to_ship -> shipped -> delivered`, with documented branches `failed`, `cancelled`, `refunded`, `partially_refunded`. Return UI `submitted -> approved -> label_pending/inspection_pending -> refund_pending -> completed | rejected`; refund `not_requested -> pending -> succeeded | partially_refunded | failed | unknown`. These return/refund states are display states unless returned by the API.
- **Idempotency:** Use a unique stable key only where the OpenAPI operation marks idempotency as required or supported; the current order-refund operation is optional and must not promise replay safety. Fulfillment writes follow OpenAPI; payment/shipment operations are provider-excluded and must still show pending/failed/unavailable/unknown. Reconcile with order, return, invoice, or credit-memo reads before retrying.
- **Acceptance:** Money is rendered from API money/minor-unit values; no client total recalculation. Destructive actions have confirmation, focus return, and textual status. Lost refund responses never render success without an authoritative read. Mobile detail keeps the current entity and action context together.
- **BLOCKED_BY_CONTRACT:** Complete return/fulfillment/refund/credit-memo list/read schemas, state enums, eligibility, refund amount rules, label/inspection evidence, and privacy-safe reconciliation fields are incomplete. Queue screens must stop at an unavailable state where no list/read operation exists.

### 4. Inventory And ATP

- **Screens and ownership:** `/admin/inventory` search/variant detail; `/admin/inventory/:variant` adjustment and priorities; `/admin/inventory/:variant/transfer`; ATP is an embedded read panel. API ownership: `get_api_admin_inventory_variant`, `put_api_admin_inventory_variant`, `put_api_admin_inventory_variant_priorities`, `post_api_admin_inventory_variant_transfer`, `post_api_v2_inventory_variant_atp`, and warehouse-related fulfillment operations above.
- **Roles/capabilities:** `admin,owner,warehouse`; ATP read is authenticated inventory read. No client role label grants access.
- **Scope:** Send active scope to admin inventory requests and keep warehouse/source context only if returned by the schema. Do not infer stock, ATP, reservations, or allocation from client state.
- **State machine:** `loading -> available | empty | unavailable`; adjustment/priority/transfer `editing -> confirming -> submitting -> applied | conflict | unknown_outcome -> authoritative read`; ATP unknown values remain unknown.
- **Idempotency:** Follow each operation's OpenAPI flag. Preserve the same key across response-loss reconciliation; never retry an adjustment with a new key merely because the response was lost.
- **Acceptance:** Adjustment reason and values use only schema-defined controls; conflict preserves draft; keyboard users can operate quantity/action controls; no stock status is conveyed by color alone; old-scope rows disappear after a scope switch.
- **BLOCKED_BY_CONTRACT:** ATP response detail, inventory state enum, source/warehouse list semantics, transfer terminal states, and universal polling/reconciliation endpoints are not fully published. No derived availability or ETA may be shown.

### 5. Customers And Support

- **Screens and ownership:** `/admin/customers/:id` detail; `/admin/customers/:id/addresses`; `/admin/reviews`; `/admin/search`; support order/return detail reuses order screens. API ownership: `get_api_admin_customers_id`, `get_api_admin_customers_id_addresses`, `post_api_admin_customers_id_addresses`, `get_api_admin_reviews`, `post_api_admin_reviews_id_moderate`, `get_api_v2_admin_search`, `get_api_v2_admin_search_status`, `post_api_admin_orders_id_downloads`, and `post_api_admin_customers_id_privacy_legal_hold`.
- **Roles/capabilities:** Customer detail/address and review moderation: `admin,owner,support` or `admin,owner,marketer` as route-mapped. Search: `admin,owner,support,warehouse,finance` plus `search`; reindex/status: `admin,owner` plus `search`. Legal hold: `admin,owner,finance` plus `finance`. Downloads are provider/storage excluded.
- **Scope:** Customer operations are not marked with the four-part scope in the current list; use only the operation contract and do not submit arbitrary customer identity. Search uses the active admin context where applicable.
- **State machine:** Detail `loading -> ready | not_found | forbidden`; address/review/legal-hold mutation `editing -> confirming -> submitting -> succeeded | validation_error | conflict | unknown_outcome -> reread`; search `idle -> loading -> results | empty | throttled | failed`.
- **Idempotency:** Follow OpenAPI. Legal hold requires an explicit reason/confirmation only if those fields are in schema; never fabricate a reason field. Reconcile any unknown privacy/legal hold mutation through a documented read where available.
- **Acceptance:** PII is masked/minimized and excluded from telemetry; icon-only moderation/legal actions have names; direct unauthorized routes render accessible `401/403`; search has pagination and no cross-scope cache leakage.
- **BLOCKED_BY_CONTRACT:** Customer list/search result field policy, support notes/ticket workflow, review moderation enum, legal-hold read/status, and download availability are incomplete. There is no contract for a support ticket queue; do not create one as an API-backed screen.

### 6. Promotions

- **Screens and ownership:** `/admin/promotions/coupons` list/create; `/admin/promotions/coupons/:id`; promotion allocation reconciliation is an order detail action. API ownership: `get_api_admin_coupons`, `post_api_admin_coupons`, `post_api_admin_coupons_id_disable`, `post_api_admin_orders_id_promotion_allocations_reconcile`, and public preview `post_api_promotions_preview` only where a storefront/admin preview use is contractually allowed.
- **Roles/capabilities:** Coupon routes: `admin,owner,marketer`; allocation reconciliation: `admin,owner,finance`. Preview is public/storefront and is not an admin authorization control.
- **Scope:** Coupon routes are not currently marked `x-scope`; use only returned contract context and do not assume a coupon applies across stores/channels. Order reconciliation uses the order's active scoped context.
- **State machine:** Coupon `loading -> ready | empty`; create/disable `draft -> confirming -> submitting -> active/disabled | validation_error | conflict | unknown_outcome -> reread`; reconciliation `idle -> submitting -> reconciled | conflict | unknown_outcome`.
- **Idempotency:** Use operation-declared idempotency. For optional/unmarked writes, show pending and reconcile rather than promise replay. Never show coupon codes in telemetry or export UI.
- **Acceptance:** Disabled state is read from the response, not inferred from button state; confirmation is accessible; order money/allocation evidence is read-only unless operation schema permits a mutation; `429` honors retry timing.
- **BLOCKED_BY_CONTRACT:** Coupon field/rule/eligibility schemas, lifecycle enum, usage reconciliation result, and scope/capability metadata are incomplete. Do not invent discount calculations or promotion analytics.

### 7. CMS And SEO

- **Screens and ownership:** `/admin/cms/pages`; `/admin/cms/pages/:id/edit`; `/admin/cms/pages/:id/blocks`; `/admin/cms/pages/:id/preview`; `/admin/cms/pages/:id/revisions`; `/admin/seo`. API ownership: `get_api_admin_pages`, `post_api_admin_pages`, `put_api_admin_pages_id`, `post_api_admin_pages_id_blocks`, `put_api_admin_blocks_id`, `get_api_admin_pages_id_preview`, `get_api_admin_cms_revisions_type_id`, `post_api_admin_pages_id_publish`, `post_api_admin_pages_id_restore_revision`, `get_api_pages_slug`, `get_api_admin_seo_health`, `get_api_admin_seo_type_id`, `put_api_admin_seo_type_id`, `get_api_admin_seo_type_id_schema`, and `post_api_admin_redirects`.
- **Roles/capabilities:** CMS routes `admin,owner,marketer` plus `cms`; SEO/redirects `admin,owner,marketer` per route mapping. Public page read is verification only.
- **Scope:** All CMS/page/revision operations use complete four-part active scope. A revision from another scope cannot be selected. SEO scope follows its operation contract; do not guess missing `x-scope` metadata.
- **State machine:** Editor `clean -> dirty -> saving -> saved`; preview `previewing -> shown`; publish/restore `confirming -> publishing/restoring -> published/restored | conflict | unknown_outcome -> reread`. Unknown backend page/revision values remain explicit.
- **Idempotency:** Publish and restore require `Idempotency-Key`; equivalent replay returns the original result. Save/block operations follow their declared OpenAPI flag. `409` opens reread plus diff/merge decision, never silent overwrite.
- **Acceptance:** Rich content is sanitized by reviewed allowlist; preview cannot publish; dialogs retain focus; unsaved drafts survive recoverable errors but are cleared on logout/scope change; mobile editing does not require drag-only controls.
- **BLOCKED_BY_CONTRACT:** Revision payload/state, diff/merge, autosave, media upload, scheduling, rollback depth, preview isolation, SEO validation, and cache invalidation are not fully specified. No scheduled publish or merge UI may be promised.

### 8. B2B

- **Screens and ownership:** `/admin/b2b/companies`; company detail members/approval scopes/catalogs/contracts/prices/credit; `/admin/b2b/quotes/:id`; `/admin/b2b/orders/:id/approval`. API ownership is all 21 IDs in the B2B mapping in `ADMIN_UI_SCREEN_SPECS.md`.
- **Roles/capabilities:** Owner/admin company management; finance/owner/admin credit; sales/support/owner/admin quote; company buyer/approver and sales/admin order approval. These are navigation defaults only; capability names are not consistently published.
- **Scope:** Company membership, quote, order, catalog, price, contract, and credit context is backend-owned. Never infer company scope from a URL ID or switch it in the browser. Send active CommerceScope where the operation requires it.
- **State machine:** Quote/order display `draft -> submitted -> pending_approval -> approved | rejected | converted`; credit `available | blocked | unavailable`; mutation `confirming -> submitting -> succeeded | conflict | unknown_outcome -> matching read`.
- **Idempotency:** Follow operation-level OpenAPI flags. Many admin B2B writes are optional; stable keys may be sent, but replay safety must not be claimed unless documented. Quote conversion/order approval reconciles before another side effect.
- **Acceptance:** Credit is audience-restricted and never logged; two approvers produce a recoverable `409`; unknown actions/states disable destructive controls; mobile drafts and approval dialogs are keyboard/screen-reader complete.
- **BLOCKED_BY_CONTRACT:** Company/member/catalog/contract/order list/read schemas, complete quote/order enums, `{action}` and `transition` allowed values, credit semantics, approval routing, expiry, price precedence, and conversion side effects are incomplete.

### 9. Analytics, Reports, And Exports

- **Screens and ownership:** `/admin/analytics/events`, `/admin/analytics/funnel`; `/admin/reports/overview`, `/admin/reports/advanced`, `/admin/reports/failures`; `/admin/exports`. API ownership: `get_api_admin_analytics_events`, `get_api_admin_analytics_export`, `get_api_admin_analytics_funnel`, `get_api_v2_admin_reports_overview`, `get_api_v2_admin_reports_advanced`, `get_api_v2_admin_reports_failures`, `post_api_v2_admin_exports`, `get_api_v2_admin_exports_id`, `get_api_v2_admin_exports_id_download`, `post_api_v2_admin_exports_id_retry`.
- **Roles/capabilities:** Analytics/funnel/events/export: `admin,owner,marketer` plus `analytics` where mapped. Reports/exports: `admin,owner,finance` plus `reports`/`exports`. OpenAPI is generated from the route capability variables and must remain parity-checked against this table.
- **Scope:** Analytics/report operations currently contract `store_id/channel_id` where declared; they do not uniformly declare the four CommerceScope headers. Do not add locale/currency headers until OpenAPI parity is published. Advanced reports allow documented dimensions only, inclusive `from/to`, maximum 366 days and documented source-row bounds. A `304` keeps the previous valid report.
- **State machine:** Read `loading -> ready | empty | unavailable | not_modified | failed`; export `idle -> submitting -> queued -> processing -> completed -> expired`, with `failed -> retrying -> queued`, plus forbidden/conflict/cancelled. Download is enabled only after completed status and an available contract result.
- **Idempotency:** Create/retry exports are optional-idempotency in OpenAPI; use a stable key where supported but do not promise replay semantics. Reconcile failed jobs before retry.
- **Acceptance:** Unsupported metrics/dimensions are `unavailable`, never zero; snapshot/schema/scope/window evidence is visible when returned; charts have table alternatives; 320 px/400% zoom and reduced motion preserve data; no storage paths, PII, raw evidence, or coupon codes are shown.
- **BLOCKED_BY_CONTRACT:** Export/report response schemas, export status/expiration/ownership, analytics export semantics, polling interval, and capability/scope metadata parity require backend clarification. Search/reindex progress is not an analytics substitute.

### 10. Health And Recovery

- **Screens and ownership:** `/admin/health` overview; `/admin/health/readiness`; `/admin/health/queue`; `/admin/health/payments`; `/admin/health/inventory`; `/admin/health/outbox`; `/admin/ops/observability`; `/admin/ops/metrics`; `/admin/ops/hooks`; `/admin/recovery`. API ownership: `get_health_live`, `get_health_ready`, `get_api_v2_health_queue`, `get_api_v2_health_payments`, `get_api_v2_health_inventory`, `get_api_v2_health_outbox`, `get_api_admin_ops_observability`, `get_api_admin_ops_readiness`, `get_api_admin_ops_metrics`, `get_api_admin_ops_hooks`, `get_api_admin_dev_doctor`, `get_api_admin_dev_trace_id`, and `post_api_admin_dev_recover`.
- **Roles/capabilities:** Public live/ready and public health metadata expose generic status only. Protected diagnostics/recovery are `admin,owner`; recovery must remain behind the exact route middleware. No UI may expose provider or infrastructure internals.
- **Scope:** Health is system-level unless an operation schema says otherwise. Do not attach arbitrary CommerceScope or present a system result as tenant health.
- **State machine:** `idle -> loading -> healthy | degraded | unavailable | unknown`; recovery `confirming -> submitting -> accepted | conflict | forbidden | unknown_outcome -> reread`. Poll only documented status reads, with bounded backoff and cancellation on route/logout.
- **Idempotency:** `post_api_admin_dev_recover` follows OpenAPI; unknown result requires a documented read before retry. Never retry recovery blindly.
- **Acceptance:** Health status is textual and not color-only; no raw trace/provider/SQL details; mobile status cards reflow; keyboard users can reach retry and support correlation controls; public and protected views are clearly separated.
- **BLOCKED_BY_CONTRACT:** Health payload severity/status enums, recovery action/result semantics, universal polling intervals, and tenant-vs-system scope are not uniformly defined. Render only documented values and an explicit unknown/unavailable state.

## Cross-Domain Release Gate

- Every screen has fixtures for loading, empty, `401`, `403`, `409`, `422`, `429`, `500`, unknown error/state, and unknown mutation outcome where applicable.
- Every mutation maps to an exact operation ID, role set, scope rule, idempotency rule, and authoritative reconciliation read or is marked `BLOCKED_BY_CONTRACT`.
- Responsive/accessibility evidence covers keyboard, NVDA/VoiceOver as applicable, 320 CSS px, 400% zoom, reduced motion, focus restoration, labeled errors, and no color-only status.
- Scope switching increments generation, invalidates scoped caches, aborts/ignores old requests, and never falls back to a default scope.
- Browser telemetry contains only allowlisted operation/status/error/correlation/scope-label fields; no tokens, raw IDs, PII, provider data, or response bodies.
