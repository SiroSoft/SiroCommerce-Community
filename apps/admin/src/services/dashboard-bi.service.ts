import api from "./api";
import type { DashboardBi, DashboardBiRange, DashboardBiSalesBlock } from "@/types/dashboard";

export const DASHBOARD_BI_RANGES: ReadonlyArray<{ value: DashboardBiRange; label: string }> = [
  { value: "7d", label: "Last 7 days" },
  { value: "30d", label: "Last 30 days" },
  { value: "90d", label: "Last 90 days" },
];

/** Sales are a single block for one currency and a list for multi-currency stores. */
export function normalizeSalesBlocks(sales: DashboardBi["sales"] | undefined): DashboardBiSalesBlock[] {
  if (!sales) return [];
  return Array.isArray(sales) ? sales : [sales];
}

export const dashboardBiService = {
  async getBi(range: DashboardBiRange): Promise<DashboardBi> {
    const response = await api.get<{ success: boolean; data: DashboardBi }>("/api/v2/admin/dashboard/bi", { params: { range } });
    return response.data.data;
  },
};
