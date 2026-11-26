import { beforeEach, describe, expect, it, vi } from "vitest";
import { REPORTING_OPERATIONS, reportingService } from "@/services/reporting.service";
import { financialService } from "@/services/financial.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));

describe("reporting capability and scope parity", () => {
  beforeEach(() => vi.clearAllMocks());
  it("declares one reports capability and the same query scope for every report operation", () => {
    for (const operation of Object.values(REPORTING_OPERATIONS)) {
      expect(operation.capability).toBe("reports");
      expect(operation.scope).toEqual(["store_id", "channel_id"]);
    }
  });

  it("passes bounded report window and scope parameters without inventing route-specific shapes", async () => {
    apiMock.get.mockResolvedValue({ data: { data: {} }, status: 200, headers: new Headers() });
    await reportingService.failures({ from: "2026-01-01T00:00:00Z", to: "2026-01-02T00:00:00Z", store_id: "store", channel_id: "web", page: 2, per_page: 20 });
    expect(apiMock.get).toHaveBeenCalledWith(REPORTING_OPERATIONS.failures.path, { params: { from: "2026-01-01T00:00:00Z", to: "2026-01-02T00:00:00Z", store_id: "store", channel_id: "web", page: 2, per_page: 20 } });
  });

  it("uses the advanced contract for the scoped insights vertical slice", async () => {
    apiMock.get.mockResolvedValue({ data: { data: { data: {} } }, status: 200, headers: new Headers() });
    await reportingService.insights({ from: "2026-09-01", to: "2026-09-02", store_id: "store", channel_id: "web" });
    expect(apiMock.get).toHaveBeenCalledWith(REPORTING_OPERATIONS.advanced.path, { params: { from: "2026-09-01", to: "2026-09-02", store_id: "store", channel_id: "web", dimension: "insights" } });
  });

  it("reads finance reconciliation through the scoped, paginated contract", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [], meta: { page: 2, per_page: 25, total: 0, last_page: 1 } }, status: 200, headers: new Headers() });
    await financialService.reconciliation(2);
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/finance/reconciliation", { params: { page: 2, per_page: 25 } });
  });
});
