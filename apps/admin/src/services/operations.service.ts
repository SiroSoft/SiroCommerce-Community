import api from "./api";

export interface OperationRow { id: string; kind: "job" | "payment" | "fulfillment" | "notification" | "return"; label: string; state: string; attempts: number | null; last_error: string | null; order_id: string | null; created_at: string | null; }
export interface OperationsResponse { data: OperationRow[]; meta: { page: number; per_page: number; total: number; last_page: number }; }

export const operationsService = {
  async list(params: { page?: number; per_page?: number; kind?: string; state?: string; q?: string } = {}) {
    const response = await api.get<OperationsResponse>("/api/v2/admin/operations", { params });
    return response.data;
  },
  async retry(id: string, idempotencyKey: string) {
    const response = await api.post<{ data: { id: string; state: string; previous_state: string; idempotency_key: string } }>(`/api/v2/admin/operations/${encodeURIComponent(id)}/retry`, undefined, { idempotencyKey });
    return response.data.data;
  },
  async replayNotification(id: string, idempotencyKey: string) {
    const response = await api.post<{ data: { id: string; state: string; idempotency_key: string } }>(`/api/v2/admin/operations/${encodeURIComponent(id)}/replay-notification`, undefined, { idempotencyKey });
    return response.data.data;
  },
};
