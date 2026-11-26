import { beforeEach, describe, expect, it, vi } from "vitest";
import { adminWorkbenchService } from "@/services/admin-workbench.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));

describe("admin workbench API contract", () => {
  beforeEach(() => vi.clearAllMocks());

  it("loads server capabilities and scoped saved views", async () => {
    apiMock.get.mockResolvedValue({ data: { data: { capabilities: ["admin_workbench"], roles: ["owner"], permission_sets: {}, saved_views: [], approval_workflows: [] } } });
    await expect(adminWorkbenchService.get()).resolves.toMatchObject({ capabilities: ["admin_workbench"] });
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/workbench");
  });

  it("uses idempotent real endpoints for writes", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { state: "queued" } } });
    await adminWorkbenchService.bulkOrders({ ids: ["order-1"], to: "cancelled" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/workbench/bulk-orders", { ids: ["order-1"], to: "cancelled" }, expect.objectContaining({ idempotencyKey: expect.stringMatching(/^admin-bulk-/) }));
  });
});
