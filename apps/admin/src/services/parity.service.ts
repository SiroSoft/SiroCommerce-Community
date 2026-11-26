import api from "./api";
import { idempotencyKey } from "./merchant.service";

const envelope = <T>(v: { data?: T } | T) => (v as { data?: T }).data ?? v as T;

export const parityService = {
  // Jobs parity
  async enqueueJob(body: { type: string; payload: Record<string, unknown> }) {
    const res = await api.post<{ data: unknown }>("/api/v2/admin/jobs", body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async job(id: string) {
    const res = await api.get<{ data: unknown }>(`/api/v2/admin/jobs/${encodeURIComponent(id)}`);
    return envelope(res.data);
  },
  async jobAction(id: string, action: string, body: Record<string, unknown> = {}) {
    const res = await api.post<{ data: unknown }>(`/api/v2/admin/jobs/${encodeURIComponent(id)}/${encodeURIComponent(action)}`, body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  // Audit
  async auditLogs(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/v2/admin/audit-logs", { params });
    return envelope(res.data);
  },
  async auditExport(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/v2/admin/audit-logs/export", { params });
    return envelope(res.data);
  },
  async notificationEvents(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/v2/admin/notification-events", { params });
    return envelope(res.data);
  },
  // SEO health (parity)
  async seoHealth() {
    const res = await api.get<{ data: unknown }>("/api/admin/seo/health");
    return envelope(res.data);
  },
  async seoSchema(type: string, id: string) {
    const res = await api.get<{ data: unknown }>(`/api/admin/seo/${encodeURIComponent(type)}/${encodeURIComponent(id)}/schema`);
    return envelope(res.data);
  },
  async seoGet(type: string, id: string) {
    const res = await api.get<{ data: unknown }>(`/api/admin/seo/${encodeURIComponent(type)}/${encodeURIComponent(id)}`);
    return envelope(res.data);
  },
  async seoPut(type: string, id: string, body: Record<string, unknown>) {
    const res = await api.put<{ data: unknown }>(`/api/admin/seo/${encodeURIComponent(type)}/${encodeURIComponent(id)}`, body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async redirectsCreate(body: { source: string; target: string; type?: number }) {
    const res = await api.post<{ data: unknown }>("/api/admin/redirects", body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  // Analytics
  async analyticsFunnel(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/admin/analytics/funnel", { params });
    return envelope(res.data);
  },
  async analyticsEvents(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/admin/analytics/events", { params });
    return envelope(res.data);
  },
  async analyticsExport(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/admin/analytics/export", { params });
    return envelope(res.data);
  },
  // Smart parity
  async smartInventory(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/admin/smart/inventory", { params });
    return envelope(res.data);
  },
  async smartConversion(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/admin/smart/conversion", { params });
    return envelope(res.data);
  },
  async smartSetupGuide() {
    const res = await api.get<{ data: unknown }>("/api/admin/smart/setup-guide");
    return envelope(res.data);
  },
  // Sellers / parity warehousing already in commerce but expose via parity too
  async sellerOrders(params?: Record<string, unknown>) {
    const res = await api.get<{ data: unknown }>("/api/v2/admin/seller-orders", { params });
    return envelope(res.data);
  },
  async erpLoyaltyEvaluate(body: { customer_id: string; order_id: string; order_total_minor: number; currency?: string }) {
    const res = await api.post<{ data: unknown }>("/api/v2/admin/erp/loyalty-evaluate", body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async erpSubscriptionRenew(id: string, body: Record<string, unknown>) {
    const res = await api.post<{ data: unknown }>(`/api/v2/admin/erp/subscriptions/${encodeURIComponent(id)}/renew`, body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
};
