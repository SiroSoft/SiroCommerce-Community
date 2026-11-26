import api, { fetchWithAuth } from "./api";
import { AdminApiError } from "./api-errors";
import type { components } from "@/types/api";

type PaginationMeta = components["schemas"]["PaginationMeta"];

export interface Vendor {
  id: string;
  name: string;
  slug: string;
  status: "active" | "suspended";
  commission_bps: number;
  contact_email?: string | null;
  description?: string | null;
  products_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface VendorInput {
  name: string;
  slug?: string | null;
  status?: "active" | "suspended";
  commission_bps?: number;
  contact_email?: string | null;
  description?: string | null;
}

export interface VendorsResponse {
  data: Vendor[];
  meta: PaginationMeta;
}

export interface VendorLedgerEntry {
  id: string;
  vendor_id: string;
  order_id?: string | null;
  order_line_id?: string | null;
  type: "sale" | "refund" | "adjustment";
  gross_minor: number;
  commission_minor: number;
  net_minor: number;
  currency: string;
  state: "pending" | "available" | "paid" | "void";
  payout_id?: string | null;
  created_at?: string;
}

export interface VendorLedgerTotals {
  gross: number;
  commission: number;
  net: number;
  pending: number;
  available: number;
  paid: number;
}

export interface VendorLedger {
  vendor_id?: string;
  entries: VendorLedgerEntry[];
  totals: VendorLedgerTotals;
}

export interface VendorPayout {
  id: string;
  vendor_id: string;
  amount_minor: number;
  currency: string;
  state: "pending" | "paid" | "failed" | "returned" | "cancelled";
  period_start: string;
  period_end: string;
  entries_count?: number;
  created_at?: string;
  paid_at?: string | null;
  failed_at?: string | null;
  returned_at?: string | null;
  cancelled_at?: string | null;
  provider_reference?: string | null;
  failure_reason?: string | null;
}

export interface VendorPayoutReconciliation {
  ok: boolean;
  processed: number;
  paid: number;
  failed: number;
  returned: number;
  skipped: number;
  errors: number;
  results: Array<{ row: number; payout_id?: string; status?: string; ok: boolean; action?: string; error?: string }>;
}

export interface VendorPayoutInput {
  period_start: string;
  period_end: string;
}

export interface VendorPayoutCsvExport {
  blob: Blob;
  filename: string;
  rows: number;
}

/** Marketplace-lite vendors. Product ownership is assigned from the product form. */
export const vendorsService = {
  async list(params?: Record<string, unknown>) {
    const res = await api.get<VendorsResponse>("/api/admin/vendors", { params });
    return {
      ...res.data,
      data: Array.isArray(res.data?.data) ? res.data.data.filter((vendor) => vendor && typeof vendor.name === "string" && typeof vendor.slug === "string") : [],
    };
  },

  async create(data: VendorInput, idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessEnvelope"] & { data: Vendor }>("/api/admin/vendors", data, { idempotencyKey });
    return res.data.data;
  },

  async update(id: string, data: Partial<VendorInput>, idempotencyKey: string) {
    const res = await api.put<components["schemas"]["SuccessEnvelope"] & { data: Vendor }>(`/api/admin/vendors/${encodeURIComponent(id)}`, data, { idempotencyKey });
    return res.data.data;
  },

  async delete(id: string, idempotencyKey: string) {
    await api.delete(`/api/admin/vendors/${encodeURIComponent(id)}`, { idempotencyKey });
  },

  async ledger(id: string, params?: Record<string, unknown>) {
    const res = await api.get<components["schemas"]["SuccessEnvelope"] & { data: VendorLedger }>(`/api/admin/vendors/${encodeURIComponent(id)}/ledger`, { params });
    return res.data.data;
  },

  async payouts(id: string) {
    const res = await api.get<components["schemas"]["SuccessEnvelope"] & { data: VendorPayout[] }>(`/api/admin/vendors/${encodeURIComponent(id)}/payouts`);
    return Array.isArray(res.data.data) ? res.data.data : [];
  },

  async createPayout(id: string, data: VendorPayoutInput, idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessEnvelope"] & { data: VendorPayout }>(`/api/admin/vendors/${encodeURIComponent(id)}/payouts`, data, { idempotencyKey });
    return res.data.data;
  },

  async markPayoutPaid(payoutId: string) {
    const res = await api.post<components["schemas"]["SuccessEnvelope"] & { data: VendorPayout }>(`/api/admin/vendor-payouts/${encodeURIComponent(payoutId)}/mark-paid`, {});
    return res.data.data;
  },

  async cancelPayout(payoutId: string) {
    const res = await api.post<components["schemas"]["SuccessEnvelope"] & { data: VendorPayout }>(`/api/admin/vendor-payouts/${encodeURIComponent(payoutId)}/cancel`, {});
    return res.data.data;
  },

  async reconcilePayouts(csv: string, idempotencyKey: string, dryRun = false) {
    const res = await api.post<{ data: VendorPayoutReconciliation }>("/api/admin/vendor-payouts/reconcile", { csv, dry_run: dryRun }, { idempotencyKey });
    return res.data.data;
  },

  async exportPayoutCsv(payoutId: string): Promise<VendorPayoutCsvExport> {
    const response = await fetchWithAuth(`/api/admin/vendor-payouts/${encodeURIComponent(payoutId)}/export.csv`, {
      method: "GET",
      headers: { Accept: "text/csv, application/json; version=v1" },
    });
    if (!response.ok) {
      const body = await response.json().catch(() => null) as { message?: string; meta?: { error_code?: string; errors?: Record<string, string[]> } } | null;
      throw new AdminApiError({
        message: body?.message ?? `Payout export failed (${response.status})`,
        status: response.status,
        errorCode: body?.meta?.error_code,
        errors: body?.meta?.errors,
        correlationId: response.headers.get("X-Correlation-Id") ?? undefined,
      });
    }
    const disposition = response.headers.get("Content-Disposition") ?? "";
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    const blob = await response.blob();
    return {
      blob,
      filename: match?.[1] ?? "vendor-payout.csv",
      rows: Number(response.headers.get("X-Export-Rows") ?? 0),
    };
  },
};
