import api from "./api";

export type ReconciliationRow = { invoice_id: string; invoice_number: string; order_id: string; state: string; currency: string; minor_unit: number; amount_minor: number; credited_minor: number; reconciliation: "balanced" | "open" | "over_credited"; evidence_available: boolean };
export type ReconciliationResponse = { data: ReconciliationRow[]; meta: { page: number; per_page: number; total: number; last_page: number } };
export const financialService = { async reconciliation(page = 1) { const response = await api.get<ReconciliationResponse>("/api/v2/admin/finance/reconciliation", { params: { page, per_page: 25 } }); return response.data; } };
