import api from "./api";

export type SearchType = "catalog" | "orders" | "customers";

export interface AdminSearchResult {
  id: string | number;
  name?: string;
  email_masked?: string;
  number?: string;
  state?: string;
  slug?: string;
}

export interface AdminSearchResponse {
  data: AdminSearchResult[];
  meta: { page: number; per_page: number; total: number; last_page: number };
  type: SearchType;
}

export interface AuditEvent {
  id: string;
  action: string;
  actor: string;
  payload?: Record<string, unknown>;
  store_id: string;
  channel_id: string;
  created_at: string;
}

export const adminConsoleService = {
  async search(q: string, type: SearchType = "catalog", page = 1, perPage = 20) {
    const response = await api.get<{ data: AdminSearchResponse }>("/api/v2/admin/search", { params: { q, type, page, per_page: perPage } });
    return response.data.data;
  },
  async audit(limit = 20, offset = 0) {
    const response = await api.get<{ data: AuditEvent[] }>("/api/v2/admin/audit-logs", { params: { limit, offset } });
    return response.data.data;
  },
};
