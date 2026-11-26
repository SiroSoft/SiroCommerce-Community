import api from "./api";

export type RecordValue = Record<string, unknown>;
export type ApiList = RecordValue[];

const envelope = <T>(value: { data?: T }) => value.data;

export const fe1Service = {
  async productTypes() {
    const response = await api.get<{ data?: RecordValue[] }>("/api/v2/catalog/product-types");
    return envelope(response.data) ?? [];
  },
  async options(productId: number) {
    const response = await api.get<{ data?: RecordValue[] }>(`/api/admin/products/${productId}/options`);
    return envelope(response.data) ?? [];
  },
  async createOption(productId: number, body: { name: string; sort?: number }) {
    const response = await api.post<{ data?: RecordValue }>(`/api/admin/products/${productId}/options`, body, { idempotencyKey: `option_${productId}_${body.name.toLowerCase()}` });
    return envelope(response.data);
  },
  async addOptionValue(productId: number, optionId: string, body: { value: string; sort?: number }) {
    const response = await api.post<{ data?: RecordValue }>(`/api/admin/products/${productId}/options/${optionId}/values`, body, { idempotencyKey: `option-value_${productId}_${optionId}_${body.value.toLowerCase()}` });
    return envelope(response.data);
  },
  async media(productId: string | number) {
    const response = await api.get<{ data?: RecordValue[] }>(`/api/admin/products/${encodeURIComponent(String(productId))}/media`);
    return envelope(response.data) ?? [];
  },
  async addMedia(productId: string | number, body: { url: string; alt?: string; title?: string; role?: string; type?: string }) {
    const response = await api.post<{ data?: RecordValue }>(`/api/admin/products/${encodeURIComponent(String(productId))}/media`, body, { idempotencyKey: `media_${productId}_${body.type ?? "image"}_${body.url}` });
    return envelope(response.data);
  },
  async reorderMedia(productId: string | number, mediaIds: string[]) {
    const response = await api.put<{ data?: RecordValue }>(`/api/admin/products/${encodeURIComponent(String(productId))}/media/order`, { media_ids: mediaIds }, { idempotencyKey: `media-order_${productId}_${mediaIds.join(",")}` });
    return envelope(response.data);
  },
  async deleteMedia(productId: string | number, mediaId: string) {
    await api.delete(`/api/admin/products/${encodeURIComponent(String(productId))}/media/${encodeURIComponent(mediaId)}`);
  },
  async variants(productId: string | number) {
    const response = await api.get<{ data?: RecordValue[] }>(`/api/admin/products/${encodeURIComponent(String(productId))}/variants`);
    return envelope(response.data) ?? [];
  },
  async createVariant(productId: string | number, body: { sku: string; price_minor: number; currency?: string; compare_price_minor?: number; option_value_ids?: string[] }) {
    const response = await api.post<{ data?: RecordValue }>(`/api/admin/products/${encodeURIComponent(String(productId))}/variants`, body, { idempotencyKey: `variant_${productId}_${body.sku}` });
    return envelope(response.data);
  },
  async coupons() {
    const response = await api.get<{ data?: RecordValue[] }>("/api/admin/coupons");
    return envelope(response.data) ?? [];
  },
  async createCoupon(body: { code: string; rules?: string }) {
    const response = await api.post<{ data?: RecordValue }>("/api/admin/coupons", body, { idempotencyKey: `coupon_${body.code.toUpperCase()}` });
    return envelope(response.data);
  },
  async disableCoupon(id: string) {
    await api.post(`/api/admin/coupons/${id}/disable`, undefined, { idempotencyKey: `coupon-disable_${id}` });
  },
  async enableCoupon(id: string) {
    await api.post(`/api/admin/coupons/${id}/enable`, undefined, { idempotencyKey: `coupon-enable_${id}` });
  },
  async couponVersions(id: string) {
    const response = await api.get<{ data?: RecordValue[] }>(`/api/admin/coupons/${encodeURIComponent(id)}/versions`);
    return envelope(response.data) ?? [];
  },
  async couponUsage(id: string) {
    const response = await api.get<{ data?: RecordValue }>(`/api/admin/coupons/${encodeURIComponent(id)}/usage`);
    return envelope(response.data);
  },
  async couponPnl() {
    const response = await api.get<{ data?: RecordValue[] }>(`/api/admin/coupons/pnl`);
    return envelope(response.data) ?? [];
  },
  async bulkCoupons(body: { pattern: string; count: number; start?: number; rules?: string }) {
    const response = await api.post<{ data?: RecordValue }>("/api/admin/coupons/bulk", body, { idempotencyKey: `coupon-bulk_${body.pattern}_${body.count}` });
    return envelope(response.data);
  },
  async bulkCouponStatus(id: string) {
    const response = await api.get<{ data?: RecordValue }>(`/api/admin/coupons/bulk/${encodeURIComponent(id)}`);
    return envelope(response.data);
  },
  async bookingSlots(variantId: string) {
    const response = await api.get<{ data?: RecordValue[] }>(`/api/admin/products/${encodeURIComponent(variantId)}/booking-slots`);
    return envelope(response.data) ?? [];
  },
  async createBookingSlot(variantId: string, body: { starts_at: string; ends_at: string; capacity: number; price_minor?: number; currency?: string }) {
    const response = await api.post<{ data?: RecordValue }>(`/api/admin/products/${encodeURIComponent(variantId)}/booking-slots`, body, { idempotencyKey: `booking-${variantId}-${body.starts_at}` });
    return envelope(response.data);
  },
  async previewPromotion(body: { code: string; subtotal_minor: number; currency?: string }) {
    const response = await api.post<{ data?: RecordValue }>('/api/promotions/preview', body);
    return envelope(response.data);
  },
  async products(params?: Record<string, unknown>) {
    const response = await api.get<{ data?: ApiList }>("/api/admin/products", { params });
    return envelope(response.data) ?? [];
  },
  async updateProductLifecycle(id: string, body: { status: string; version?: number }) {
    const response = await api.put<{ data?: RecordValue }>(`/api/admin/products/${encodeURIComponent(id)}`, body, { idempotencyKey: `product-lifecycle_${id}_${body.status}` });
    return envelope(response.data);
  },
  async approveProduct(id: string, body: { approval_status: "pending" | "approved" | "rejected"; version?: number }) {
    const response = await api.post<{ data?: RecordValue }>(`/api/v2/admin/products/${encodeURIComponent(id)}/approval`, body, { idempotencyKey: `product-approval_${id}_${body.approval_status}` });
    return envelope(response.data);
  },
  async pages(params?: Record<string, unknown>) {
    const response = await api.get<{ data?: ApiList }>("/api/admin/pages", { params });
    return envelope(response.data) ?? [];
  },
  async publishPage(id: string, body: RecordValue = {}) {
    const response = await api.post<{ data?: RecordValue }>(`/api/admin/pages/${encodeURIComponent(id)}/publish`, body, { idempotencyKey: `page-publish_${id}_${JSON.stringify(body)}` });
    return envelope(response.data);
  },
  async stores() {
    const response = await api.get<{ data?: ApiList }>("/api/admin/stores");
    return envelope(response.data) ?? [];
  },
  async storeChannels(store: string) {
    const response = await api.get<{ data?: ApiList }>(`/api/admin/stores/${encodeURIComponent(store)}/channels`);
    return envelope(response.data) ?? [];
  },
  async exportStatus(id: string) {
    const response = await api.get<{ data?: RecordValue }>(`/api/v2/admin/exports/${encodeURIComponent(id)}`);
    return envelope(response.data);
  },
  async events(params?: Record<string, unknown>) {
    const response = await api.get<{ data?: ApiList }>("/api/admin/analytics/events", { params });
    return envelope(response.data) ?? [];
  },
  async notificationPreferences() {
    const response = await api.get<{ data?: RecordValue }>("/api/account/notification-preferences");
    return envelope(response.data);
  },
  async startExport(body: { dataset: string; format: string }) {
    const response = await api.post<{ data?: RecordValue }>("/api/v2/admin/exports", body, { idempotencyKey: `export_${body.dataset}_${body.format}` });
    return envelope(response.data);
  },
  async retryExport(id: string) {
    const response = await api.post<{ data?: RecordValue }>(`/api/v2/admin/exports/${id}/retry`, undefined, { idempotencyKey: `export-retry_${id}` });
    return envelope(response.data);
  },
  async importCatalog(body: { format: string; source: string; validate_only?: boolean }) {
    const response = await api.post<{ data?: RecordValue }>("/api/v2/admin/catalog/imports", body, { idempotencyKey: `import_${body.format}_${body.source}` });
    return envelope(response.data);
  },
};
