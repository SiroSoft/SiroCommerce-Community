/* eslint-disable */
/** GENERATED FILE. Run `php scripts/generate-openapi.php && php scripts/generate-sdk.php`. */
export type ApiVersion = "v1" | "v2";
export type QueryValue = string | number | boolean | null | undefined;
export interface ApiError { success: false; message: string; meta: { timestamp: string; error_code?: string; errors?: Record<string, string[]>; [key: string]: unknown }; }
export interface ApiEnvelope<T> { success: true; message: string; data: T; meta: Record<string, unknown>; }
export interface PaginatedApiEnvelope<T> { success: true; message: string; data: T[]; meta: PaginationMeta; }
export interface PaginationMeta { page: number; per_page: number; total: number; last_page: number; timestamp?: string; }
export interface ApiRequestOptions<TBody = unknown> { token?: string; headers?: Record<string, string>; query?: Record<string, QueryValue>; path?: Record<string, string | number>; body?: TBody; idempotencyKey?: string; signal?: AbortSignal; }
export interface SiroCommerceClientOptions { baseUrl: string; fetch?: typeof fetch; defaultHeaders?: Record<string, string>; }
export interface SuccessEnvelope {
  success: boolean;
  message: string;
  data: UnknownData;
  meta: Record<string, unknown>;
}
export interface PaginatedEnvelope {
  success: boolean;
  message: string;
  data: Array<UnknownData>;
  meta: PaginationMeta;
}
export interface GraphQlRequest {
  query: string;
  variables?: Record<string, unknown>;
  extensions?: Record<string, unknown>;
}
export interface ProductMediaUpload {
  file: string;
  alt?: string;
  title?: string;
  role?: string;
  sort?: number;
}
export interface ProductCsvImportUpload {
  file: string;
  csv?: string;
}
export interface ProductCsvImportRowError {
  row: number;
  error: string;
}
export interface ProductCsvImportResult {
  created: number;
  updated: number;
  skipped: number;
  errors: Array<ProductCsvImportRowError>;
  dry_run: boolean;
  rows_seen: number;
}
export interface ReturnLineRequest {
  order_line_id: string;
  qty: number;
  reason?: string;
  disposition?: string;
  condition?: string;
}
export interface InventoryItem {
  variant_id: string;
  source_id: string;
  on_hand: number;
  reserved: number;
  available?: number;
  low_threshold?: number;
}
export interface InventoryTransfer {
  id: string;
  variant_id: string;
  from_source_id: string;
  to_source_id: string;
  qty: number;
  state: string;
  idempotency_key?: string;
}
export interface CommerceScope {
  tenant_id?: string;
  store_id: string;
  channel_id: string;
  locale: string;
  currency: string;
}
export interface DeliveryMethod {
  code: string;
  label: string;
  base_cost_minor: number;
  currency: string;
  rule?: Record<string, unknown>;
}
export interface PaymentAvailability {
  method: string;
  available: boolean;
  status: string;
  reason?: string;
}
export interface EventEnvelope {
  event_id: string;
  name: string;
  occurred_at: string;
  correlation_id: string;
  causation_id: string;
  actor: string;
  payload: Record<string, unknown>;
  schema_version: number;
  redaction: Array<string>;
}
export interface ProductType {
  type: string;
}
export interface CatalogTag {
  slug: string;
  count: number;
}
export interface Money {
  amount_minor: number;
  currency: string;
}
export interface CatalogFacetCount {
  value: string;
  count: number;
}
export interface CatalogFacets {
  category?: Array<CatalogFacetCount>;
  brand?: Array<CatalogFacetCount>;
  type?: Array<CatalogFacetCount>;
  tag?: Array<CatalogFacetCount>;
  price?: Record<string, unknown>;
}
export interface WriteResult {
  id?: string;
  state?: string;
  status?: string;
  message?: string;
  ok?: boolean;
  queued?: boolean;
  count?: number;
  created_at?: string;
}
export interface HeadlessSchemaEnvelope {
  data: Record<string, unknown>;
}
export interface AiSuggestionEnvelope {
  success: boolean;
  message: string;
  data: Record<string, unknown>;
  meta: Record<string, unknown>;
}
export interface ReportingContractEnvelope {
  success: boolean;
  message: string;
  data: Record<string, unknown>;
  meta: Record<string, unknown>;
}
export interface DashboardBiSalesDay {
  date: string;
  gross_minor: number;
  orders: number;
}
export interface DashboardBiSalesBlock {
  currency: string;
  gross_minor: number;
  orders: number;
  aov_minor: number;
  refunds_minor: number;
  by_day: Array<DashboardBiSalesDay>;
}
export interface DashboardBiFunnel {
  sessions: number;
  product_views: number;
  add_to_cart: number;
  checkout_started: number;
  orders: number;
}
export interface DashboardBiTopProduct {
  product_id: string;
  name: string;
  slug: string;
  qty: number;
  revenue_minor: number;
}
export interface DashboardBiLowStock {
  variant_id: string;
  name: string;
  sku: string;
  available: number;
}
export interface DashboardBiOps {
  failed_payments: number;
  pending_refunds: number;
  queue_dead_letters: number;
  open_ops_alerts: number;
}
export interface DashboardBi {
  range: string;
  window: Record<string, unknown>;
  sales: DashboardBiSales;
  sales_by_currency?: Array<DashboardBiSalesBlock>;
  funnel: DashboardBiFunnel;
  top_products: Array<DashboardBiTopProduct>;
  low_stock: Array<DashboardBiLowStock>;
  ops: DashboardBiOps;
  generated_at: string;
}
export interface LaunchCheck {
  key: string;
  state: string;
  hint: string;
  href: string;
  weight: number;
  detail: string;
}
export interface LaunchStatus {
  ready: boolean;
  score: number;
  checks: Array<LaunchCheck>;
}
export interface ProviderStatus {
  payment: Record<string, unknown>;
  shipping: Record<string, unknown>;
  mail: Record<string, unknown>;
  search: Record<string, unknown>;
  ai: Record<string, unknown>;
}
export interface ProviderVerificationCheck {
  name: string;
  type: string;
  configured: boolean;
  reachable: boolean;
  detail: string;
}
export interface ProviderVerification {
  providers: Array<ProviderVerificationCheck>;
  certified: boolean;
}
export interface LaunchSeedCounts {
  theme: number;
  categories: number;
  products: number;
  variants: number;
  inventory: number;
  prices: number;
  pages: number;
  menus: number;
  menu_items: number;
}
export interface LaunchSeedResult {
  created: LaunchSeedCounts;
  skipped: LaunchSeedCounts;
}
export interface ProviderWebhookResult {
  event_id?: string;
  status?: string;
}
export interface Error {
  success: boolean;
  message: string;
  meta: Record<string, unknown>;
}
export interface GraphQlError {
  code: string;
  message: string;
}
export interface Request_get_health_live {
}
export interface Request_get_health_ready {
}
export interface Request_get_health_providers {
}
export interface Request_get_api_v2_health_queue {
}
export interface Request_get_api_v2_health_payments {
}
export interface Request_get_api_v2_health_inventory {
}
export interface Request_get_api_v2_health_outbox {
}
export interface Request_get_metrics {
}
export interface Request_get_api_v2_admin_reports_overview {
}
export interface Request_get_api_v2_admin_reports_contract {
}
export interface Request_get_api_v2_admin_reports_failures {
}
export interface Request_get_api_v2_admin_reports_advanced {
}
export interface Request_get_api_v2_admin_dashboard_bi {
}
export interface Request_get_api_v2_admin_search {
}
export interface Request_get_api_v2_admin_search_status {
}
export interface Request_post_api_v2_admin_search_reindex {
  type?: string;
}
export interface Request_post_api_v2_admin_ai_suggestions {
  task: string;
  input: Record<string, unknown>;
  max_cost_minor: number;
}
export interface Request_post_api_v2_admin_ai_copilot_tasks {
  task: string;
  input: Record<string, unknown>;
  product_id?: string;
  max_cost_minor?: number;
}
export interface Request_get_api_v2_admin_ai_copilot_queue {
}
export interface Request_post_api_v2_admin_ai_copilot_queue_id_approve {
}
export interface Request_post_api_v2_admin_ai_copilot_queue_id_reject {
}
export interface Request_post_api_v2_admin_exports {
  dataset: string;
  format: string;
  from?: string;
  to?: string;
  store_id?: string;
  channel_id?: string;
}
export interface Request_get_api_v2_admin_exports_id {
}
export interface Request_get_api_v2_admin_exports_id_download {
}
export interface Request_post_api_v2_admin_exports_id_retry {
}
export interface Request_post_api_v2_admin_catalog_imports {
  format: string;
  source: string;
  validate_only?: boolean;
}
export interface Request_get_api_v2_admin_workbench {
}
export interface Request_post_api_v2_admin_workbench_views {
  name: string;
  resource: string;
  filters: Record<string, unknown>;
  columns?: Array<string>;
}
export interface Request_delete_api_v2_admin_workbench_views_id {
}
export interface Request_put_api_v2_admin_workbench_permissions_role {
  permissions: Array<string>;
}
export interface Request_post_api_v2_admin_workbench_workflows {
  name: string;
  resource: string;
  action: string;
  steps: Array<string>;
}
export interface Request_post_api_v2_admin_workbench_approvals {
  workflow_id: string;
  resource_id: string;
  payload?: Record<string, unknown>;
}
export interface Request_post_api_v2_admin_workbench_approvals_id_decision {
  decision: string;
}
export interface Request_post_api_v2_admin_workbench_bulk_orders {
  ids: Array<string>;
  to: string;
  reason?: string;
}
export interface Request_get_api_v2_admin_catalog_exports {
}
export interface Request_post_api_v2_admin_products_id_approval {
  approval_status: string;
  version?: number;
}
export interface Request_get_api_v2_admin_products_id_versions {
}
export interface Request_get_api_products {
}
export interface Request_get_api_vendors_slug {
}
export interface Request_post_api_products_id_stock_alerts {
  variant_id?: string;
  email?: string;
  type?: string;
  target_price_minor?: number;
}
export interface Request_post_api_products_stock_alerts_unsubscribe {
  token: string;
}
export interface Request_get_api_admin_stock_alerts {
}
export interface Request_get_api_admin_features {
}
export interface Request_get_api_admin_ops_alerts {
}
export interface Request_post_api_admin_ops_alerts_kind_ack {
}
export interface Request_get_api_search_suggest {
}
export interface Request_get_api_products_id_affinities {
}
export interface Request_get_api_products_id_reviews_summary {
}
export interface Request_get_api_products_slug {
}
export interface Request_get_api_storefront_context {
}
export interface Request_get_api_storefront_config {
}
export interface Request_get_api_storefront_theme_presets {
}
export interface Request_get_api_pages_slug {
}
export interface Request_get_api_menus_key {
}
export interface Request_get_api_redirects_resolve {
}
export interface Request_post_api_headless_graphql {
}
export interface Request_get_api_headless_schema {
}
export interface Request_get_api_products_id_reviews {
}
export interface Request_get_api_wishlist {
}
export interface Request_post_api_wishlist {
  product_id: string;
  variant_id?: string;
}
export interface Request_delete_api_wishlist_product {
}
export interface Request_post_api_products_id_reviews {
  order_id: string;
  order_line_id: string;
  rating: number;
  title?: string;
  body: string;
}
export interface Request_post_api_carts {
  currency?: string;
}
export interface Request_get_api_carts_id {
}
export interface Request_post_api_carts_id_lines {
  variant_id: string;
  qty: number;
}
export interface Request_patch_api_carts_id_lines_line {
  qty: number;
}
export interface Request_delete_api_carts_id_lines_line {
}
export interface Request_get_api_carts_id_quote {
}
export interface Request_post_api_checkout_place {
  cart_id: string;
  guest_email?: string;
  coupon_code?: string;
  gift_code?: string;
  gift_codes?: Array<string>;
  fulfillment_method?: string;
  pickup_point_code?: string;
}
export interface Request_get_api_orders_id {
}
export interface Request_get_api_orders_id_timeline {
}
export interface Request_get_api_orders_id_returns {
}
export interface Request_post_api_webhooks_provider {
  event_id?: string;
}
export interface Request_post_api_admin_fulfillment_allocate {
  order_id: string;
  lines: Array<Record<string, unknown>>;
}
export interface Request_post_api_admin_fulfillment_id_ship {
  lines?: Array<Record<string, unknown>>;
}
export interface Request_post_api_admin_fulfillment_id_deliver {
}
export interface Request_post_api_admin_fulfillment_id_ready {
  pickup_point_code?: string;
}
export interface Request_post_api_admin_fulfillment_id_collect {
}
export interface Request_get_api_pickup_points {
}
export interface Request_post_api_admin_pickup_points {
  code: string;
  name: string;
  store_id?: string;
  source_id?: string;
  address_line?: string;
  city?: string;
  sla_hours?: number;
}
export interface Request_put_api_admin_pickup_points_code {
  name?: string;
  store_id?: string;
  source_id?: string;
  address_line?: string;
  city?: string;
  sla_hours?: number;
  state?: string;
}
export interface Request_post_api_admin_fulfillment_id_cancel {
}
export interface Request_get_api_admin_fulfillment_id_track {
}
export interface Request_post_api_admin_returns {
  order_id: string;
  fulfillment_id: string;
  lines: Array<ReturnLineRequest>;
  reason?: string;
}
export interface Request_post_api_admin_returns_id_complete {
}
export interface Request_post_api_admin_returns_id_approve {
}
export interface Request_post_api_admin_returns_id_inspect {
  disposition: string;
  note?: string;
}
export interface Request_post_api_admin_returns_id_approve_refund {
}
export interface Request_post_api_admin_returns_id_reject {
  note?: string;
}
export interface Request_post_api_admin_returns_id_label {
  shipping: Record<string, unknown>;
}
export interface Request_post_api_v2_checkout_quote {
  cart_id: string;
  coupon?: string;
}
export interface Request_get_api_v2_products_id_bundle_quote {
}
export interface Request_get_api_v2_downloads_token {
}
export interface Request_post_api_storefront_shipping_estimate {
  variant_id: string;
  qty?: number;
  destination: Record<string, unknown>;
}
export interface Request_post_api_v2_shipping_rates {
  origin: Record<string, unknown>;
  destination: Record<string, unknown>;
  packages: Array<Record<string, unknown>>;
  provider?: string;
}
export interface Request_post_api_v2_shipments {
  shipment: Record<string, unknown>;
}
export interface Request_get_api_v2_shipments_id_tracking {
}
export interface Request_post_api_v2_shipments_id_cancel {
}
export interface Request_post_api_webhooks_shipping_dhl {
  event_id: string;
  status: string;
}
export interface Request_post_api_webhooks_shipping_provider {
  event_id: string;
  status: string;
}
export interface Request_post_api_v2_payments_id_capture {
  amount_minor: number;
  currency?: string;
}
export interface Request_post_api_v2_payments_id_refund {
  amount_minor: number;
  currency?: string;
}
export interface Request_post_api_v2_payments_id_void {
  amount_minor?: number;
  currency?: string;
}
export interface Request_post_api_admin_invoices {
  order_id: string;
  payment_id: string;
}
export interface Request_get_api_admin_invoices_id {
}
export interface Request_get_api_admin_invoices_id_evidence {
}
export interface Request_post_api_admin_invoices_id_issue {
}
export interface Request_post_api_admin_invoices_id_credit_memos {
  amount_minor: number;
  reason?: string;
}
export interface Request_post_api_admin_orders_id_promotion_allocations_reconcile {
  allocations: Array<Record<string, unknown>>;
}
export interface Request_get_api_admin_credit_memos_id {
}
export interface Request_get_api_v2_catalog_brands {
}
export interface Request_get_api_v2_catalog_categories {
}
export interface Request_get_api_v2_catalog_tags {
}
export interface Request_get_api_v2_catalog_product_types {
}
export interface Request_get_api_admin_stores {
}
export interface Request_post_api_admin_stores {
  code: string;
  name: string;
  status?: string;
}
export interface Request_get_api_admin_stores_store_channels {
}
export interface Request_post_api_admin_channels {
  store_id: string;
  code: string;
  name: string;
  status?: string;
}
export interface Request_get_api_version {
}
export interface Request_post_api_auth_forgot_password {
  email: string;
}
export interface Request_post_api_auth_reset_password {
  token: string;
  password: string;
}
export interface Request_post_api_auth_verify_email {
  token: string;
}
export interface Request_post_api_auth_verify_email_resend {
}
export interface Request_post_api_auth_register {
  name: string;
  email: string;
  password: string;
  role?: string;
}
export interface Request_post_api_auth_login {
  email: string;
  password: string;
}
export interface Request_post_api_auth_refresh {
  refresh: string;
}
export interface Request_get_api_auth_me {
}
export interface Request_put_api_auth_me {
  name?: string;
  email?: string;
}
export interface Request_post_api_auth_logout {
}
export interface Request_get_api_account_orders {
}
export interface Request_get_api_account_orders_id {
}
export interface Request_get_api_account_orders_id_timeline {
}
export interface Request_get_api_account_orders_id_financial_documents {
}
export interface Request_get_api_account_financial_documents_type_id {
}
export interface Request_get_api_account_returns {
}
export interface Request_get_api_account_returns_id {
}
export interface Request_post_api_account_orders_id_returns {
  fulfillment_id: string;
  lines: Array<ReturnLineRequest>;
  reason?: string;
}
export interface Request_get_api_account_orders_id_notifications {
}
export interface Request_get_api_account_message_history {
}
export interface Request_get_api_account_addresses {
}
export interface Request_post_api_account_addresses {
  label?: string;
  line1: string;
  city: string;
  phone: string;
}
export interface Request_get_api_account_notification_preferences {
}
export interface Request_put_api_account_notification_preferences {
  transactional_email?: boolean;
  marketing_email?: boolean;
}
export interface Request_get_api_account_loyalty {
}
export interface Request_post_api_account_loyalty_order_earn {
}
export interface Request_post_api_account_loyalty_redeem {
  points: number;
}
export interface Request_post_api_account_loyalty_expire {
  before: string;
}
export interface Request_get_api_account_subscriptions {
}
export interface Request_get_api_account_subscriptions_id {
}
export interface Request_post_api_account_subscriptions {
  plan_id: string;
}
export interface Request_post_api_account_subscriptions_id_transition {
  action: string;
}
export interface Request_post_api_account_subscriptions_id_renew {
}
export interface Request_get_api_account_privacy_export {
}
export interface Request_post_api_account_privacy_anonymize {
}
export interface Request_post_api_account_consents {
  purpose: string;
  version: string;
  source: string;
  granted: boolean;
}
export interface Request_get_api_consent_policy {
}
export interface Request_post_api_consent {
  necessary: boolean;
  analytics: boolean;
  marketing: boolean;
  policy_version: string;
  session_id?: string;
}
export interface Request_post_api_admin_customers_id_privacy_legal_hold {
  held: boolean;
}
export interface Request_get_api_admin_launch_status {
}
export interface Request_post_api_admin_launch_seed_demo {
}
export interface Request_get_api_admin_providers_status {
}
export interface Request_get_api_admin_providers_verify {
}
export interface Request_get_api_dashboard_stats {
}
export interface Request_get_api_users {
}
export interface Request_post_api_users {
  name: string;
  email: string;
  role?: string;
}
export interface Request_get_api_users_id {
}
export interface Request_put_api_users_id {
  name?: string;
  email?: string;
  role?: string;
}
export interface Request_delete_api_users_id {
}
export interface Request_get_api_profile {
}
export interface Request_put_api_profile {
  name?: string;
  phone?: string;
}
export interface Request_put_api_profile_password {
  current_password?: string;
  password: string;
}
export interface Request_get_api_server_info {
}
export interface Request_get_api_settings {
}
export interface Request_put_api_settings {
  settings?: Record<string, unknown>;
}
export interface Request_get_api_admin_consent_summary {
}
export interface Request_get_api_admin_configuration {
}
export interface Request_get_api_admin_configuration_group {
}
export interface Request_get_api_admin_configuration_group_section {
}
export interface Request_put_api_admin_configuration_group_section {
  values: Record<string, unknown>;
  expected_version?: number;
}
export interface Request_get_api_configuration_public {
}
export interface Request_get_api_admin_search_seo_url_rewrites {
}
export interface Request_post_api_admin_search_seo_url_rewrites {
  request_path: string;
  target_path: string;
  redirect_type?: number;
  store_id?: string;
  channel_id?: string;
}
export interface Request_put_api_admin_search_seo_url_rewrites_id {
  request_path?: string;
  target_path?: string;
  redirect_type?: number;
}
export interface Request_delete_api_admin_search_seo_url_rewrites_id {
}
export interface Request_get_api_admin_search_seo_search_terms {
}
export interface Request_get_api_admin_search_seo_synonyms {
}
export interface Request_post_api_admin_search_seo_synonyms {
  term: string;
  synonyms: Array<string>;
  store_id?: string;
  channel_id?: string;
}
export interface Request_put_api_admin_search_seo_synonyms_id {
  term?: string;
  synonyms?: Array<string>;
}
export interface Request_delete_api_admin_search_seo_synonyms_id {
}
export interface Request_get_api_admin_search_seo_sitemaps {
}
export interface Request_put_api_admin_search_seo_sitemaps {
  products?: Array<unknown>;
  categories?: Array<unknown>;
  cms_pages?: Array<unknown>;
  brands?: Array<unknown>;
  images_enabled?: boolean;
}
export interface Request_post_api_admin_search_seo_sitemaps_generate {
}
export interface Request_get_api_v2_admin_campaign_automation {
}
export interface Request_put_api_v2_admin_campaign_automation {
  enabled?: boolean;
  max_messages_per_day?: number;
  quiet_hours_start?: number;
  quiet_hours_end?: number;
  allowed_channels?: Array<string>;
}
export interface Request_post_api_upload {
  file?: string;
}
export interface Request_post_api_upload_avatar {
  file?: string;
}
export interface Request_post_api_products {
  name: string;
  slug?: string;
  type?: string;
}
export interface Request_put_api_products_id {
  name?: string;
  slug?: string;
  status?: string;
}
export interface Request_delete_api_products_id {
}
export interface Request_get_api_orders {
}
export interface Request_get_api_categories {
}
export interface Request_post_api_categories {
  name: string;
  slug?: string;
}
export interface Request_get_api_categories_id {
}
export interface Request_put_api_categories_id {
  name?: string;
  slug?: string;
}
export interface Request_delete_api_categories_id {
}
export interface Request_get_api_tags {
}
export interface Request_post_api_tags {
  name: string;
  slug?: string;
}
export interface Request_get_api_tags_id {
}
export interface Request_put_api_tags_id {
  name?: string;
  slug?: string;
}
export interface Request_delete_api_tags_id {
}
export interface Request_get_api_posts {
}
export interface Request_post_api_posts {
  title: string;
  body?: string;
  status?: string;
}
export interface Request_get_api_posts_id {
}
export interface Request_put_api_posts_id {
  title?: string;
  body?: string;
  status?: string;
}
export interface Request_delete_api_posts_id {
}
export interface Request_get_api_admin_storefront_config {
}
export interface Request_put_api_admin_storefront_config {
  expected_version: number;
  theme_key: string;
  vertical_key: string;
  brand?: Record<string, unknown>;
  tokens?: Record<string, unknown>;
  navigation?: Record<string, unknown>;
  homepage?: Record<string, unknown>;
  presentation?: Record<string, unknown>;
  purchase?: Record<string, unknown>;
  policies?: Record<string, unknown>;
}
export interface Request_post_api_admin_storefront_config_preview {
  expected_version: number;
  theme_key: string;
  vertical_key: string;
  brand?: Record<string, unknown>;
  tokens?: Record<string, unknown>;
  navigation?: Record<string, unknown>;
  homepage?: Record<string, unknown>;
  presentation?: Record<string, unknown>;
  purchase?: Record<string, unknown>;
  policies?: Record<string, unknown>;
}
export interface Request_post_api_admin_storefront_config_publish {
  expected_version: number;
}
export interface Request_post_api_admin_storefront_config_restore_revision {
  expected_version: number;
}
export interface Request_get_api_admin_storefront_config_revisions {
}
export interface Request_get_api_admin_storefront_config_revisions_diff {
}
export interface Request_get_api_admin_storefront_themes {
}
export interface Request_get_api_admin_storefront_vertical_presets {
}
export interface Request_post_api_admin_storefront_config_apply_preset {
  expected_version: number;
  preset_key: string;
}
export interface Request_get_api_admin_storefront_config_export {
}
export interface Request_post_api_admin_storefront_config_import {
  config: Record<string, unknown>;
  expected_version?: number;
}
export interface Request_get_api_admin_storefront_config_export_theme {
}
export interface Request_get_api_admin_storefront_config_export_theme_archive {
}
export interface Request_post_api_admin_storefront_config_import_theme {
  theme: Record<string, unknown>;
  expected_version?: number;
}
export interface Request_post_api_admin_storefront_config_import_theme_archive {
  package: string;
  expected_version?: number;
}
export interface Request_get_api_admin_extensions {
}
export interface Request_get_api_admin_extensions_ui {
}
export interface Request_get_api_admin_extensions_license {
}
export interface Request_post_api_admin_extensions_install {
  id: string;
}
export interface Request_post_api_admin_extensions_package_verify {
  package: string;
}
export interface Request_post_api_admin_extensions_package_qit {
  package: string;
}
export interface Request_post_api_admin_extensions_package_install {
  package: string;
  force?: boolean;
}
export interface Request_post_api_admin_extensions_enable {
  id: string;
}
export interface Request_post_api_admin_extensions_disable {
  id: string;
}
export interface Request_post_api_admin_extensions_uninstall {
  id: string;
  remove_data?: boolean;
}
export interface Request_post_api_admin_extensions_health {
  id: string;
}
export interface Request_post_api_admin_extensions_upgrade {
  id: string;
}
export interface Request_get_api_admin_pages {
}
export interface Request_post_api_admin_pages {
  slug: string;
  title: string;
  body?: string;
  locale?: string;
  status?: string;
}
export interface Request_put_api_admin_pages_id {
  slug?: string;
  title?: string;
  body?: string;
  locale?: string;
  status?: string;
}
export interface Request_get_api_admin_pages_id_preview {
}
export interface Request_post_api_admin_pages_id_blocks {
  type: string;
  content: string;
  sort?: number;
  status?: string;
}
export interface Request_put_api_admin_blocks_id {
  type?: string;
  content?: string;
  sort?: number;
  status?: string;
}
export interface Request_get_api_admin_cms_revisions_type_id {
}
export interface Request_get_api_admin_cms_revisions_type_id_diff {
}
export interface Request_post_api_admin_pages_id_autosave {
  title?: string;
  body?: string;
  status?: string;
  scheduled_at?: string;
  media_json?: Array<unknown>;
  expected_version?: number;
}
export interface Request_post_api_admin_pages_id_merge_revision {
  title?: string;
  body?: string;
  status?: string;
  scheduled_at?: string;
  media_json?: Array<unknown>;
  expected_version?: number;
}
export interface Request_post_api_admin_pages_id_publish {
}
export interface Request_post_api_admin_pages_id_restore_revision {
}
export interface Request_post_api_v2_headless_pages {
  slug: string;
  title: string;
  body?: string;
  locale?: string;
  status?: string;
}
export interface Request_get_api_admin_reviews {
}
export interface Request_post_api_admin_reviews_id_moderate {
  status: string;
  moderation_note?: string;
}
export interface Request_get_api_admin_products {
}
export interface Request_get_api_admin_vendors {
}
export interface Request_post_api_admin_vendors {
  name: string;
  slug?: string;
  status?: string;
  commission_bps?: number;
  contact_email?: string;
  description?: string;
  owner_customer_id?: string;
}
export interface Request_put_api_admin_vendors_id {
  name?: string;
  slug?: string;
  status?: string;
  commission_bps?: number;
  contact_email?: string;
  description?: string;
  owner_customer_id?: string;
}
export interface Request_delete_api_admin_vendors_id {
}
export interface Request_get_api_admin_vendors_id_ledger {
}
export interface Request_get_api_admin_vendors_id_payouts {
}
export interface Request_post_api_admin_vendors_id_payouts {
  period_start: string;
  period_end: string;
}
export interface Request_post_api_admin_vendor_payouts_id_mark_paid {
}
export interface Request_post_api_admin_vendor_payouts_reconcile {
  csv: string;
  dry_run?: boolean;
}
export interface Request_post_api_admin_vendor_payouts_id_cancel {
}
export interface Request_get_api_admin_vendor_payouts_id_export_csv {
}
export interface Request_get_api_admin_products_export_csv {
}
export interface Request_post_api_admin_products_import {
  file?: string;
  csv?: string;
}
export interface Request_post_api_admin_products {
  name: string;
  slug?: string;
  status?: string;
  price_minor?: number;
  compare_price_minor?: number;
  stock?: number;
  sku?: string;
  type?: string;
  category_id?: string;
  description?: string;
  tags?: Array<string>;
  allow_backorder?: boolean;
  meta_title?: string;
  meta_description?: string;
  min_tier?: string;
  vendor_id?: string;
}
export interface Request_post_api_admin_products_id_lifecycle {
  state: string;
  publish_at?: string;
  version?: number;
}
export interface Request_post_api_admin_products_id_voucher_codes {
  code: string;
  expires_at?: string;
}
export interface Request_get_api_admin_products_id_voucher_codes {
}
export interface Request_put_api_admin_products_id {
  name?: string;
  slug?: string;
  status?: string;
  version?: number;
  min_tier?: string;
  is_preorder?: boolean;
  vendor_id?: string;
}
export interface Request_delete_api_admin_products_id {
}
export interface Request_get_api_admin_products_id_options {
}
export interface Request_post_api_admin_products_id_options {
  name: string;
  sort?: number;
}
export interface Request_post_api_admin_products_product_options_option_values {
  value: string;
  sort?: number;
  surcharge_minor?: number;
  currency?: string;
}
export interface Request_get_api_admin_products_id_media {
}
export interface Request_post_api_admin_products_id_media {
}
export interface Request_put_api_admin_products_id_media_order {
  media_ids: Array<string>;
}
export interface Request_delete_api_admin_products_product_media_media {
}
export interface Request_get_api_admin_products_id_variants {
}
export interface Request_post_api_admin_products_id_variants {
  sku: string;
  price_minor: number;
  currency?: string;
  compare_price_minor?: number;
  tax_class?: string;
  weight_g?: number;
  length_mm?: number;
  width_mm?: number;
  height_mm?: number;
  option_value_ids?: Array<string>;
}
export interface Request_post_api_admin_product_components {
  product_id: string;
  component_variant_id: string;
  qty: number;
}
export interface Request_post_api_admin_digital_assets {
  variant_id: string;
  name: string;
  url: string;
  access_key: string;
  download_limit?: number;
  expires_at?: string;
}
export interface Request_post_api_admin_catalog_import {
  rows: Array<Record<string, unknown>>;
}
export interface Request_get_api_admin_orders {
}
export interface Request_get_api_admin_orders_id {
}
export interface Request_get_api_admin_orders_id_timeline {
}
export interface Request_post_api_admin_orders_id_transition {
  to: string;
  reason?: string;
}
export interface Request_post_api_admin_orders_id_cancel {
  reason?: string;
}
export interface Request_post_api_admin_orders_id_refund {
  amount_minor: number;
  reason?: string;
}
export interface Request_post_api_admin_orders_id_refund_remaining {
  reason?: string;
}
export interface Request_post_api_admin_orders_id_refund_lines {
  lines: Array<Record<string, unknown>>;
  reason?: string;
}
export interface Request_post_api_admin_orders_id_downloads {
}
export interface Request_get_api_admin_inventory_variant {
}
export interface Request_get_api_admin_inventory_variant_sources {
}
export interface Request_get_api_admin_replenishment_hints {
}
export interface Request_put_api_admin_inventory_variant {
  on_hand: number;
  source_id?: string;
}
export interface Request_post_api_admin_inventory_variant_transfer {
  from_source_id: string;
  to_source_id: string;
  qty: number;
  reason?: string;
}
export interface Request_put_api_admin_inventory_variant_priorities {
  source_id: string;
  priority: number;
}
export interface Request_post_api_v2_inventory_variant_atp {
  qty: number;
  cart_id: string;
  ttl_seconds?: number;
  allow_split?: boolean;
  backorder_policy?: string;
  allow_backorder?: boolean;
  allow_preorder?: boolean;
  allowed_source_ids?: Array<string>;
  excluded_source_ids?: Array<string>;
  store_id?: string;
  company_id?: string;
}
export interface Request_get_api_admin_ops_scheduled_run {
}
export interface Request_post_api_admin_ops_scheduled_run {
}
export interface Request_get_api_v2_admin_operations {
}
export interface Request_get_api_v2_admin_finance_reconciliation {
}
export interface Request_post_api_v2_admin_operations_id_retry {
}
export interface Request_post_api_v2_admin_operations_id_replay_notification {
}
export interface Request_get_api_v2_admin_subscriptions {
}
export interface Request_get_api_v2_admin_subscriptions_summary {
}
export interface Request_get_api_v2_admin_subscriptions_id {
}
export interface Request_post_api_v2_admin_subscriptions_id_retry {
}
export interface Request_get_api_v2_admin_subscription_plans {
}
export interface Request_post_api_v2_admin_subscription_plans {
  name: string;
  amount_minor: number;
  currency: string;
  interval: string;
  interval_count?: number;
  trial_period?: string;
  trial_period_count?: number;
  state?: string;
  provider?: string;
}
export interface Request_put_api_v2_admin_subscription_plans_id {
  name?: string;
  amount_minor?: number;
  currency?: string;
  interval?: string;
  interval_count?: number;
  trial_period?: string;
  trial_period_count?: number;
  state?: string;
  provider?: string;
}
export interface Request_delete_api_v2_admin_subscription_plans_id {
}
export interface Request_post_api_v2_admin_jobs {
  type: string;
  payload?: Record<string, unknown>;
}
export interface Request_get_api_v2_admin_jobs_id {
}
export interface Request_post_api_v2_admin_jobs_id_action {
  reason?: string;
}
export interface Request_post_api_v2_admin_warehouses_transfers {
  from_warehouse_id: string;
  to_warehouse_id: string;
  lines: Array<Record<string, unknown>>;
}
export interface Request_post_api_v2_admin_warehouses_reservations {
  warehouse_id: string;
  lines: Array<Record<string, unknown>>;
}
export interface Request_post_api_v2_admin_warehouses_reservations_id_release {
}
export interface Request_get_api_v2_admin_audit_logs {
}
export interface Request_get_api_v2_admin_audit_logs_export {
}
export interface Request_get_api_v2_admin_notification_events {
}
export interface Request_get_api_v2_admin_vendors {
}
export interface Request_get_api_v2_admin_vendors_id {
}
export interface Request_post_api_v2_admin_vendors {
  code: string;
  name: string;
  profile?: Record<string, unknown>;
}
export interface Request_patch_api_v2_admin_vendors_id {
  version: number;
  name?: string;
  profile?: Record<string, unknown>;
}
export interface Request_post_api_v2_admin_vendors_id_status {
  status: string;
}
export interface Request_post_api_v2_admin_vendors_vendor_catalog {
  product_id: string;
  visibility: string;
  version?: number;
}
export interface Request_post_api_v2_admin_commissions {
  vendor_id: string;
  gross_minor: number;
  rate_bps: number;
  currency: string;
  order_id?: string;
}
export interface Request_get_api_v2_admin_commissions {
}
export interface Request_get_api_v2_admin_commissions_id {
}
export interface Request_post_api_v2_admin_payouts {
  vendor_id: string;
  amount_minor: number;
  currency: string;
}
export interface Request_get_api_v2_admin_payouts_id {
}
export interface Request_post_api_v2_admin_payouts_id_transition {
  state: string;
}
export interface Request_get_api_v2_admin_seller_orders {
}
export interface Request_post_api_v2_admin_orders_id_seller_split {
  currency: string;
  parts: Array<Record<string, unknown>>;
}
export interface Request_post_api_admin_coupons_bulk {
  pattern: string;
  count: number;
  start?: number;
  rules?: string;
}
export interface Request_get_api_admin_coupons_bulk_id {
}
export interface Request_get_api_admin_coupons {
}
export interface Request_post_api_admin_coupons {
  code: string;
  rules?: string;
}
export interface Request_put_api_admin_coupons_id {
  code?: string;
  rules?: string;
  state?: string;
}
export interface Request_post_api_admin_coupons_id_disable {
}
export interface Request_post_api_admin_coupons_id_enable {
}
export interface Request_get_api_admin_coupons_id_versions {
}
export interface Request_get_api_admin_coupons_id_usage {
}
export interface Request_get_api_admin_coupons_pnl {
}
export interface Request_get_api_admin_promotion_rules {
}
export interface Request_get_api_admin_tax_rules {
}
export interface Request_post_api_admin_tax_rules {
  code: string;
  destination_country?: string;
  destination_region?: string;
  destination_postal_code?: string;
  tax_class?: string;
  rate_basis_points: number;
  priority?: number;
  state?: string;
  compound?: boolean;
  effective_at?: string;
  expires_at?: string;
}
export interface Request_put_api_admin_tax_rules_id {
  code?: string;
  destination_country?: string;
  destination_region?: string;
  destination_postal_code?: string;
  tax_class?: string;
  rate_basis_points?: number;
  priority?: number;
  state?: string;
  compound?: boolean;
  effective_at?: string;
  expires_at?: string;
}
export interface Request_delete_api_admin_tax_rules_id {
}
export interface Request_post_api_admin_promotion_rules {
  name: string;
  kind: string;
  value: number;
  min_subtotal_minor?: number;
  category_slugs?: Array<string>;
  category_slugs_json?: string;
  starts_at?: string;
  ends_at?: string;
  max_uses?: number;
  state?: string;
}
export interface Request_put_api_admin_promotion_rules_id {
  name?: string;
  kind?: string;
  value?: number;
  min_subtotal_minor?: number;
  category_slugs?: Array<string>;
  category_slugs_json?: string;
  starts_at?: string;
  ends_at?: string;
  max_uses?: number;
  state?: string;
}
export interface Request_delete_api_admin_promotion_rules_id {
}
export interface Request_post_api_admin_gift_cards {
  amount_minor: number;
  currency?: string;
  owner_customer_id?: string;
  code?: string;
  type?: string;
  expires_at?: string;
}
export interface Request_get_api_admin_gift_cards {
}
export interface Request_post_api_admin_gift_cards_id_void {
}
export interface Request_get_api_admin_store_credits {
}
export interface Request_post_api_admin_orders_id_refund_to_credit {
  reason?: string;
}
export interface Request_get_api_account_credits {
}
export interface Request_get_api_account_vendor_ledger {
}
export interface Request_get_api_account_vendor_payouts {
}
export interface Request_post_api_gift_preview {
  code: string;
  subtotal_minor?: number;
  currency?: string;
}
export interface Request_get_api_admin_products_id_booking_slots {
}
export interface Request_post_api_admin_products_id_booking_slots {
  starts_at: string;
  ends_at: string;
  capacity: number;
  price_minor?: number;
  currency?: string;
}
export interface Request_get_api_v2_products_id_booking_quote {
}
export interface Request_get_api_admin_flash_sales {
}
export interface Request_post_api_admin_flash_sales {
  name: string;
  starts_at: string;
  ends_at: string;
  state?: string;
  priority?: number;
}
export interface Request_put_api_admin_flash_sales_id {
  name?: string;
  starts_at?: string;
  ends_at?: string;
  state?: string;
  priority?: number;
}
export interface Request_post_api_admin_flash_sales_id_items {
  variant_id: string;
  sale_price_minor: number;
  cap_qty?: number;
}
export interface Request_delete_api_admin_flash_sales_id_items_item {
}
export interface Request_get_api_admin_member_prices {
}
export interface Request_post_api_admin_member_prices {
  variant_id: string;
  tier: string;
  price_minor: number;
  currency?: string;
}
export interface Request_delete_api_admin_member_prices_id {
}
export interface Request_get_api_admin_member_deals {
}
export interface Request_post_api_admin_member_deals {
  name: string;
  kind?: string;
  min_tier?: string;
  percent_off: number;
  starts_at?: string;
  ends_at?: string;
}
export interface Request_put_api_admin_member_deals_id {
  name?: string;
  min_tier?: string;
  percent_off?: number;
  starts_at?: string;
  ends_at?: string;
  state?: string;
}
export interface Request_get_api_admin_media_library {
}
export interface Request_post_api_admin_media_library {
  title?: string;
  alt?: string;
}
export interface Request_delete_api_admin_media_library_id {
}
export interface Request_get_api_admin_customers_id {
}
export interface Request_get_api_admin_customers_id_addresses {
}
export interface Request_post_api_admin_customers_id_addresses {
  label?: string;
  line1: string;
  city: string;
  phone: string;
}
export interface Request_post_api_admin_b2b_companies {
  name: string;
  status?: string;
}
export interface Request_post_api_admin_b2b_companies_id_members {
  user_id: number;
  role: string;
}
export interface Request_post_api_admin_b2b_companies_id_approval_scopes {
  scope: string;
  channel_id?: string;
  approval_limit_minor?: number;
}
export interface Request_post_api_admin_b2b_companies_id_credit_account {
  limit_minor: number;
  currency?: string;
  payment_terms_days?: number;
}
export interface Request_get_api_admin_b2b_companies_id_credit {
}
export interface Request_post_api_admin_b2b_companies_id_contracts {
  contract_number: string;
  credit_limit_minor?: number;
  currency?: string;
  starts_at?: string;
  ends_at?: string;
}
export interface Request_post_api_admin_b2b_companies_id_catalogs {
  code: string;
  name: string;
  currency?: string;
  scope?: string;
}
export interface Request_post_api_admin_b2b_companies_id_catalogs_catalog_items {
  variant_id: string;
  minimum_qty?: number;
}
export interface Request_post_api_admin_b2b_companies_id_negotiated_prices {
  variant_id: string;
  currency?: string;
  unit_price_minor: number;
  minimum_qty?: number;
}
export interface Request_post_api_admin_b2b_companies_id_groups {
  code: string;
  name: string;
}
export interface Request_post_api_admin_b2b_companies_id_roles_role_policy {
  policy?: Record<string, unknown>;
  approval_limit_minor?: number;
}
export interface Request_post_api_admin_b2b_companies_id_price_lists {
  code: string;
  currency?: string;
}
export interface Request_post_api_admin_b2b_price_lists_id_tiers {
  variant_id: string;
  minimum_qty: number;
  unit_price_minor: number;
}
export interface Request_put_api_admin_b2b_companies_id_terms {
  minimum_order_minor?: number;
  minimum_order_qty?: number;
  credit_limit_minor?: number;
  payment_terms_days?: number;
  currency?: string;
}
export interface Request_post_api_v2_b2b_companies_id_quotes {
  currency: string;
  lines: Array<Record<string, unknown>>;
  expires_at?: string;
  purchase_order_number?: string;
  payment_terms_days?: number;
}
export interface Request_post_api_v2_b2b_companies_id_orders_validate_bulk {
  lines: Array<Record<string, unknown>>;
}
export interface Request_post_api_v2_b2b_quotes_id_convert {
}
export interface Request_get_api_v2_b2b_quotes_id {
}
export interface Request_post_api_v2_b2b_quotes_id_action {
  reason?: string;
}
export interface Request_post_api_v2_b2b_orders_id_approve {
}
export interface Request_post_api_v2_b2b_orders_id_transition {
  state: string;
  reason?: string;
}
export interface Request_post_api_v2_b2b_orders_id_draft {
  reason?: string;
}
export interface Request_post_api_v2_b2b_orders_id_submit {
  reason?: string;
}
export interface Request_post_api_v2_b2b_orders_id_approve_company {
  reason?: string;
}
export interface Request_post_api_v2_b2b_orders_id_reject {
  reason?: string;
}
export interface Request_get_api_v2_b2b_companies_id_credit {
}
export interface Request_post_api_v2_b2b_contracts_id_approve {
}
export interface Request_post_api_v2_b2b_companies_id_purchase_orders {
  po_number: string;
  currency?: string;
  total_minor: number;
  contract_id?: string;
}
export interface Request_post_api_v2_b2b_purchase_orders_id_action {
  reason?: string;
}
export interface Request_get_sitemap_xml {
}
export interface Request_get_robots_txt {
}
export interface Request_post_api_promotions_preview {
  code: string;
  subtotal_minor: number;
  currency?: string;
  lines?: Array<Record<string, unknown>>;
  customer_group_id?: string;
  applied_promotions?: Array<unknown>;
}
export interface Request_get_api_admin_promotion_decisions_audit {
}
export interface Request_post_api_analytics_track {
  event: string;
  event_id: string;
  session_id: string;
  properties: Record<string, unknown>;
  analytics_consent?: boolean;
  store_id: string;
  channel_id: string;
  locale: string;
  currency: string;
}
export interface Request_get_api_admin_seo_health {
}
export interface Request_get_api_admin_seo_type_id_schema {
}
export interface Request_get_api_admin_seo_type_id {
}
export interface Request_put_api_admin_seo_type_id {
  title: string;
  description?: string;
  canonical?: string;
  robots?: string;
  og_image?: string;
  schema_json?: string;
}
export interface Request_post_api_admin_redirects {
  from_path: string;
  to_path: string;
  code?: number;
}
export interface Request_get_api_admin_analytics_funnel {
}
export interface Request_get_api_admin_analytics_events {
}
export interface Request_get_api_admin_analytics_export {
}
export interface Request_get_api_admin_smart_action_center {
}
export interface Request_post_api_admin_smart_rescue_id {
}
export interface Request_get_api_admin_smart_search {
}
export interface Request_post_api_admin_smart_execute {
  command: string;
}
export interface Request_get_api_admin_smart_inventory {
}
export interface Request_get_api_admin_smart_setup_guide {
}
export interface Request_get_api_admin_smart_conversion {
}
export interface Request_get_api_admin_dev_trace_id {
}
export interface Request_get_api_admin_dev_doctor {
}
export interface Request_post_api_admin_dev_recover {
  action: string;
  order_id?: string;
  dry_run?: number;
}
export interface Request_get_api_admin_ops_observability {
}
export interface Request_get_api_admin_ops_readiness {
}
export interface Request_get_api_admin_ops_metrics {
}
export interface Request_get_api_admin_ops_hooks {
}
export interface Request_post_api_orders_recover {
  order_number: string;
  email: string;
}
export interface Request_post_api_v2_payments_id_authorize {
  amount_minor: number;
  currency?: string;
}
export interface Request_get_api_v2_payments_id {
}
export interface Request_get_api_v2_payments_id_status {
}
export interface Request_post_api_v2_payments_id_proof {
  reference: string;
  file_url?: string;
  file_hash?: string;
}
export interface Request_post_api_admin_payments_reconcile {
  apply?: boolean;
}
export interface Request_post_api_webhooks_erp_loyalty {
}
export interface Request_post_api_webhooks_erp_subscription {
}
export interface Request_post_api_webhooks_erp_pos {
}
export interface Request_post_api_webhooks_erp_marketplace {
}
export interface Request_post_api_v2_admin_erp_loyalty_evaluate {
  customer_id: string;
  order_id: string;
  order_total_minor: number;
  currency?: string;
}
export interface Request_post_api_v2_admin_erp_subscriptions_id_renew {
  period?: string;
  amount_minor?: number;
  currency?: string;
}
export class SiroCommerceClient {
  private readonly http: typeof fetch;
  constructor(private readonly options: SiroCommerceClientOptions) { this.http = options.fetch ?? fetch; }
  async request<T>(method: string, path: string, options: ApiRequestOptions = {}): Promise<T> {
    Object.entries(options.path ?? {}).forEach(([k, v]) => { path = path.replace(`{${k}}`, encodeURIComponent(String(v))); }); const url = new URL(path, this.options.baseUrl); Object.entries(options.query ?? {}).forEach(([k, v]) => { if (v !== undefined && v !== null) url.searchParams.set(k, String(v)); });
    const headers: Record<string, string> = { Accept: "application/json", ...this.options.defaultHeaders, ...options.headers }; if (options.token) headers.Authorization = `Bearer ${options.token}`; if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey; if (options.body !== undefined) headers["Content-Type"] = "application/json";
    const response = await this.http(url, { method, headers, body: options.body === undefined ? undefined : JSON.stringify(options.body), signal: options.signal });
    const payload = await response.json() as T; if (!response.ok) throw payload; return payload;
  }
  delete_api_admin_flash_sales_id_items_item(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/flash-sales/{id}/items/{item}', options); }
  delete_api_admin_media_library_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/media-library/{id}', options); }
  delete_api_admin_member_prices_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/member-prices/{id}', options); }
  delete_api_admin_products_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/products/{id}', options); }
  delete_api_admin_products_product_media_media(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/products/{product}/media/{media}', options); }
  delete_api_admin_promotion_rules_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/promotion-rules/{id}', options); }
  delete_api_admin_search_seo_synonyms_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/search-seo/synonyms/{id}', options); }
  delete_api_admin_search_seo_url_rewrites_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/search-seo/url-rewrites/{id}', options); }
  delete_api_admin_tax_rules_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/tax-rules/{id}', options); }
  delete_api_admin_vendors_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/admin/vendors/{id}', options); }
  delete_api_carts_id_lines_line(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/carts/{id}/lines/{line}', options); }
  delete_api_categories_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/categories/{id}', options); }
  delete_api_posts_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/posts/{id}', options); }
  delete_api_products_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/products/{id}', options); }
  delete_api_tags_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/tags/{id}', options); }
  delete_api_users_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/users/{id}', options); }
  delete_api_v2_admin_subscription_plans_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/v2/admin/subscription-plans/{id}', options); }
  delete_api_v2_admin_workbench_views_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/v2/admin/workbench/views/{id}', options); }
  delete_api_wishlist_product(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('DELETE', '/api/wishlist/{product}', options); }
  get_api_account_addresses(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/addresses', options); }
  get_api_account_credits(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/credits', options); }
  get_api_account_financial_documents_type_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/financial-documents/{type}/{id}', options); }
  get_api_account_loyalty(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/loyalty', options); }
  get_api_account_message_history(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/message-history', options); }
  get_api_account_notification_preferences(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/notification-preferences', options); }
  get_api_account_orders(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/account/orders', options); }
  get_api_account_orders_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/orders/{id}', options); }
  get_api_account_orders_id_financial_documents(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/orders/{id}/financial-documents', options); }
  get_api_account_orders_id_notifications(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/orders/{id}/notifications', options); }
  get_api_account_orders_id_timeline(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/orders/{id}/timeline', options); }
  get_api_account_privacy_export(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/privacy/export', options); }
  get_api_account_returns(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/account/returns', options); }
  get_api_account_returns_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/returns/{id}', options); }
  get_api_account_subscriptions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/subscriptions', options); }
  get_api_account_subscriptions_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/subscriptions/{id}', options); }
  get_api_account_vendor_ledger(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/vendor/ledger', options); }
  get_api_account_vendor_payouts(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/account/vendor/payouts', options); }
  get_api_admin_analytics_events(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/admin/analytics/events', options); }
  get_api_admin_analytics_export(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/analytics/export', options); }
  get_api_admin_analytics_funnel(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/analytics/funnel', options); }
  get_api_admin_b2b_companies_id_credit(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/b2b/companies/{id}/credit', options); }
  get_api_admin_cms_revisions_type_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/cms-revisions/{type}/{id}', options); }
  get_api_admin_cms_revisions_type_id_diff(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/cms-revisions/{type}/{id}/diff', options); }
  get_api_admin_configuration(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/configuration', options); }
  get_api_admin_configuration_group(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/configuration/{group}', options); }
  get_api_admin_configuration_group_section(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/configuration/{group}/{section}', options); }
  get_api_admin_consent_summary(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/consent/summary', options); }
  get_api_admin_coupons(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/coupons', options); }
  get_api_admin_coupons_bulk_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/coupons/bulk/{id}', options); }
  get_api_admin_coupons_id_usage(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/coupons/{id}/usage', options); }
  get_api_admin_coupons_id_versions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/coupons/{id}/versions', options); }
  get_api_admin_coupons_pnl(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/coupons/pnl', options); }
  get_api_admin_credit_memos_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/credit-memos/{id}', options); }
  get_api_admin_customers_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/customers/{id}', options); }
  get_api_admin_customers_id_addresses(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/customers/{id}/addresses', options); }
  get_api_admin_dev_doctor(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/dev/doctor', options); }
  get_api_admin_dev_trace_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/dev/trace/{id}', options); }
  get_api_admin_extensions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/extensions', options); }
  get_api_admin_extensions_license(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/extensions/license', options); }
  get_api_admin_extensions_ui(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/extensions/ui', options); }
  get_api_admin_features(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/features', options); }
  get_api_admin_flash_sales(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/flash-sales', options); }
  get_api_admin_fulfillment_id_track(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/fulfillment/{id}/track', options); }
  get_api_admin_gift_cards(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/gift-cards', options); }
  get_api_admin_inventory_variant(options: ApiRequestOptions = {}): Promise<ApiEnvelope<InventoryItem>> { return this.request<ApiEnvelope<InventoryItem>>('GET', '/api/admin/inventory/{variant}', options); }
  get_api_admin_inventory_variant_sources(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/inventory/{variant}/sources', options); }
  get_api_admin_invoices_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/invoices/{id}', options); }
  get_api_admin_invoices_id_evidence(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/invoices/{id}/evidence', options); }
  get_api_admin_launch_status(options: ApiRequestOptions = {}): Promise<ApiEnvelope<LaunchStatus>> { return this.request<ApiEnvelope<LaunchStatus>>('GET', '/api/admin/launch/status', options); }
  get_api_admin_media_library(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/media-library', options); }
  get_api_admin_member_deals(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/member-deals', options); }
  get_api_admin_member_prices(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/member-prices', options); }
  get_api_admin_ops_alerts(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/ops-alerts', options); }
  get_api_admin_ops_hooks(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/ops/hooks', options); }
  get_api_admin_ops_metrics(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/ops/metrics', options); }
  get_api_admin_ops_observability(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/ops/observability', options); }
  get_api_admin_ops_readiness(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/ops/readiness', options); }
  get_api_admin_ops_scheduled_run(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/ops/scheduled/run', options); }
  get_api_admin_orders(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/orders', options); }
  get_api_admin_orders_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/orders/{id}', options); }
  get_api_admin_orders_id_timeline(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/orders/{id}/timeline', options); }
  get_api_admin_pages(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/pages', options); }
  get_api_admin_pages_id_preview(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/pages/{id}/preview', options); }
  get_api_admin_products(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products', options); }
  get_api_admin_products_export_csv(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products/export.csv', options); }
  get_api_admin_products_id_booking_slots(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products/{id}/booking-slots', options); }
  get_api_admin_products_id_media(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products/{id}/media', options); }
  get_api_admin_products_id_options(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products/{id}/options', options); }
  get_api_admin_products_id_variants(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products/{id}/variants', options); }
  get_api_admin_products_id_voucher_codes(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/products/{id}/voucher-codes', options); }
  get_api_admin_promotion_decisions_audit(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/promotion-decisions/audit', options); }
  get_api_admin_promotion_rules(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/promotion-rules', options); }
  get_api_admin_providers_status(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProviderStatus>> { return this.request<ApiEnvelope<ProviderStatus>>('GET', '/api/admin/providers/status', options); }
  get_api_admin_providers_verify(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProviderVerification>> { return this.request<ApiEnvelope<ProviderVerification>>('GET', '/api/admin/providers/verify', options); }
  get_api_admin_replenishment_hints(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/replenishment-hints', options); }
  get_api_admin_reviews(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/reviews', options); }
  get_api_admin_search_seo_search_terms(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/search-seo/search-terms', options); }
  get_api_admin_search_seo_sitemaps(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/search-seo/sitemaps', options); }
  get_api_admin_search_seo_synonyms(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/search-seo/synonyms', options); }
  get_api_admin_search_seo_url_rewrites(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/search-seo/url-rewrites', options); }
  get_api_admin_seo_health(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/seo/health', options); }
  get_api_admin_seo_type_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/seo/{type}/{id}', options); }
  get_api_admin_seo_type_id_schema(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/seo/{type}/{id}/schema', options); }
  get_api_admin_smart_action_center(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/smart/action-center', options); }
  get_api_admin_smart_conversion(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/smart/conversion', options); }
  get_api_admin_smart_inventory(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/smart/inventory', options); }
  get_api_admin_smart_search(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/smart/search', options); }
  get_api_admin_smart_setup_guide(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/smart/setup-guide', options); }
  get_api_admin_stock_alerts(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/stock-alerts', options); }
  get_api_admin_store_credits(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/store-credits', options); }
  get_api_admin_storefront_config(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/config', options); }
  get_api_admin_storefront_config_export(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/config/export', options); }
  get_api_admin_storefront_config_export_theme(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/config/export-theme', options); }
  get_api_admin_storefront_config_export_theme_archive(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/config/export-theme-archive', options); }
  get_api_admin_storefront_config_revisions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/config/revisions', options); }
  get_api_admin_storefront_config_revisions_diff(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/config/revisions/diff', options); }
  get_api_admin_storefront_themes(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/themes', options); }
  get_api_admin_storefront_vertical_presets(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/storefront/vertical-presets', options); }
  get_api_admin_stores(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/stores', options); }
  get_api_admin_stores_store_channels(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/stores/{store}/channels', options); }
  get_api_admin_tax_rules(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/tax-rules', options); }
  get_api_admin_vendor_payouts_id_export_csv(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/vendor-payouts/{id}/export.csv', options); }
  get_api_admin_vendors(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/vendors', options); }
  get_api_admin_vendors_id_ledger(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/vendors/{id}/ledger', options); }
  get_api_admin_vendors_id_payouts(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/admin/vendors/{id}/payouts', options); }
  get_api_auth_me(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/auth/me', options); }
  get_api_carts_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/carts/{id}', options); }
  get_api_carts_id_quote(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/carts/{id}/quote', options); }
  get_api_categories(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/categories', options); }
  get_api_categories_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/categories/{id}', options); }
  get_api_configuration_public(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/configuration/public', options); }
  get_api_consent_policy(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/consent/policy', options); }
  get_api_dashboard_stats(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/dashboard/stats', options); }
  get_api_headless_schema(options: ApiRequestOptions = {}): Promise<ApiEnvelope<HeadlessSchemaEnvelope>> { return this.request<ApiEnvelope<HeadlessSchemaEnvelope>>('GET', '/api/headless/schema', options); }
  get_api_menus_key(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/menus/{key}', options); }
  get_api_orders(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/orders', options); }
  get_api_orders_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/orders/{id}', options); }
  get_api_orders_id_returns(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/orders/{id}/returns', options); }
  get_api_orders_id_timeline(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/orders/{id}/timeline', options); }
  get_api_pages_slug(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/pages/{slug}', options); }
  get_api_pickup_points(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/pickup-points', options); }
  get_api_posts(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/posts', options); }
  get_api_posts_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/posts/{id}', options); }
  get_api_products(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/products', options); }
  get_api_products_id_affinities(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/products/{id}/affinities', options); }
  get_api_products_id_reviews(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/products/{id}/reviews', options); }
  get_api_products_id_reviews_summary(options: ApiRequestOptions<Request_get_api_products_id_reviews_summary> = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/products/{id}/reviews/summary', options); }
  get_api_products_slug(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/products/{slug}', options); }
  get_api_profile(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/profile', options); }
  get_api_redirects_resolve(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/redirects/resolve', options); }
  get_api_search_suggest(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/search/suggest', options); }
  get_api_server_info(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/server/info', options); }
  get_api_settings(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/settings', options); }
  get_api_storefront_config(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/storefront/config', options); }
  get_api_storefront_context(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/storefront/context', options); }
  get_api_storefront_theme_presets(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/storefront/theme-presets', options); }
  get_api_tags(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/tags', options); }
  get_api_tags_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/tags/{id}', options); }
  get_api_users(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/users', options); }
  get_api_users_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/users/{id}', options); }
  get_api_v2_admin_ai_copilot_queue(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/ai/copilot/queue', options); }
  get_api_v2_admin_audit_logs(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/audit-logs', options); }
  get_api_v2_admin_audit_logs_export(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/audit-logs/export', options); }
  get_api_v2_admin_campaign_automation(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/campaign-automation', options); }
  get_api_v2_admin_catalog_exports(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/catalog/exports', options); }
  get_api_v2_admin_commissions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/commissions', options); }
  get_api_v2_admin_commissions_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/commissions/{id}', options); }
  get_api_v2_admin_dashboard_bi(options: ApiRequestOptions = {}): Promise<ApiEnvelope<DashboardBi>> { return this.request<ApiEnvelope<DashboardBi>>('GET', '/api/v2/admin/dashboard/bi', options); }
  get_api_v2_admin_exports_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/exports/{id}', options); }
  get_api_v2_admin_exports_id_download(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/exports/{id}/download', options); }
  get_api_v2_admin_finance_reconciliation(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/finance/reconciliation', options); }
  get_api_v2_admin_jobs_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/jobs/{id}', options); }
  get_api_v2_admin_notification_events(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/notification-events', options); }
  get_api_v2_admin_operations(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/v2/admin/operations', options); }
  get_api_v2_admin_payouts_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/payouts/{id}', options); }
  get_api_v2_admin_products_id_versions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/products/{id}/versions', options); }
  get_api_v2_admin_reports_advanced(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/reports/advanced', options); }
  get_api_v2_admin_reports_contract(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ReportingContractEnvelope>> { return this.request<ApiEnvelope<ReportingContractEnvelope>>('GET', '/api/v2/admin/reports/contract', options); }
  get_api_v2_admin_reports_failures(options: ApiRequestOptions = {}): Promise<PaginatedApiEnvelope<unknown>> { return this.request<PaginatedApiEnvelope<unknown>>('GET', '/api/v2/admin/reports/failures', options); }
  get_api_v2_admin_reports_overview(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/reports/overview', options); }
  get_api_v2_admin_search(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/search', options); }
  get_api_v2_admin_search_status(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/search/status', options); }
  get_api_v2_admin_seller_orders(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/seller-orders', options); }
  get_api_v2_admin_subscription_plans(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/subscription-plans', options); }
  get_api_v2_admin_subscriptions(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/subscriptions', options); }
  get_api_v2_admin_subscriptions_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/subscriptions/{id}', options); }
  get_api_v2_admin_subscriptions_summary(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/subscriptions/summary', options); }
  get_api_v2_admin_vendors(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/vendors', options); }
  get_api_v2_admin_vendors_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/vendors/{id}', options); }
  get_api_v2_admin_workbench(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/admin/workbench', options); }
  get_api_v2_b2b_companies_id_credit(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/b2b/companies/{id}/credit', options); }
  get_api_v2_b2b_quotes_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/b2b/quotes/{id}', options); }
  get_api_v2_catalog_brands(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/catalog/brands', options); }
  get_api_v2_catalog_categories(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/catalog/categories', options); }
  get_api_v2_catalog_product_types(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProductTypeList>> { return this.request<ApiEnvelope<ProductTypeList>>('GET', '/api/v2/catalog/product-types', options); }
  get_api_v2_catalog_tags(options: ApiRequestOptions = {}): Promise<ApiEnvelope<CatalogTagList>> { return this.request<ApiEnvelope<CatalogTagList>>('GET', '/api/v2/catalog/tags', options); }
  get_api_v2_downloads_token(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/downloads/{token}', options); }
  get_api_v2_health_inventory(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/health/inventory', options); }
  get_api_v2_health_outbox(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/health/outbox', options); }
  get_api_v2_health_payments(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/health/payments', options); }
  get_api_v2_health_queue(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/health/queue', options); }
  get_api_v2_payments_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/payments/{id}', options); }
  get_api_v2_payments_id_status(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/payments/{id}/status', options); }
  get_api_v2_products_id_booking_quote(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/products/{id}/booking-quote', options); }
  get_api_v2_products_id_bundle_quote(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/products/{id}/bundle-quote', options); }
  get_api_v2_shipments_id_tracking(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/v2/shipments/{id}/tracking', options); }
  get_api_vendors_slug(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/vendors/{slug}', options); }
  get_api_version(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/version', options); }
  get_api_wishlist(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/api/wishlist', options); }
  get_health_live(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/health/live', options); }
  get_health_providers(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/health/providers', options); }
  get_health_ready(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/health/ready', options); }
  get_metrics(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/metrics', options); }
  get_robots_txt(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/robots.txt', options); }
  get_sitemap_xml(options: ApiRequestOptions = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('GET', '/sitemap.xml', options); }
  patch_api_carts_id_lines_line(options: ApiRequestOptions<Request_patch_api_carts_id_lines_line> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PATCH', '/api/carts/{id}/lines/{line}', options); }
  patch_api_v2_admin_vendors_id(options: ApiRequestOptions<Request_patch_api_v2_admin_vendors_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PATCH', '/api/v2/admin/vendors/{id}', options); }
  post_api_account_addresses(options: ApiRequestOptions<Request_post_api_account_addresses> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/addresses', options); }
  post_api_account_consents(options: ApiRequestOptions<Request_post_api_account_consents> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/consents', options); }
  post_api_account_loyalty_expire(options: ApiRequestOptions<Request_post_api_account_loyalty_expire> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/loyalty/expire', options); }
  post_api_account_loyalty_order_earn(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/loyalty/{order}/earn', options); }
  post_api_account_loyalty_redeem(options: ApiRequestOptions<Request_post_api_account_loyalty_redeem> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/loyalty/redeem', options); }
  post_api_account_orders_id_returns(options: ApiRequestOptions<Request_post_api_account_orders_id_returns> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/orders/{id}/returns', options); }
  post_api_account_privacy_anonymize(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/privacy/anonymize', options); }
  post_api_account_subscriptions(options: ApiRequestOptions<Request_post_api_account_subscriptions> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/subscriptions', options); }
  post_api_account_subscriptions_id_renew(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/subscriptions/{id}/renew', options); }
  post_api_account_subscriptions_id_transition(options: ApiRequestOptions<Request_post_api_account_subscriptions_id_transition> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/account/subscriptions/{id}/transition', options); }
  post_api_admin_b2b_companies(options: ApiRequestOptions<Request_post_api_admin_b2b_companies> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies', options); }
  post_api_admin_b2b_companies_id_approval_scopes(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_approval_scopes> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/approval-scopes', options); }
  post_api_admin_b2b_companies_id_catalogs(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_catalogs> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/catalogs', options); }
  post_api_admin_b2b_companies_id_catalogs_catalog_items(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_catalogs_catalog_items> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/catalogs/{catalog}/items', options); }
  post_api_admin_b2b_companies_id_contracts(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_contracts> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/contracts', options); }
  post_api_admin_b2b_companies_id_credit_account(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_credit_account> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/credit-account', options); }
  post_api_admin_b2b_companies_id_groups(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_groups> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/groups', options); }
  post_api_admin_b2b_companies_id_members(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_members> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/members', options); }
  post_api_admin_b2b_companies_id_negotiated_prices(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_negotiated_prices> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/negotiated-prices', options); }
  post_api_admin_b2b_companies_id_price_lists(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_price_lists> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/price-lists', options); }
  post_api_admin_b2b_companies_id_roles_role_policy(options: ApiRequestOptions<Request_post_api_admin_b2b_companies_id_roles_role_policy> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/companies/{id}/roles/{role}/policy', options); }
  post_api_admin_b2b_price_lists_id_tiers(options: ApiRequestOptions<Request_post_api_admin_b2b_price_lists_id_tiers> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/b2b/price-lists/{id}/tiers', options); }
  post_api_admin_catalog_import(options: ApiRequestOptions<Request_post_api_admin_catalog_import> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/catalog/import', options); }
  post_api_admin_channels(options: ApiRequestOptions<Request_post_api_admin_channels> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/channels', options); }
  post_api_admin_coupons(options: ApiRequestOptions<Request_post_api_admin_coupons> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/coupons', options); }
  post_api_admin_coupons_bulk(options: ApiRequestOptions<Request_post_api_admin_coupons_bulk> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/coupons/bulk', options); }
  post_api_admin_coupons_id_disable(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/coupons/{id}/disable', options); }
  post_api_admin_coupons_id_enable(options: ApiRequestOptions<Request_post_api_admin_coupons_id_enable> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/coupons/{id}/enable', options); }
  post_api_admin_customers_id_addresses(options: ApiRequestOptions<Request_post_api_admin_customers_id_addresses> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/customers/{id}/addresses', options); }
  post_api_admin_customers_id_privacy_legal_hold(options: ApiRequestOptions<Request_post_api_admin_customers_id_privacy_legal_hold> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/customers/{id}/privacy/legal-hold', options); }
  post_api_admin_dev_recover(options: ApiRequestOptions<Request_post_api_admin_dev_recover> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/dev/recover', options); }
  post_api_admin_digital_assets(options: ApiRequestOptions<Request_post_api_admin_digital_assets> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/digital-assets', options); }
  post_api_admin_extensions_disable(options: ApiRequestOptions<Request_post_api_admin_extensions_disable> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/disable', options); }
  post_api_admin_extensions_enable(options: ApiRequestOptions<Request_post_api_admin_extensions_enable> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/enable', options); }
  post_api_admin_extensions_health(options: ApiRequestOptions<Request_post_api_admin_extensions_health> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/health', options); }
  post_api_admin_extensions_install(options: ApiRequestOptions<Request_post_api_admin_extensions_install> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/install', options); }
  post_api_admin_extensions_package_install(options: ApiRequestOptions<Request_post_api_admin_extensions_package_install> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/package/install', options); }
  post_api_admin_extensions_package_qit(options: ApiRequestOptions<Request_post_api_admin_extensions_package_qit> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/package/qit', options); }
  post_api_admin_extensions_package_verify(options: ApiRequestOptions<Request_post_api_admin_extensions_package_verify> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/package/verify', options); }
  post_api_admin_extensions_uninstall(options: ApiRequestOptions<Request_post_api_admin_extensions_uninstall> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/uninstall', options); }
  post_api_admin_extensions_upgrade(options: ApiRequestOptions<Request_post_api_admin_extensions_upgrade> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/extensions/upgrade', options); }
  post_api_admin_flash_sales(options: ApiRequestOptions<Request_post_api_admin_flash_sales> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/flash-sales', options); }
  post_api_admin_flash_sales_id_items(options: ApiRequestOptions<Request_post_api_admin_flash_sales_id_items> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/flash-sales/{id}/items', options); }
  post_api_admin_fulfillment_allocate(options: ApiRequestOptions<Request_post_api_admin_fulfillment_allocate> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/fulfillment/allocate', options); }
  post_api_admin_fulfillment_id_cancel(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/fulfillment/{id}/cancel', options); }
  post_api_admin_fulfillment_id_collect(options: ApiRequestOptions<Request_post_api_admin_fulfillment_id_collect> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/fulfillment/{id}/collect', options); }
  post_api_admin_fulfillment_id_deliver(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/fulfillment/{id}/deliver', options); }
  post_api_admin_fulfillment_id_ready(options: ApiRequestOptions<Request_post_api_admin_fulfillment_id_ready> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/fulfillment/{id}/ready', options); }
  post_api_admin_fulfillment_id_ship(options: ApiRequestOptions<Request_post_api_admin_fulfillment_id_ship> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/fulfillment/{id}/ship', options); }
  post_api_admin_gift_cards(options: ApiRequestOptions<Request_post_api_admin_gift_cards> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/gift-cards', options); }
  post_api_admin_gift_cards_id_void(options: ApiRequestOptions<Request_post_api_admin_gift_cards_id_void> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/gift-cards/{id}/void', options); }
  post_api_admin_inventory_variant_transfer(options: ApiRequestOptions<Request_post_api_admin_inventory_variant_transfer> = {}): Promise<ApiEnvelope<InventoryTransfer>> { return this.request<ApiEnvelope<InventoryTransfer>>('POST', '/api/admin/inventory/{variant}/transfer', options); }
  post_api_admin_invoices(options: ApiRequestOptions<Request_post_api_admin_invoices> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/invoices', options); }
  post_api_admin_invoices_id_credit_memos(options: ApiRequestOptions<Request_post_api_admin_invoices_id_credit_memos> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/invoices/{id}/credit-memos', options); }
  post_api_admin_invoices_id_issue(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/invoices/{id}/issue', options); }
  post_api_admin_launch_seed_demo(options: ApiRequestOptions = {}): Promise<ApiEnvelope<LaunchSeedResult>> { return this.request<ApiEnvelope<LaunchSeedResult>>('POST', '/api/admin/launch/seed-demo', options); }
  post_api_admin_media_library(options: ApiRequestOptions<Request_post_api_admin_media_library> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/media-library', options); }
  post_api_admin_member_deals(options: ApiRequestOptions<Request_post_api_admin_member_deals> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/member-deals', options); }
  post_api_admin_member_prices(options: ApiRequestOptions<Request_post_api_admin_member_prices> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/member-prices', options); }
  post_api_admin_ops_alerts_kind_ack(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/ops-alerts/{kind}/ack', options); }
  post_api_admin_ops_scheduled_run(options: ApiRequestOptions<Request_post_api_admin_ops_scheduled_run> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/ops/scheduled/run', options); }
  post_api_admin_orders_id_cancel(options: ApiRequestOptions<Request_post_api_admin_orders_id_cancel> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/cancel', options); }
  post_api_admin_orders_id_downloads(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/downloads', options); }
  post_api_admin_orders_id_promotion_allocations_reconcile(options: ApiRequestOptions<Request_post_api_admin_orders_id_promotion_allocations_reconcile> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/promotion-allocations/reconcile', options); }
  post_api_admin_orders_id_refund(options: ApiRequestOptions<Request_post_api_admin_orders_id_refund> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/refund', options); }
  post_api_admin_orders_id_refund_lines(options: ApiRequestOptions<Request_post_api_admin_orders_id_refund_lines> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/refund-lines', options); }
  post_api_admin_orders_id_refund_remaining(options: ApiRequestOptions<Request_post_api_admin_orders_id_refund_remaining> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/refund-remaining', options); }
  post_api_admin_orders_id_refund_to_credit(options: ApiRequestOptions<Request_post_api_admin_orders_id_refund_to_credit> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/refund-to-credit', options); }
  post_api_admin_orders_id_transition(options: ApiRequestOptions<Request_post_api_admin_orders_id_transition> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/orders/{id}/transition', options); }
  post_api_admin_pages(options: ApiRequestOptions<Request_post_api_admin_pages> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pages', options); }
  post_api_admin_pages_id_autosave(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pages/{id}/autosave', options); }
  post_api_admin_pages_id_blocks(options: ApiRequestOptions<Request_post_api_admin_pages_id_blocks> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pages/{id}/blocks', options); }
  post_api_admin_pages_id_merge_revision(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pages/{id}/merge/{revision}', options); }
  post_api_admin_pages_id_publish(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pages/{id}/publish', options); }
  post_api_admin_pages_id_restore_revision(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pages/{id}/restore/{revision}', options); }
  post_api_admin_payments_reconcile(options: ApiRequestOptions<Request_post_api_admin_payments_reconcile> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/payments/reconcile', options); }
  post_api_admin_pickup_points(options: ApiRequestOptions<Request_post_api_admin_pickup_points> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/pickup-points', options); }
  post_api_admin_product_components(options: ApiRequestOptions<Request_post_api_admin_product_components> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/product-components', options); }
  post_api_admin_products(options: ApiRequestOptions<Request_post_api_admin_products> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products', options); }
  post_api_admin_products_id_booking_slots(options: ApiRequestOptions<Request_post_api_admin_products_id_booking_slots> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{id}/booking-slots', options); }
  post_api_admin_products_id_lifecycle(options: ApiRequestOptions<Request_post_api_admin_products_id_lifecycle> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{id}/lifecycle', options); }
  post_api_admin_products_id_media(options: ApiRequestOptions<Request_post_api_admin_products_id_media> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{id}/media', options); }
  post_api_admin_products_id_options(options: ApiRequestOptions<Request_post_api_admin_products_id_options> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{id}/options', options); }
  post_api_admin_products_id_variants(options: ApiRequestOptions<Request_post_api_admin_products_id_variants> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{id}/variants', options); }
  post_api_admin_products_id_voucher_codes(options: ApiRequestOptions<Request_post_api_admin_products_id_voucher_codes> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{id}/voucher-codes', options); }
  post_api_admin_products_import(options: ApiRequestOptions<Request_post_api_admin_products_import> = {}): Promise<ApiEnvelope<ProductCsvImportResult>> { return this.request<ApiEnvelope<ProductCsvImportResult>>('POST', '/api/admin/products/import', options); }
  post_api_admin_products_product_options_option_values(options: ApiRequestOptions<Request_post_api_admin_products_product_options_option_values> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/products/{product}/options/{option}/values', options); }
  post_api_admin_promotion_rules(options: ApiRequestOptions<Request_post_api_admin_promotion_rules> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/promotion-rules', options); }
  post_api_admin_redirects(options: ApiRequestOptions<Request_post_api_admin_redirects> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/redirects', options); }
  post_api_admin_returns(options: ApiRequestOptions<Request_post_api_admin_returns> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns', options); }
  post_api_admin_returns_id_approve(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns/{id}/approve', options); }
  post_api_admin_returns_id_approve_refund(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns/{id}/approve-refund', options); }
  post_api_admin_returns_id_complete(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns/{id}/complete', options); }
  post_api_admin_returns_id_inspect(options: ApiRequestOptions<Request_post_api_admin_returns_id_inspect> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns/{id}/inspect', options); }
  post_api_admin_returns_id_label(options: ApiRequestOptions<Request_post_api_admin_returns_id_label> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns/{id}/label', options); }
  post_api_admin_returns_id_reject(options: ApiRequestOptions<Request_post_api_admin_returns_id_reject> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/returns/{id}/reject', options); }
  post_api_admin_reviews_id_moderate(options: ApiRequestOptions<Request_post_api_admin_reviews_id_moderate> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/reviews/{id}/moderate', options); }
  post_api_admin_search_seo_sitemaps_generate(options: ApiRequestOptions<Request_post_api_admin_search_seo_sitemaps_generate> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/search-seo/sitemaps/generate', options); }
  post_api_admin_search_seo_synonyms(options: ApiRequestOptions<Request_post_api_admin_search_seo_synonyms> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/search-seo/synonyms', options); }
  post_api_admin_search_seo_url_rewrites(options: ApiRequestOptions<Request_post_api_admin_search_seo_url_rewrites> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/search-seo/url-rewrites', options); }
  post_api_admin_smart_execute(options: ApiRequestOptions<Request_post_api_admin_smart_execute> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/smart/execute', options); }
  post_api_admin_smart_rescue_id(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/smart/rescue/{id}', options); }
  post_api_admin_storefront_config_apply_preset(options: ApiRequestOptions<Request_post_api_admin_storefront_config_apply_preset> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/apply-preset', options); }
  post_api_admin_storefront_config_import(options: ApiRequestOptions<Request_post_api_admin_storefront_config_import> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/import', options); }
  post_api_admin_storefront_config_import_theme(options: ApiRequestOptions<Request_post_api_admin_storefront_config_import_theme> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/import-theme', options); }
  post_api_admin_storefront_config_import_theme_archive(options: ApiRequestOptions<Request_post_api_admin_storefront_config_import_theme_archive> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/import-theme-archive', options); }
  post_api_admin_storefront_config_preview(options: ApiRequestOptions<Request_post_api_admin_storefront_config_preview> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/preview', options); }
  post_api_admin_storefront_config_publish(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/publish', options); }
  post_api_admin_storefront_config_restore_revision(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/storefront/config/restore/{revision}', options); }
  post_api_admin_stores(options: ApiRequestOptions<Request_post_api_admin_stores> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/stores', options); }
  post_api_admin_tax_rules(options: ApiRequestOptions<Request_post_api_admin_tax_rules> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/tax-rules', options); }
  post_api_admin_vendor_payouts_id_cancel(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/vendor-payouts/{id}/cancel', options); }
  post_api_admin_vendor_payouts_id_mark_paid(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/vendor-payouts/{id}/mark-paid', options); }
  post_api_admin_vendor_payouts_reconcile(options: ApiRequestOptions<Request_post_api_admin_vendor_payouts_reconcile> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/vendor-payouts/reconcile', options); }
  post_api_admin_vendors(options: ApiRequestOptions<Request_post_api_admin_vendors> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/vendors', options); }
  post_api_admin_vendors_id_payouts(options: ApiRequestOptions<Request_post_api_admin_vendors_id_payouts> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/admin/vendors/{id}/payouts', options); }
  post_api_analytics_track(options: ApiRequestOptions<Request_post_api_analytics_track> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/analytics/track', options); }
  post_api_auth_forgot_password(options: ApiRequestOptions<Request_post_api_auth_forgot_password> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/forgot-password', options); }
  post_api_auth_login(options: ApiRequestOptions<Request_post_api_auth_login> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/login', options); }
  post_api_auth_logout(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/logout', options); }
  post_api_auth_refresh(options: ApiRequestOptions<Request_post_api_auth_refresh> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/refresh', options); }
  post_api_auth_register(options: ApiRequestOptions<Request_post_api_auth_register> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/register', options); }
  post_api_auth_reset_password(options: ApiRequestOptions<Request_post_api_auth_reset_password> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/reset-password', options); }
  post_api_auth_verify_email(options: ApiRequestOptions<Request_post_api_auth_verify_email> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/verify-email', options); }
  post_api_auth_verify_email_resend(options: ApiRequestOptions<Request_post_api_auth_verify_email_resend> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/auth/verify-email/resend', options); }
  post_api_carts(options: ApiRequestOptions<Request_post_api_carts> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/carts', options); }
  post_api_carts_id_lines(options: ApiRequestOptions<Request_post_api_carts_id_lines> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/carts/{id}/lines', options); }
  post_api_categories(options: ApiRequestOptions<Request_post_api_categories> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/categories', options); }
  post_api_checkout_place(options: ApiRequestOptions<Request_post_api_checkout_place> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/checkout/place', options); }
  post_api_consent(options: ApiRequestOptions<Request_post_api_consent> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/consent', options); }
  post_api_gift_preview(options: ApiRequestOptions<Request_post_api_gift_preview> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/gift/preview', options); }
  post_api_headless_graphql(options: ApiRequestOptions<Request_post_api_headless_graphql> = {}): Promise<ApiEnvelope<unknown>> { return this.request<ApiEnvelope<unknown>>('POST', '/api/headless/graphql', options); }
  post_api_orders_recover(options: ApiRequestOptions<Request_post_api_orders_recover> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/orders/recover', options); }
  post_api_posts(options: ApiRequestOptions<Request_post_api_posts> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/posts', options); }
  post_api_products(options: ApiRequestOptions<Request_post_api_products> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/products', options); }
  post_api_products_id_reviews(options: ApiRequestOptions<Request_post_api_products_id_reviews> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/products/{id}/reviews', options); }
  post_api_products_id_stock_alerts(options: ApiRequestOptions<Request_post_api_products_id_stock_alerts> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/products/{id}/stock-alerts', options); }
  post_api_products_stock_alerts_unsubscribe(options: ApiRequestOptions<Request_post_api_products_stock_alerts_unsubscribe> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/products/stock-alerts/unsubscribe', options); }
  post_api_promotions_preview(options: ApiRequestOptions<Request_post_api_promotions_preview> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/promotions/preview', options); }
  post_api_storefront_shipping_estimate(options: ApiRequestOptions<Request_post_api_storefront_shipping_estimate> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/storefront/shipping-estimate', options); }
  post_api_tags(options: ApiRequestOptions<Request_post_api_tags> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/tags', options); }
  post_api_upload(options: ApiRequestOptions<Request_post_api_upload> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/upload', options); }
  post_api_upload_avatar(options: ApiRequestOptions<Request_post_api_upload_avatar> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/upload/avatar', options); }
  post_api_users(options: ApiRequestOptions<Request_post_api_users> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/users', options); }
  post_api_v2_admin_ai_copilot_queue_id_approve(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/ai/copilot/queue/{id}/approve', options); }
  post_api_v2_admin_ai_copilot_queue_id_reject(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/ai/copilot/queue/{id}/reject', options); }
  post_api_v2_admin_ai_copilot_tasks(options: ApiRequestOptions<Request_post_api_v2_admin_ai_copilot_tasks> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/ai/copilot/tasks', options); }
  post_api_v2_admin_ai_suggestions(options: ApiRequestOptions<Request_post_api_v2_admin_ai_suggestions> = {}): Promise<ApiEnvelope<AiSuggestionEnvelope>> { return this.request<ApiEnvelope<AiSuggestionEnvelope>>('POST', '/api/v2/admin/ai/suggestions', options); }
  post_api_v2_admin_catalog_imports(options: ApiRequestOptions<Request_post_api_v2_admin_catalog_imports> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/catalog/imports', options); }
  post_api_v2_admin_commissions(options: ApiRequestOptions<Request_post_api_v2_admin_commissions> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/commissions', options); }
  post_api_v2_admin_erp_loyalty_evaluate(options: ApiRequestOptions<Request_post_api_v2_admin_erp_loyalty_evaluate> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/erp/loyalty-evaluate', options); }
  post_api_v2_admin_erp_subscriptions_id_renew(options: ApiRequestOptions<Request_post_api_v2_admin_erp_subscriptions_id_renew> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/erp/subscriptions/{id}/renew', options); }
  post_api_v2_admin_exports(options: ApiRequestOptions<Request_post_api_v2_admin_exports> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/exports', options); }
  post_api_v2_admin_exports_id_retry(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/exports/{id}/retry', options); }
  post_api_v2_admin_jobs(options: ApiRequestOptions<Request_post_api_v2_admin_jobs> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/jobs', options); }
  post_api_v2_admin_jobs_id_action(options: ApiRequestOptions<Request_post_api_v2_admin_jobs_id_action> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/jobs/{id}/{action}', options); }
  post_api_v2_admin_operations_id_replay_notification(options: ApiRequestOptions<Request_post_api_v2_admin_operations_id_replay_notification> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/operations/{id}/replay-notification', options); }
  post_api_v2_admin_operations_id_retry(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/operations/{id}/retry', options); }
  post_api_v2_admin_orders_id_seller_split(options: ApiRequestOptions<Request_post_api_v2_admin_orders_id_seller_split> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/orders/{id}/seller-split', options); }
  post_api_v2_admin_payouts(options: ApiRequestOptions<Request_post_api_v2_admin_payouts> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/payouts', options); }
  post_api_v2_admin_payouts_id_transition(options: ApiRequestOptions<Request_post_api_v2_admin_payouts_id_transition> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/payouts/{id}/transition', options); }
  post_api_v2_admin_products_id_approval(options: ApiRequestOptions<Request_post_api_v2_admin_products_id_approval> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/products/{id}/approval', options); }
  post_api_v2_admin_search_reindex(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/search/reindex', options); }
  post_api_v2_admin_subscription_plans(options: ApiRequestOptions<Request_post_api_v2_admin_subscription_plans> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/subscription-plans', options); }
  post_api_v2_admin_subscriptions_id_retry(options: ApiRequestOptions<Request_post_api_v2_admin_subscriptions_id_retry> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/subscriptions/{id}/retry', options); }
  post_api_v2_admin_vendors(options: ApiRequestOptions<Request_post_api_v2_admin_vendors> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/vendors', options); }
  post_api_v2_admin_vendors_id_status(options: ApiRequestOptions<Request_post_api_v2_admin_vendors_id_status> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/vendors/{id}/status', options); }
  post_api_v2_admin_vendors_vendor_catalog(options: ApiRequestOptions<Request_post_api_v2_admin_vendors_vendor_catalog> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/vendors/{vendor}/catalog', options); }
  post_api_v2_admin_warehouses_reservations(options: ApiRequestOptions<Request_post_api_v2_admin_warehouses_reservations> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/warehouses/reservations', options); }
  post_api_v2_admin_warehouses_reservations_id_release(options: ApiRequestOptions<Request_post_api_v2_admin_warehouses_reservations_id_release> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/warehouses/reservations/{id}/release', options); }
  post_api_v2_admin_warehouses_transfers(options: ApiRequestOptions<Request_post_api_v2_admin_warehouses_transfers> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/warehouses/transfers', options); }
  post_api_v2_admin_workbench_approvals(options: ApiRequestOptions<Request_post_api_v2_admin_workbench_approvals> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/workbench/approvals', options); }
  post_api_v2_admin_workbench_approvals_id_decision(options: ApiRequestOptions<Request_post_api_v2_admin_workbench_approvals_id_decision> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/workbench/approvals/{id}/decision', options); }
  post_api_v2_admin_workbench_bulk_orders(options: ApiRequestOptions<Request_post_api_v2_admin_workbench_bulk_orders> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/workbench/bulk-orders', options); }
  post_api_v2_admin_workbench_views(options: ApiRequestOptions<Request_post_api_v2_admin_workbench_views> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/workbench/views', options); }
  post_api_v2_admin_workbench_workflows(options: ApiRequestOptions<Request_post_api_v2_admin_workbench_workflows> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/admin/workbench/workflows', options); }
  post_api_v2_b2b_companies_id_orders_validate_bulk(options: ApiRequestOptions<Request_post_api_v2_b2b_companies_id_orders_validate_bulk> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/companies/{id}/orders/validate-bulk', options); }
  post_api_v2_b2b_companies_id_purchase_orders(options: ApiRequestOptions<Request_post_api_v2_b2b_companies_id_purchase_orders> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/companies/{id}/purchase-orders', options); }
  post_api_v2_b2b_companies_id_quotes(options: ApiRequestOptions<Request_post_api_v2_b2b_companies_id_quotes> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/companies/{id}/quotes', options); }
  post_api_v2_b2b_contracts_id_approve(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/contracts/{id}/approve', options); }
  post_api_v2_b2b_orders_id_approve(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/orders/{id}/approve', options); }
  post_api_v2_b2b_orders_id_approve_company(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/orders/{id}/approve-company', options); }
  post_api_v2_b2b_orders_id_draft(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/orders/{id}/draft', options); }
  post_api_v2_b2b_orders_id_reject(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/orders/{id}/reject', options); }
  post_api_v2_b2b_orders_id_submit(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/orders/{id}/submit', options); }
  post_api_v2_b2b_orders_id_transition(options: ApiRequestOptions<Request_post_api_v2_b2b_orders_id_transition> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/orders/{id}/transition', options); }
  post_api_v2_b2b_purchase_orders_id_action(options: ApiRequestOptions<Request_post_api_v2_b2b_purchase_orders_id_action> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/purchase-orders/{id}/{action}', options); }
  post_api_v2_b2b_quotes_id_action(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/quotes/{id}/{action}', options); }
  post_api_v2_b2b_quotes_id_convert(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/b2b/quotes/{id}/convert', options); }
  post_api_v2_checkout_quote(options: ApiRequestOptions<Request_post_api_v2_checkout_quote> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/checkout/quote', options); }
  post_api_v2_headless_pages(options: ApiRequestOptions<Request_post_api_v2_headless_pages> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/headless/pages', options); }
  post_api_v2_inventory_variant_atp(options: ApiRequestOptions<Request_post_api_v2_inventory_variant_atp> = {}): Promise<ApiEnvelope<InventoryReservation>> { return this.request<ApiEnvelope<InventoryReservation>>('POST', '/api/v2/inventory/{variant}/atp', options); }
  post_api_v2_payments_id_authorize(options: ApiRequestOptions<Request_post_api_v2_payments_id_authorize> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/payments/{id}/authorize', options); }
  post_api_v2_payments_id_capture(options: ApiRequestOptions<Request_post_api_v2_payments_id_capture> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/payments/{id}/capture', options); }
  post_api_v2_payments_id_proof(options: ApiRequestOptions<Request_post_api_v2_payments_id_proof> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/payments/{id}/proof', options); }
  post_api_v2_payments_id_refund(options: ApiRequestOptions<Request_post_api_v2_payments_id_refund> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/payments/{id}/refund', options); }
  post_api_v2_payments_id_void(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/payments/{id}/void', options); }
  post_api_v2_shipments(options: ApiRequestOptions<Request_post_api_v2_shipments> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/shipments', options); }
  post_api_v2_shipments_id_cancel(options: ApiRequestOptions = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/shipments/{id}/cancel', options); }
  post_api_v2_shipping_rates(options: ApiRequestOptions<Request_post_api_v2_shipping_rates> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/v2/shipping/rates', options); }
  post_api_webhooks_erp_loyalty(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/erp/loyalty', options); }
  post_api_webhooks_erp_marketplace(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/erp/marketplace', options); }
  post_api_webhooks_erp_pos(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/erp/pos', options); }
  post_api_webhooks_erp_subscription(options: ApiRequestOptions = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/erp/subscription', options); }
  post_api_webhooks_provider(options: ApiRequestOptions<Request_post_api_webhooks_provider> = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/{provider}', options); }
  post_api_webhooks_shipping_dhl(options: ApiRequestOptions<Request_post_api_webhooks_shipping_dhl> = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/shipping/dhl', options); }
  post_api_webhooks_shipping_provider(options: ApiRequestOptions<Request_post_api_webhooks_shipping_provider> = {}): Promise<ApiEnvelope<ProviderWebhookResult>> { return this.request<ApiEnvelope<ProviderWebhookResult>>('POST', '/api/webhooks/shipping/{provider}', options); }
  post_api_wishlist(options: ApiRequestOptions<Request_post_api_wishlist> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('POST', '/api/wishlist', options); }
  put_api_account_notification_preferences(options: ApiRequestOptions<Request_put_api_account_notification_preferences> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/account/notification-preferences', options); }
  put_api_admin_b2b_companies_id_terms(options: ApiRequestOptions<Request_put_api_admin_b2b_companies_id_terms> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/b2b/companies/{id}/terms', options); }
  put_api_admin_blocks_id(options: ApiRequestOptions<Request_put_api_admin_blocks_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/blocks/{id}', options); }
  put_api_admin_configuration_group_section(options: ApiRequestOptions<Request_put_api_admin_configuration_group_section> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/configuration/{group}/{section}', options); }
  put_api_admin_coupons_id(options: ApiRequestOptions<Request_put_api_admin_coupons_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/coupons/{id}', options); }
  put_api_admin_flash_sales_id(options: ApiRequestOptions<Request_put_api_admin_flash_sales_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/flash-sales/{id}', options); }
  put_api_admin_inventory_variant(options: ApiRequestOptions<Request_put_api_admin_inventory_variant> = {}): Promise<ApiEnvelope<InventoryItem>> { return this.request<ApiEnvelope<InventoryItem>>('PUT', '/api/admin/inventory/{variant}', options); }
  put_api_admin_inventory_variant_priorities(options: ApiRequestOptions<Request_put_api_admin_inventory_variant_priorities> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/inventory/{variant}/priorities', options); }
  put_api_admin_member_deals_id(options: ApiRequestOptions<Request_put_api_admin_member_deals_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/member-deals/{id}', options); }
  put_api_admin_pages_id(options: ApiRequestOptions<Request_put_api_admin_pages_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/pages/{id}', options); }
  put_api_admin_pickup_points_code(options: ApiRequestOptions<Request_put_api_admin_pickup_points_code> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/pickup-points/{code}', options); }
  put_api_admin_products_id(options: ApiRequestOptions<Request_put_api_admin_products_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/products/{id}', options); }
  put_api_admin_products_id_media_order(options: ApiRequestOptions<Request_put_api_admin_products_id_media_order> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/products/{id}/media/order', options); }
  put_api_admin_promotion_rules_id(options: ApiRequestOptions<Request_put_api_admin_promotion_rules_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/promotion-rules/{id}', options); }
  put_api_admin_search_seo_sitemaps(options: ApiRequestOptions<Request_put_api_admin_search_seo_sitemaps> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/search-seo/sitemaps', options); }
  put_api_admin_search_seo_synonyms_id(options: ApiRequestOptions<Request_put_api_admin_search_seo_synonyms_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/search-seo/synonyms/{id}', options); }
  put_api_admin_search_seo_url_rewrites_id(options: ApiRequestOptions<Request_put_api_admin_search_seo_url_rewrites_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/search-seo/url-rewrites/{id}', options); }
  put_api_admin_seo_type_id(options: ApiRequestOptions<Request_put_api_admin_seo_type_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/seo/{type}/{id}', options); }
  put_api_admin_storefront_config(options: ApiRequestOptions<Request_put_api_admin_storefront_config> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/storefront/config', options); }
  put_api_admin_tax_rules_id(options: ApiRequestOptions<Request_put_api_admin_tax_rules_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/tax-rules/{id}', options); }
  put_api_admin_vendors_id(options: ApiRequestOptions<Request_put_api_admin_vendors_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/admin/vendors/{id}', options); }
  put_api_auth_me(options: ApiRequestOptions<Request_put_api_auth_me> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/auth/me', options); }
  put_api_categories_id(options: ApiRequestOptions<Request_put_api_categories_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/categories/{id}', options); }
  put_api_posts_id(options: ApiRequestOptions<Request_put_api_posts_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/posts/{id}', options); }
  put_api_products_id(options: ApiRequestOptions<Request_put_api_products_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/products/{id}', options); }
  put_api_profile(options: ApiRequestOptions<Request_put_api_profile> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/profile', options); }
  put_api_profile_password(options: ApiRequestOptions<Request_put_api_profile_password> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/profile/password', options); }
  put_api_settings(options: ApiRequestOptions<Request_put_api_settings> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/settings', options); }
  put_api_tags_id(options: ApiRequestOptions<Request_put_api_tags_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/tags/{id}', options); }
  put_api_users_id(options: ApiRequestOptions<Request_put_api_users_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/users/{id}', options); }
  put_api_v2_admin_campaign_automation(options: ApiRequestOptions<Request_put_api_v2_admin_campaign_automation> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/v2/admin/campaign-automation', options); }
  put_api_v2_admin_subscription_plans_id(options: ApiRequestOptions<Request_put_api_v2_admin_subscription_plans_id> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/v2/admin/subscription-plans/{id}', options); }
  put_api_v2_admin_workbench_permissions_role(options: ApiRequestOptions<Request_put_api_v2_admin_workbench_permissions_role> = {}): Promise<ApiEnvelope<WriteResult>> { return this.request<ApiEnvelope<WriteResult>>('PUT', '/api/v2/admin/workbench/permissions/{role}', options); }
}
