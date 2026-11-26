import api from "./api";

export type ReportData = Record<string, unknown>;
export interface ReportingQuery extends Record<string, unknown> { from?: string; to?: string; store_id?: string; channel_id?: string; page?: number; per_page?: number; }
export const REPORTING_OPERATIONS = {
  overview: { path: "/api/v2/admin/reports/overview", capability: "reports", scope: ["store_id", "channel_id"] },
  failures: { path: "/api/v2/admin/reports/failures", capability: "reports", scope: ["store_id", "channel_id"] },
  advanced: { path: "/api/v2/admin/reports/advanced", capability: "reports", scope: ["store_id", "channel_id"] },
} as const;

export const reportingService = {
  async overview(params?: ReportingQuery) {
    const response = await api.get<{ data: ReportData }>(REPORTING_OPERATIONS.overview.path, { params });
    return response.data.data;
  },
  async failures(params?: ReportingQuery) {
    const response = await api.get<{ data: ReportData }>(REPORTING_OPERATIONS.failures.path, { params });
    return response.data.data;
  },
  async advanced(params?: ReportingQuery) {
    const response = await api.get<{ data: ReportData }>(REPORTING_OPERATIONS.advanced.path, { params });
    return response.data.data;
  },
  async insights(params?: ReportingQuery) {
    const response = await api.get<{ data: ReportData }>(REPORTING_OPERATIONS.advanced.path, { params: { ...params, dimension: "insights" } });
    return response.data.data;
  },
};
