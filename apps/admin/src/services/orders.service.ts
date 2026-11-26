import api from "./api";
import type { components } from "@/types/api";
import type { OrderTransitionRequest } from "@sirocommerce/api-client/generated";

export interface OrderCancelRequest { reason?: string; }
export interface OrderRefundRequest { amount_minor: number; reason?: string; }
export interface BulkOrderFailure { id: string; message: string; }
export interface BulkOrderResult { succeeded: unknown[]; failed: BulkOrderFailure[]; }

type Order = components["schemas"]["Order"];
type PaginationMeta = components["schemas"]["PaginationMeta"];

export interface OrdersResponse {
  data: Order[];
  meta: PaginationMeta;
}

export const ordersService = {
  async list(params?: Record<string, unknown>) {
    const res = await api.get<OrdersResponse>("/api/orders", { params });
    return res.data;
  },

  async get(id: number) {
    const res = await api.get<components["schemas"]["SuccessResponse_Order"]>(`/api/orders/${id}`);
    return res.data.data;
  },

  async create(data: components["schemas"]["CreateOrderRequest"]) {
    const res = await api.post<components["schemas"]["SuccessResponse_Order"]>("/api/orders", data);
    return res.data.data;
  },

  async update(id: number, data: components["schemas"]["UpdateOrderRequest"]) {
    const res = await api.put<components["schemas"]["SuccessResponse_Order"]>(`/api/orders/${id}`, data);
    return res.data.data;
  },

  async delete(id: number) {
    await api.delete(`/api/orders/${id}`);
  },

  async bulkTransition(input: { ids: string[]; to: string; reason?: string }, idempotencyKey: string) {
    const res = await api.post<{ data: BulkOrderResult }>("/api/v2/admin/workbench/bulk-orders", input, { idempotencyKey });
    return res.data.data;
  },

  async transition(id: number, data: OrderTransitionRequest, idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessResponse_Order"]>(`/api/admin/orders/${id}/transition`, data, { idempotencyKey });
    return res.data.data;
  },

  async cancel(id: number, data: OrderCancelRequest = {}, idempotencyKey: string) {
    const res = await api.post<{ data: { id?: string; state?: string; status?: string; message?: string } }>(`/api/admin/orders/${id}/cancel`, data, { idempotencyKey });
    return res.data.data;
  },

  async refund(id: number, data: OrderRefundRequest, idempotencyKey: string) {
    if (!Number.isInteger(data.amount_minor) || data.amount_minor <= 0) throw new Error("Refund amount must be a positive integer in minor units.");
    const res = await api.post<{ data: { id?: string; state?: string; status?: string; message?: string } }>(`/api/admin/orders/${id}/refund`, data, { idempotencyKey });
    return res.data.data;
  },

  async refundRemaining(id: number, reason: string | undefined, idempotencyKey: string) {
    const res = await api.post<{ data: { id?: string; state?: string; status?: string; message?: string } }>(`/api/admin/orders/${id}/refund-remaining`, reason ? { reason } : {}, { idempotencyKey });
    return res.data.data;
  },

  async refundToCredit(id: number, reason: string | undefined, idempotencyKey: string) {
    const res = await api.post<{ data: { order?: { id?: string; state?: string }; credit?: { id?: string; code?: string; balance_minor?: number } } }>(`/api/admin/orders/${id}/refund-to-credit`, reason ? { reason } : {}, { idempotencyKey });
    return res.data.data;
  },

  async reconcilePayments(apply = false, idempotencyKey: string) {
    const res = await api.post<{ data: { inspected: number; resolved: number; unknown: number } }>('/api/admin/payments/reconcile', { apply }, { idempotencyKey });
    return res.data.data;
  },
};
