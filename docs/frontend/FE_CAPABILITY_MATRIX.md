# Frontend Capability Matrix

Status: `FE DESIGN BASELINE`

This document defines frontend authorization behavior. It does not replace
backend authorization. The backend remains authoritative for authentication,
role gates, capability gates, CommerceScope, object ownership, state
transitions, and idempotency.

## Contract Sources

- Operation IDs and request/response contracts: `backend/docs/openapi.json`.
- Exact method/path route records and middleware role gates:
  `backend/routes/api.php`.
- Route evidence and the canonical record count:
  `backend/docs/api-handoff/coverage-matrix.json`.
- Shared headers and stable error envelope:
  `backend/docs/api-handoff/FE_HANDOFF.md`.

The FE must call generated SDK operations by `operationId`. It must not derive
permission decisions from URL strings, human-readable messages, or hidden menu
items. A hidden action is a UX optimization, not a security control.

## Coverage Counts

These counts are checked against the regenerated v0.3.1 contract on 2026-11-27. The historical table below covers the last generated 186-operation slice; current source has more operations and the matrix mapping is incomplete. Rerun `composer handoff:matrix` and regenerate this section before release; do not hand-maintain counts.

| Measure | Count | Meaning |
|---|---:|---|
| Exact method/path route declarations | 469 | `backend/routes/api.php` (verified 2026-11-27) |
| OpenAPI paths | 406 | `backend/docs/openapi.json` |
| OpenAPI operations | 469 | One operation per method/path |
| Bearer-authenticated operations | 376 | OpenAPI `bearerAuth` security requirement |
| FE-guarded operations | 410 | OpenAPI `feSharedSecret` requirement |
| Explicit CommerceScope operations | 63 | OpenAPI `x-scope` marker |
| Webhook-signature operations | 7 | Server-to-server; excluded from browser FE |
| Operation IDs explicitly listed in this matrix | 186/469 | Historical slice; full mapping not yet regenerated |

Counts are contract evidence, not a claim that the browser UI is implemented.
Regenerate this section when OpenAPI or `routes/api.php` changes.

## UI Decision Rules

| Situation | UI behavior | Transport behavior |
|---|---|---|
| Role/capability absent | Hide navigation; direct route shows a deliberate forbidden state | Still send the request only when the user explicitly attempts a permitted UI action; never spoof a capability |
| `401` / `UNAUTHORIZED` | Clear local access state, preserve a safe return URL, redirect to login | One coordinated refresh attempt for an expired access token; never retry a failed login or refresh indefinitely |
| `403` / `FORBIDDEN` | Show “You do not have access”; do not reveal resource existence | Do not retry; record operation ID and correlation ID without PII |
| `409` / `CONFLICT` | Show recoverable conflict and refetch current resource | Reuse the same idempotency key only for the same payload |
| `422` / `VALIDATION_ERROR` | Map `meta.errors` to fields and focus the first invalid field | Do not branch on `message` |
| `429` | Show rate-limit state and retry affordance | Honor `Retry-After`; add jitter; do not fan out retries |
| `500` / `INTERNAL_ERROR` | Generic failure with support correlation ID | Do not expose provider, SQL, token, or infrastructure details |
| `TRUST_CONFIG_MISSING` | Service-unavailable state, not a login prompt | Do not retry rapidly; alert operations in non-user telemetry |

Every operation needs loading, empty, success, validation, unauthorized,
forbidden, conflict, throttled, and unexpected-error states where applicable.

## Headers And Mutation Rules

The SDK request policy supplies:

| Header | Browser rule |
|---|---|
| `Accept` | Always `application/vnd.siro.v1+json` |
| `Content-Type` | `application/json` for JSON writes |
| `Authorization` | `Bearer <access-token>` only for authenticated operations |
| `X-Siro-FE` | Supplied by trusted runtime configuration where FE guard is enabled; never expose the secret in analytics or error text |
| `X-Correlation-Id` | Generate for each user action/traceable request and preserve the response ID |
| `Idempotency-Key` | Required for OpenAPI-marked idempotent writes and checkout/payment mutations; export create/retry currently support an optional stable key and must not claim replay guarantees unless the operation declares them |
| `X-Cart-Token` | Only for guest cart, quote, and checkout calls; never put it in URLs, logs, cache keys visible to analytics, or telemetry payloads |
| `X-Store-Id`, `X-Channel-Id`, `X-Locale`, `X-Currency` | Required as a coherent CommerceScope set for operations marked `x-scope` |

The FE must use `page` and `per_page` (maximum 100), never offset pagination.

## Capability And Action Matrix

The following are the explicit capability gates in the current route records.
Role middleware remains an additional requirement.

| Capability | Roles in current route records | Exact operation IDs | UI actions |
|---|---|---|---|
| `cms` | `admin`, `owner`, `marketer` | `get_api_admin_pages`, `post_api_admin_pages`, `put_api_admin_pages_id`, `get_api_admin_pages_id_preview`, `post_api_admin_pages_id_blocks`, `put_api_admin_blocks_id`, `get_api_admin_cms_revisions_type_id`, `post_api_admin_pages_id_publish`, `post_api_admin_pages_id_restore_revision`, `post_api_v2_headless_pages` | Browse, create, edit, preview, block-edit, publish, restore |
| `analytics` | `admin`, `owner`, `marketer` | `get_api_admin_analytics_funnel`, `get_api_admin_analytics_events`, `get_api_admin_analytics_export` | View funnel/events and request analytics export |
| `reports` | `admin`, `owner`, `finance` | `get_api_v2_admin_reports_overview`, `get_api_v2_admin_reports_failures`, `get_api_v2_admin_reports_advanced` | View scoped reports and failure evidence |
| `exports` | `admin`, `owner`, `finance` | `post_api_v2_admin_exports`, `get_api_v2_admin_exports_id`, `get_api_v2_admin_exports_id_download`, `post_api_v2_admin_exports_id_retry` | Create, poll, retry, and download export jobs |
| `search` | `admin`, `owner`, `support`, `warehouse`, `finance` for search; `admin`, `owner` for reindex/status | `get_api_v2_admin_search`, `get_api_v2_admin_search_status`, `post_api_v2_admin_search_reindex` | Search; show status; reindex only to the narrower role set |
| `products` | `admin`, `owner` route roles plus capability on digital assets | `post_api_admin_digital_assets` | Create/manage digital asset action only when `products` is granted |
| `finance` | `admin`, `owner`, `finance` | `post_api_admin_customers_id_privacy_legal_hold` | Place/release legal hold only in finance surface |

Capabilities are not inferred from the role label in the browser. The FE uses
the authenticated identity payload and a server-provided capability model when
available, but must tolerate a backend `403` for stale or incomplete metadata.

## Role-To-OperationId Mapping

The role sets below are copied from the current `auth:<roles>` route middleware.
An operation may have additional capability or scope requirements. `owner` and
`admin` are not interchangeable with `finance`, `marketer`, `support`, or
`warehouse`.

| Current route role set | Operation IDs |
|---|---|
| `admin,owner` | `get_api_admin_stores`, `post_api_admin_stores`, `get_api_admin_stores_store_channels`, `get_api_admin_dev_doctor`, `get_api_admin_dev_trace_id`, `post_api_admin_dev_recover`, `get_api_admin_ops_observability`, `get_api_admin_ops_readiness`, `get_api_admin_ops_metrics`, `get_api_admin_ops_hooks`, `post_api_v2_b2b_orders_id_approve`, `post_api_v2_b2b_contracts_id_approve`, `post_api_v2_payments_id_capture`, `post_api_v2_payments_id_refund`, `post_api_v2_payments_id_void`, `post_api_admin_orders_id_refund` |
| `admin,owner,marketer` | `post_api_v2_admin_catalog_imports`, `get_api_admin_reviews`, `post_api_admin_reviews_id_moderate`, `get_api_admin_coupons`, `post_api_admin_coupons`, `post_api_admin_coupons_id_disable`, `get_api_admin_analytics_funnel`, `get_api_admin_analytics_events`, `get_api_admin_analytics_export` |
| `admin,owner,marketer` plus `cms` | `get_api_admin_pages`, `post_api_admin_pages`, `put_api_admin_pages_id`, `get_api_admin_pages_id_preview`, `post_api_admin_pages_id_blocks`, `put_api_admin_blocks_id`, `get_api_admin_cms_revisions_type_id`, `post_api_admin_pages_id_publish`, `post_api_admin_pages_id_restore_revision`, `post_api_v2_headless_pages` |
| `admin,owner,support,warehouse` | `get_api_admin_orders`, `get_api_admin_orders_id` |
| `admin,owner,support,warehouse` fulfillment/returns variants | `post_api_admin_returns`, `post_api_admin_returns_id_label` |
| `admin,owner,warehouse` | `post_api_admin_fulfillment_allocate`, `post_api_admin_fulfillment_id_ship`, `post_api_admin_fulfillment_id_deliver`, `post_api_admin_fulfillment_id_cancel`, `get_api_admin_fulfillment_id_track`, `post_api_admin_returns_id_complete`, `post_api_admin_returns_id_inspect`, `get_api_admin_inventory_variant`, `put_api_admin_inventory_variant`, `post_api_admin_inventory_variant_transfer`, `put_api_admin_inventory_variant_priorities`, `post_api_v2_shipments`, `get_api_v2_shipments_id_tracking`, `post_api_v2_shipments_id_cancel`, `post_api_admin_orders_id_transition` |
| `admin,owner,support` | `post_api_admin_returns_id_approve`, `post_api_admin_returns_id_reject`, `post_api_admin_orders_id_downloads`, `get_api_admin_customers_id`, `get_api_admin_customers_id_addresses`, `post_api_admin_customers_id_addresses` |
| `admin,owner,finance` | `post_api_admin_returns_id_approve_refund`, `post_api_admin_invoices`, `get_api_admin_invoices_id`, `get_api_admin_invoices_id_evidence`, `post_api_admin_invoices_id_issue`, `post_api_admin_invoices_id_credit_memos`, `get_api_admin_credit_memos_id`, `post_api_admin_orders_id_promotion_allocations_reconcile`, `post_api_admin_customers_id_privacy_legal_hold` |
| `admin,owner` product/catalog staff | `get_api_admin_products`, `post_api_admin_products`, `put_api_admin_products_id`, `delete_api_admin_products_id`, `get_api_admin_products_id_options`, `post_api_admin_products_id_options`, `post_api_admin_products_product_options_option_values`, `get_api_admin_products_id_media`, `post_api_admin_products_id_media`, `put_api_admin_products_id_media_order`, `delete_api_admin_products_product_media_media`, `get_api_admin_products_id_variants`, `post_api_admin_products_id_variants`, `post_api_admin_product_components`, `post_api_admin_catalog_import`, `post_api_admin_b2b_companies`, `post_api_admin_b2b_companies_id_members`, `post_api_admin_b2b_companies_id_approval_scopes`, `post_api_admin_b2b_companies_id_credit_account`, `get_api_admin_b2b_companies_id_credit`, `post_api_admin_b2b_companies_id_contracts`, `post_api_admin_b2b_companies_id_catalogs`, `post_api_admin_b2b_companies_id_catalogs_catalog_items`, `post_api_admin_b2b_companies_id_negotiated_prices` |
| authenticated customer | `get_api_auth_me`, `post_api_auth_logout`, `get_api_account_orders`, `get_api_account_orders_id`, `get_api_account_returns`, `get_api_account_returns_id`, `get_api_account_addresses`, `get_api_account_notification_preferences`, `put_api_account_notification_preferences`, `get_api_account_loyalty`, `post_api_account_loyalty_order_earn`, `post_api_account_loyalty_redeem`, `post_api_account_loyalty_expire`, `post_api_account_subscriptions`, `post_api_account_subscriptions_id_transition`, `post_api_account_subscriptions_id_renew`, `get_api_account_privacy_export`, `post_api_account_privacy_anonymize`, `post_api_account_consents`, `get_api_wishlist`, `post_api_wishlist`, `delete_api_wishlist_product`, `post_api_products_id_reviews` |
| authenticated B2B user | `post_api_v2_b2b_companies_id_quotes`, `get_api_v2_b2b_quotes_id`, `post_api_v2_b2b_quotes_id_action`, `post_api_v2_b2b_quotes_id_convert`, `post_api_v2_b2b_orders_id_transition`, `post_api_v2_b2b_orders_id_draft`, `post_api_v2_b2b_orders_id_submit`, `post_api_v2_b2b_orders_id_approve_company`, `post_api_v2_b2b_orders_id_reject`, `get_api_v2_b2b_companies_id_credit` |

The product/catalog row is intentionally labeled as a route family: the route
records currently use `$staff` (`admin,owner`) for most operations, while
`post_api_admin_products` and some catalog operations have narrower or
additional middleware in the route file. The FE must validate the exact
operation metadata from the generated client before enabling each action.

## Remaining Exact Classifications

These operation IDs complete the historical 186-operation slice and are not role rows above. The full current inventory is 469 operations; rerun the handoff gate to regenerate the mapping:

| Classification | Exact operation IDs | UI rule |
|---|---|---|
| `admin,owner` store/channel administration | `post_api_admin_channels` | Show only to store administration users; direct denial is `FORBIDDEN` |
| `admin,owner,marketer` growth | `post_api_admin_redirects`, `get_api_admin_seo_health`, `get_api_admin_seo_type_id`, `put_api_admin_seo_type_id`, `get_api_admin_seo_type_id_schema` | Show SEO surfaces only to current growth roles |
| `admin,owner` smart/developer | `get_api_admin_smart_action_center`, `get_api_admin_smart_conversion`, `post_api_admin_smart_execute`, `get_api_admin_smart_inventory`, `post_api_admin_smart_rescue_id`, `get_api_admin_smart_search`, `get_api_admin_smart_setup_guide` | Treat recommendations as evidence-linked actions; require confirmation for writes |
| public/storefront writes | `post_api_analytics_track`, `post_api_promotions_preview` | Consent/rate-limit aware; never include raw PII or secrets |
| public catalog | `get_api_v2_catalog_brands`, `get_api_v2_catalog_categories` | Use active public CommerceScope where the contract marks it; no admin controls |
| authenticated inventory read | `post_api_v2_inventory_variant_atp` | Render only returned availability; do not infer stock from client state |
| public/system health and metadata | `get_api_version`, `get_health_live`, `get_health_ready`, `get_api_v2_health_queue`, `get_api_v2_health_payments`, `get_api_v2_health_inventory`, `get_api_v2_health_outbox`, `get_robots_txt`, `get_sitemap_xml` | Public status UI shows generic state; detailed admin diagnostics use the protected operation IDs |
| public headless read | `post_api_headless_graphql` | Read-only allowlisted headless client; enforce documented query limits and never expose admin controls |

The provider health operation is classified here for route mapping but remains
excluded from live-provider FE completion as described below.

## Storefront And Customer Actions

Guest/public operations use no customer identity: `get_api_products`,
`get_api_products_slug`, `get_api_pages_slug`, `get_api_menus_key`,
`get_api_products_id_reviews`, `post_api_carts`, `post_api_carts_id_lines`,
`get_api_carts_id_quote`, `post_api_v2_checkout_quote`,
`post_api_checkout_place`, `get_api_orders_id`, `get_api_orders_id_returns`,
`post_api_v2_shipping_rates`, `get_api_v2_products_id_bundle_quote`, and
`get_api_v2_downloads_token`.

`post_api_auth_register`, `post_api_auth_login`, and
`post_api_auth_refresh` are authentication operations, not capability-bearing
application actions. They must use the auth throttling and session rules in
`FE_SECURITY_PRIVACY.md`.

## Provider Exclusion

The following are live-provider or server-to-server concerns and are excluded
from FE completion and browser certification:

- `post_api_webhooks_provider`, `post_api_webhooks_shipping_provider`,
  `post_api_webhooks_shipping_dhl`.
- `post_api_v2_payments_id_capture`, `post_api_v2_payments_id_refund`,
  `post_api_v2_payments_id_void`.
- `post_api_v2_shipments`, `get_api_v2_shipments_id_tracking`,
  `post_api_v2_shipments_id_cancel`, `post_api_v2_shipping_rates`.
- `get_api_v2_health_payments`, `get_api_v2_downloads_token`, and
  `post_api_admin_orders_id_downloads` where provider/storage availability is
  external to the browser.

The FE must still implement pending, failed, unavailable, expired, and retry
states from the documented API responses. “Excluded” means no provider
certification is counted as FE completion; it does not mean the UI may omit
safe state handling.

## Acceptance Tests

- A user with no capability cannot see the related navigation or invoke the UI action; a direct route receives and renders `FORBIDDEN`.
- A stale role/capability model cannot grant access when the backend returns `403`.
- Every mutation sends the documented `Idempotency-Key` and retries only an equivalent payload.
- A `401` from an expired access token produces one coordinated refresh, then one replay; concurrent requests do not each refresh.
- A `409` refetches the current resource and preserves the user input where safe.
- `422` field errors are mapped from `meta.errors`; no test asserts on `message`.
- A cross-scope resource identifier renders the stable scope/forbidden/not-found contract without leaking whether another scope contains it.
- Provider-dependent screens pass pending, failed, unavailable, timeout, and retry fixtures without requiring a live provider.
- Automated contract coverage must assert the full current route/operation inventory (469 declarations/operations as of 2026-11-27). The historical 186/186 figure is stale and is not release evidence until the handoff gate is rerun.
