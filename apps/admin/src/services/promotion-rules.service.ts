import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type PromotionRuleKind = "cart_percent" | "cart_fixed" | "catalog_percent";

export interface PromotionRule {
  id: string;
  name: string;
  kind: PromotionRuleKind;
  value: number;
  min_subtotal_minor?: number | null;
  category_slugs?: string[];
  starts_at?: string | null;
  ends_at?: string | null;
  max_uses?: number | null;
  uses?: number;
  state: "active" | "disabled";
  [key: string]: unknown;
}

export interface PromotionRuleInput {
  name: string;
  kind: PromotionRuleKind;
  value: number;
  min_subtotal_minor?: number | null;
  category_slugs?: string[];
  starts_at?: string | null;
  ends_at?: string | null;
  max_uses?: number | null;
  state?: "active" | "disabled";
}

const envelope = <T>(value: { data?: T }) => value.data;

export const promotionRulesService = {
  async list() {
    const response = await api.get<{ data?: PromotionRule[] }>("/api/admin/promotion-rules");
    return envelope(response.data) ?? [];
  },
  async create(body: PromotionRuleInput) {
    const response = await api.post<{ data?: PromotionRule }>("/api/admin/promotion-rules", body, { idempotencyKey: idempotencyKey() });
    return envelope(response.data);
  },
  async update(id: string, body: Partial<PromotionRuleInput>) {
    const response = await api.put<{ data?: PromotionRule }>(`/api/admin/promotion-rules/${encodeURIComponent(id)}`, body, { idempotencyKey: idempotencyKey() });
    return envelope(response.data);
  },
  async remove(id: string) {
    await api.delete(`/api/admin/promotion-rules/${encodeURIComponent(id)}`, { idempotencyKey: idempotencyKey() });
  },
};
