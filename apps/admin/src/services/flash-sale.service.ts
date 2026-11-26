import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type FlashSale = Record<string, unknown> & { id: string };

const envelope = <T>(v: { data?: T }) => v.data;

export const flashSaleService = {
  async list() {
    const res = await api.get<{ data?: FlashSale[] }>("/api/admin/flash-sales");
    return envelope(res.data) ?? [];
  },
  async create(body: { name: string; starts_at: string; ends_at: string; state?: string; priority?: number }) {
    const res = await api.post<{ data?: FlashSale }>("/api/admin/flash-sales", body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async update(id: string, body: Record<string, unknown>) {
    const res = await api.put<{ data?: FlashSale }>(`/api/admin/flash-sales/${encodeURIComponent(id)}`, body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async addItem(saleId: string, body: { variant_id: string; sale_price_minor: number; cap_qty?: number }) {
    const res = await api.post<{ data?: Record<string, unknown> }>(`/api/admin/flash-sales/${encodeURIComponent(saleId)}/items`, body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async removeItem(saleId: string, itemId: string) {
    await api.delete(`/api/admin/flash-sales/${encodeURIComponent(saleId)}/items/${encodeURIComponent(itemId)}`);
  },
  async memberPrices(variantId?: string) {
    const res = await api.get<{ data?: Record<string, unknown>[] }>("/api/admin/member-prices", variantId ? { params: { variant_id: variantId } } : undefined);
    return envelope(res.data) ?? [];
  },
  async saveMemberPrice(body: { variant_id: string; tier: string; price_minor: number; currency?: string }) {
    const res = await api.post<{ data?: Record<string, unknown> }>("/api/admin/member-prices", body, { idempotencyKey: idempotencyKey() });
    return envelope(res.data);
  },
  async deleteMemberPrice(id: string) {
    await api.delete(`/api/admin/member-prices/${encodeURIComponent(id)}`);
  },
  async inventorySources(variantId: string) {
    const res = await api.get<{ data?: Record<string, unknown>[] }>(`/api/admin/inventory/${encodeURIComponent(variantId)}/sources`);
    return envelope(res.data) ?? [];
  },
};
