import { ApiClientError, RequestCoordinator } from "@sirocommerce/api-client";
import type { CommerceScope } from "@sirocommerce/scope";

export type WishlistItem = { id?: string | number; product_id: string; variant_id?: string | null; created_at?: string };
export type Review = { id?: string | number; product_id: string; variant_id?: string | null; rating: number; title?: string | null; body: string; status?: string; verified_purchase?: boolean; created_at?: string; updated_at?: string };
export type Loyalty = { id?: string | number; balance_points: number; state?: string; created_at?: string; updated_at?: string };
export type ReturnItem = { id?: string | number; return_id?: string | number; order_line_id?: string | number; qty?: number; reason?: string; disposition?: string; condition?: string };
export type CustomerReturn = { id: string | number; order_id?: string | number; fulfillment_id?: string | number; state?: string; reason?: string; refund_state?: string; refund_amount_minor?: number; refund_currency?: string; refund_error?: string | null; created_at?: string; completed_at?: string | null; label_state?: string; label_provider?: string; label_provider_ref?: string; label_tracking?: string; label_url?: string; lines?: ReturnItem[] };
export type FinancialDocument = { id: string; number: string; state: string; currency: string; minor_unit: number; amount_minor: number; reason?: string; subtotal_minor?: number; discount_minor?: number; shipping_minor?: number; tax_minor?: number; issued_at?: string | null; created_at?: string | null; download?: { available: boolean; reason?: string; content_type?: string; filename?: string } };
export type FinancialDocuments = { order_id: string; currency: string; totals: { grand_total_minor: number; refunded_minor: number }; invoices: FinancialDocument[]; receipts: FinancialDocument[]; credit_memos: FinancialDocument[]; refund_timeline: Array<Pick<FinancialDocument, "id" | "state" | "currency" | "minor_unit" | "amount_minor" | "reason" | "created_at">>; available: boolean };
export type ReturnRequestInput = { fulfillment_id: string; lines: Array<{ order_line_id: string; qty: number; reason?: string; condition?: string }>; reason?: string };
export type Address = { id?: string | number; label?: string; line1: string; city: string; phone: string };
export type NotificationPreferences = { transactional_email: boolean; marketing_email: boolean; updated_at?: string };
export type MessageHistoryItem = { event: string; status: "sent" | "processing" | "not_delivered"; sent_at?: string | null; created_at?: string | null };
export type CustomerSubscription = { id: string; customer_id?: string; plan_id?: string; status: "active" | "paused" | "cancelled" | "unknown"; product_name?: string; interval: string; interval_count?: number; next_charge_at?: string; started_at?: string; paused_at?: string; cancelled_at?: string; price: { amount_minor: number; currency: string }; renewal_status?: "pending" | "succeeded" | "failed" | "retryable" | "unknown" };
export type PrivacyExport = { customer: Record<string, unknown>; orders: Record<string, unknown>[]; addresses: Record<string, unknown>[]; notification_preferences: Record<string, unknown>; consents: Record<string, unknown>[] };
export type ReviewInput = { order_id: string; order_line_id: string; rating: number; title?: string; body: string };

export interface CustomerAdapter {
  getWishlist(): Promise<WishlistItem[]>;
  addWishlist(productId: string, variantId?: string): Promise<WishlistItem>;
  removeWishlist(productId: string): Promise<void>;
  getReviews(productId: string): Promise<Review[]>;
  submitReview(productId: string, input: ReviewInput): Promise<Review>;
  getLoyalty(): Promise<Loyalty>;
  earnLoyalty(orderId: string): Promise<unknown>;
  redeemLoyalty(points: number): Promise<unknown>;
  getReturns(): Promise<CustomerReturn[]>;
  getReturn(id: string): Promise<CustomerReturn>;
  getFinancialDocuments(orderId: string): Promise<FinancialDocuments>;
  requestReturn(orderId: string, input: ReturnRequestInput): Promise<CustomerReturn>;
  getAddresses(): Promise<Address[]>;
  addAddress(input: Omit<Address, "id">): Promise<Address>;
  getNotificationPreferences(): Promise<NotificationPreferences>;
  updateNotificationPreferences(input: Partial<NotificationPreferences>): Promise<NotificationPreferences>;
  getMessageHistory(page?: number): Promise<{ items: MessageHistoryItem[]; page: number; last_page: number }>;
  exportPrivacy(): Promise<PrivacyExport>;
  requestDeletion(): Promise<{ customer_id: string; state: string; financial_records: string }>;
  recordConsent(granted: boolean): Promise<unknown>;
  getSubscriptions(): Promise<CustomerSubscription[]>;
  getSubscription(id: string): Promise<CustomerSubscription>;
  startSubscription(planId: string, consent: boolean): Promise<unknown>;
  transitionSubscription(id: string, action: "pause" | "resume" | "cancel", consent: boolean): Promise<unknown>;
  renewSubscription(id: string): Promise<unknown>;
}

let activeCustomerAccessToken: string | undefined;
export function setCustomerAccessToken(token: string | undefined) { activeCustomerAccessToken = token || undefined; if (typeof window !== "undefined") window.dispatchEvent(new Event("siro:customer-session")); }
export function getCustomerAccessToken() { return activeCustomerAccessToken ?? ""; }

export class CustomerRequestError extends Error {
  constructor(readonly status: number, message: string) { super(message); this.name = "CustomerRequestError"; }
}

const fixtureWishlist: WishlistItem[] = [{ product_id: "linen-overshirt" }, { product_id: "stoneware-mug" }];
const fixtureLoyalty: Loyalty = { balance_points: 240, state: "active" };
const fixturePreferences: NotificationPreferences = { transactional_email: true, marketing_email: false };
const fixtureSubscriptions: CustomerSubscription[] = [{ id: "subscription-fixture-1", customer_id: "fixture-customer", plan_id: "plan-monthly", status: "active", product_name: "Siro monthly delivery", interval: "month", interval_count: 1, next_charge_at: "2099-02-01", price: { amount_minor: 125000, currency: "VND" }, renewal_status: "succeeded" }];
function normalizeSubscription(value: CustomerSubscription): CustomerSubscription {
  const source = value as CustomerSubscription & { state?: string; amount_minor?: number; currency?: string; next_billing_at?: string };
  const state = source.status ?? source.state;
  return { ...source, status: state === "active" || state === "paused" || state === "cancelled" ? state : "unknown", next_charge_at: source.next_charge_at ?? source.next_billing_at, price: source.price ?? { amount_minor: source.amount_minor ?? 0, currency: source.currency ?? "USD" } };
}

export const fixtureCustomer: CustomerAdapter = {
  async getWishlist() { return fixtureWishlist.map((item) => ({ ...item })); },
  async addWishlist(productId, variantId) { const item = { product_id: productId, ...(variantId ? { variant_id: variantId } : {}) }; if (!fixtureWishlist.some((value) => value.product_id === productId)) fixtureWishlist.push(item); return item; },
  async removeWishlist(productId) { const index = fixtureWishlist.findIndex((item) => item.product_id === productId); if (index >= 0) fixtureWishlist.splice(index, 1); },
  async getReviews() { return []; },
  async submitReview(productId, input) { return { id: `fixture-${Date.now()}`, product_id: productId, ...input, status: "pending" }; },
  async getLoyalty() { return { ...fixtureLoyalty }; },
  async earnLoyalty() { return { ...fixtureLoyalty }; },
  async redeemLoyalty(points) { fixtureLoyalty.balance_points = Math.max(0, fixtureLoyalty.balance_points - points); return { ...fixtureLoyalty }; },
  async getReturns() { return []; },
  async getReturn(id) { return { id, state: "pending" }; },
  async getFinancialDocuments(orderId) { return { order_id: orderId, currency: "VND", totals: { grand_total_minor: 0, refunded_minor: 0 }, invoices: [], receipts: [], credit_memos: [], refund_timeline: [], available: false }; },
  async requestReturn(orderId, input) { return { id: `fixture-return-${Date.now()}`, order_id: orderId, fulfillment_id: input.fulfillment_id, state: "requested", reason: input.reason, lines: input.lines }; },
  async getAddresses() { return []; },
  async addAddress(input) { return { id: `fixture-address-${Date.now()}`, ...input }; },
  async getNotificationPreferences() { return { ...fixturePreferences }; },
  async updateNotificationPreferences(input) { Object.assign(fixturePreferences, input); return { ...fixturePreferences }; },
  async getMessageHistory() { return { items: [], page: 1, last_page: 1 }; },
  async exportPrivacy() { return { customer: { id: "fixture-customer", name: "Fixture customer" }, orders: [], addresses: [], notification_preferences: { ...fixturePreferences }, consents: [] }; },
  async requestDeletion() { return { customer_id: "fixture-customer", state: "anonymized", financial_records: "retained" }; },
  async recordConsent(granted) { return { purpose: "analytics", granted, version: "storefront-privacy-v1", source: "account" }; },
  async getSubscriptions() { return fixtureSubscriptions.map((item) => ({ ...item, price: { ...item.price } })); },
  async getSubscription(id) { const item = fixtureSubscriptions.find((value) => value.id === id); if (!item) throw new CustomerRequestError(404, "Subscription not found"); return { ...item, price: { ...item.price } }; },
  async startSubscription(planId, consent) { if (!consent) throw new CustomerRequestError(422, "Consent is required"); return { id: `subscription-${planId}`, state: "active" }; },
  async transitionSubscription(id, action, consent) { if (!consent) throw new CustomerRequestError(422, "Consent is required"); const item = fixtureSubscriptions.find((value) => value.id === id); if (!item) throw new CustomerRequestError(404, "Subscription not found"); item.status = action === "pause" ? "paused" : action === "cancel" ? "cancelled" : "active"; return { subscription_id: id, state: item.status, action }; },
  async renewSubscription(id) { if (!fixtureSubscriptions.some((value) => value.id === id)) throw new CustomerRequestError(404, "Subscription not found"); return { subscription_id: id, state: "pending" }; },
};

function apiScope(): CommerceScope | undefined {
  const scope = { store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? process.env.STOREFRONT_STORE_ID, channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? process.env.STOREFRONT_CHANNEL_ID, locale: process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? process.env.STOREFRONT_LOCALE ?? "en", currency: process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? process.env.STOREFRONT_CURRENCY ?? "USD" };
  return scope.store_id && scope.channel_id ? scope as CommerceScope : undefined;
}

function idempotencyKey() { return `storefront-${Date.now()}-${Math.random().toString(36).slice(2)}`; }

export function createApiCustomer(options: { baseUrl: string; fetch?: typeof fetch; scope?: CommerceScope; accessToken?: string; credentials?: RequestCredentials }): CustomerAdapter {
  const client = new RequestCoordinator({ baseUrl: options.baseUrl, fetch: options.fetch, getAccessToken: () => options.accessToken ?? activeCustomerAccessToken, credentials: options.credentials });
  const scope = options.scope ?? apiScope();
  async function request<T>(path: string, method: "GET" | "POST" | "PUT" | "DELETE" = "GET", body?: unknown, idempotent = false): Promise<T> {
    try {
      const result = await client.request<T>(path, { method, body, scope, ...(idempotent ? { idempotencyKey: idempotencyKey() } : {}) });
      return result.body.data;
    } catch (error) {
      if (error instanceof ApiClientError) throw new CustomerRequestError(error.status, error.message);
      throw new CustomerRequestError(503, "The customer service is unavailable.");
    }
  }
  return {
    getWishlist: () => request<WishlistItem[]>("/api/wishlist"),
    addWishlist: (productId, variantId) => request<WishlistItem>("/api/wishlist", "POST", { product_id: productId, ...(variantId ? { variant_id: variantId } : {}) }),
    removeWishlist: async (productId) => { await request<unknown>(`/api/wishlist/${encodeURIComponent(productId)}`, "DELETE"); },
    getReviews: (productId) => request<Review[]>(`/api/products/${encodeURIComponent(productId)}/reviews`),
    submitReview: (productId, input) => request<Review>(`/api/products/${encodeURIComponent(productId)}/reviews`, "POST", input),
    getLoyalty: () => request<Loyalty>("/api/account/loyalty"),
    earnLoyalty: (orderId) => request<unknown>(`/api/account/loyalty/${encodeURIComponent(orderId)}/earn`, "POST", undefined, true),
    redeemLoyalty: (points) => request<unknown>("/api/account/loyalty/redeem", "POST", { points }, true),
    getReturns: () => request<CustomerReturn[]>("/api/account/returns"),
    getReturn: (id) => request<CustomerReturn>(`/api/account/returns/${encodeURIComponent(id)}`),
    getFinancialDocuments: (orderId) => request<FinancialDocuments>(`/api/account/orders/${encodeURIComponent(orderId)}/financial-documents`),
    requestReturn: (orderId, input) => request<CustomerReturn>(`/api/account/orders/${encodeURIComponent(orderId)}/returns`, "POST", input, true),
    getAddresses: () => request<Address[]>("/api/account/addresses"),
    addAddress: (input) => request<Address>("/api/account/addresses", "POST", input),
    getNotificationPreferences: () => request<NotificationPreferences>("/api/account/notification-preferences"),
    updateNotificationPreferences: (input) => request<NotificationPreferences>("/api/account/notification-preferences", "PUT", input),
    getMessageHistory: async (page = 1) => { try { const result = await client.request<MessageHistoryItem[]>(`/api/account/message-history?page=${page}&per_page=20`, { method: "GET", scope }); const meta = result.body.meta as { page?: number; last_page?: number } | undefined; return { items: result.body.data, page: meta?.page ?? page, last_page: meta?.last_page ?? page }; } catch (error) { if (error instanceof ApiClientError) throw new CustomerRequestError(error.status, error.message); throw new CustomerRequestError(503, "The customer service is unavailable."); } },
    exportPrivacy: () => request<PrivacyExport>("/api/account/privacy/export"),
    requestDeletion: () => request<{ customer_id: string; state: string; financial_records: string }>("/api/account/privacy/anonymize", "POST", undefined, true),
    recordConsent: (granted) => request<unknown>("/api/account/consents", "POST", { purpose: "analytics", version: "storefront-privacy-v1", source: "account", granted }, true),
    getSubscriptions: async () => { const result = await client.request<CustomerSubscription[]>("/api/account/subscriptions", { scope }); return result.body.data.map(normalizeSubscription); },
    getSubscription: async (id) => normalizeSubscription(await request<CustomerSubscription>(`/api/account/subscriptions/${encodeURIComponent(id)}`)),
    startSubscription: (planId, consent) => { if (!consent) return Promise.reject(new CustomerRequestError(422, "Consent is required before starting a subscription.")); return request<unknown>("/api/account/subscriptions", "POST", { plan_id: planId, consent: true }, true); },
    transitionSubscription: (id, action, consent) => { if (!consent) return Promise.reject(new CustomerRequestError(422, "Consent is required before changing a subscription.")); return request<unknown>(`/api/account/subscriptions/${encodeURIComponent(id)}/transition`, "POST", { action, consent: true }, true); },
    renewSubscription: (id) => request<unknown>(`/api/account/subscriptions/${encodeURIComponent(id)}/renew`, "POST", undefined, true),
  };
}

export function customer(): CustomerAdapter {
  if (process.env.STOREFRONT_CUSTOMER_SOURCE === "fixture" || process.env.STOREFRONT_CATALOG_SOURCE === "fixture") return fixtureCustomer;
  return createApiCustomer({ baseUrl: process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL ?? "http://127.0.0.1:1" });
}
