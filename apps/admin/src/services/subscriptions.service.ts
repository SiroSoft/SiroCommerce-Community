import api from "./api";

export interface SubscriptionRow { id: string; plan_id: string; provider: string; currency: string; amount_minor: number; interval: string; interval_count: number; state: string; next_billing_at: string | null; latest_attempt_state: string | null; customer_ref: string; }
export interface SubscriptionList { data: SubscriptionRow[]; meta: { page: number; per_page: number; total: number; last_page: number }; }
export interface SubscriptionSummary { active: number; paused: number; cancelled: number; churn_rate: number | null; active_recurring_revenue: Record<string, number>; renewal_revenue: Record<string, { amount_minor: number; payments: number }>; payment_failure_queue: number; privacy: { pii: boolean }; }
export interface SubscriptionPlanRow { id: string; name: string; amount_minor: number; currency: string; interval: string; interval_count: number; trial_period: string | null; trial_period_count: number; state: string; provider: string | null; created_at?: string | null; updated_at?: string | null; }
export interface SubscriptionPlanInput { name: string; amount_minor: number; currency: string; interval: string; interval_count: number; trial_period?: string | null; trial_period_count?: number; state: string; provider?: string | null; }

export const subscriptionsService = {
  async list(params: { page?: number; per_page?: number; state?: string; q?: string } = {}) { const response = await api.get<SubscriptionList>("/api/v2/admin/subscriptions", { params }); return response.data; },
  async summary(params: { from?: string; to?: string } = {}) { const response = await api.get<{ data: SubscriptionSummary }>("/api/v2/admin/subscriptions/summary", { params }); return response.data.data; },
  async detail(id: string) { const response = await api.get<{ data: SubscriptionRow & { renewals: Array<{ id: string; state: string; currency: string; amount_minor: number; error_code: string | null }> } }>(`/api/v2/admin/subscriptions/${encodeURIComponent(id)}`); return response.data.data; },
  async retry(id: string, idempotencyKey: string) { const response = await api.post<{ data: { attempt_id: string; state: string } }>(`/api/v2/admin/subscriptions/${encodeURIComponent(id)}/retry`, undefined, { idempotencyKey }); return response.data.data; },
  async plans(params: { state?: string } = {}) { const response = await api.get<{ data: SubscriptionPlanRow[] }>("/api/v2/admin/subscription-plans", { params }); return response.data.data; },
  async createPlan(data: SubscriptionPlanInput, idempotencyKey: string) { const response = await api.post<{ data: SubscriptionPlanRow }>("/api/v2/admin/subscription-plans", data, { idempotencyKey }); return response.data.data; },
  async updatePlan(id: string, data: SubscriptionPlanInput, idempotencyKey: string) { const response = await api.put<{ data: SubscriptionPlanRow }>(`/api/v2/admin/subscription-plans/${encodeURIComponent(id)}`, data, { idempotencyKey }); return response.data.data; },
  async deletePlan(id: string, idempotencyKey: string) { await api.delete(`/api/v2/admin/subscription-plans/${encodeURIComponent(id)}`, { idempotencyKey }); },
};
