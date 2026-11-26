import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type TaxRuleState = "active" | "disabled";

export interface TaxRule {
  id: string;
  code: string;
  destination_country?: string | null;
  destination_region?: string | null;
  destination_postal_code?: string | null;
  tax_class: string;
  rate_basis_points: number;
  priority: number;
  state: TaxRuleState;
  compound: number;
  effective_at?: string | null;
  expires_at?: string | null;
  source?: string | null;
  evidence?: string | null;
  created_at?: string | null;
  [key: string]: unknown;
}

export interface TaxRuleInput {
  code: string;
  destination_country?: string | null;
  destination_region?: string | null;
  destination_postal_code?: string | null;
  tax_class: string;
  rate_basis_points: number;
  priority: number;
  state: TaxRuleState;
  compound: boolean;
  effective_at?: string | null;
  expires_at?: string | null;
}

const envelope = <T>(value: { data?: T }) => value.data;

export const taxRulesService = {
  async list(params?: Record<string, unknown>) {
    const response = await api.get<{ data?: TaxRule[] }>("/api/admin/tax-rules", { params });
    const rows = envelope(response.data);
    return Array.isArray(rows) ? rows : [];
  },
  async create(body: TaxRuleInput) {
    const response = await api.post<{ data?: TaxRule }>("/api/admin/tax-rules", body, { idempotencyKey: idempotencyKey() });
    return envelope(response.data);
  },
  async update(id: string, body: Partial<TaxRuleInput>) {
    const response = await api.put<{ data?: TaxRule }>(`/api/admin/tax-rules/${encodeURIComponent(id)}`, body, { idempotencyKey: idempotencyKey() });
    return envelope(response.data);
  },
  async remove(id: string) {
    await api.delete(`/api/admin/tax-rules/${encodeURIComponent(id)}`, { idempotencyKey: idempotencyKey() });
  },
};
