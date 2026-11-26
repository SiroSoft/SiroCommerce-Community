import api from "./api";

export type SavedView = { id: string; name: string; resource: string; filters: Record<string, unknown>; columns: string[] };
export type ApprovalWorkflow = { id: string; name: string; resource: string; action: string; steps: string[] };
export type Workbench = { capabilities: string[]; roles: string[]; permission_sets: Record<string, string[]>; saved_views: SavedView[]; approval_workflows: ApprovalWorkflow[] };

const data = <T>(response: { data: { data: T } }) => response.data.data;

export const adminWorkbenchService = {
  async get() { return data(await api.get<{ data: Workbench }>("/api/v2/admin/workbench")); },
  async saveView(input: { name: string; resource: string; filters: Record<string, unknown>; columns?: string[] }, idempotencyKey: string) { return data(await api.post<{ data: SavedView }>("/api/v2/admin/workbench/views", input, { idempotencyKey })); },
  async deleteView(id: string, idempotencyKey: string) { await api.delete(`/api/v2/admin/workbench/views/${encodeURIComponent(id)}`, { idempotencyKey }); },
  async savePermissions(role: string, permissions: string[], idempotencyKey: string) { return data(await api.put<{ data: Record<string, string[]> }>(`/api/v2/admin/workbench/permissions/${encodeURIComponent(role)}`, { permissions }, { idempotencyKey })); },
  async createWorkflow(input: { name: string; resource: string; action: string; steps: string[] }, idempotencyKey: string) { return data(await api.post<{ data: ApprovalWorkflow }>("/api/v2/admin/workbench/workflows", input, { idempotencyKey })); },
  async requestApproval(input: { workflow_id: string; resource_id: string; payload?: Record<string, unknown> }, idempotencyKey: string) { return data(await api.post("/api/v2/admin/workbench/approvals", input, { idempotencyKey })); },
  async decideApproval(id: string, decision: "approve" | "reject", idempotencyKey: string) { return data(await api.post(`/api/v2/admin/workbench/approvals/${encodeURIComponent(id)}/decision`, { decision }, { idempotencyKey })); },
  async bulkOrders(input: { ids: string[]; to: string; reason?: string }) { return data(await api.post("/api/v2/admin/workbench/bulk-orders", input, { idempotencyKey: `admin-bulk-${crypto.randomUUID()}` })); },
  async createExport(input: { dataset: string; format: "csv" | "jsonl"; from?: string; to?: string }) { return data(await api.post("/api/v2/admin/exports", input, { idempotencyKey: `admin-export-${crypto.randomUUID()}` })); },
};
