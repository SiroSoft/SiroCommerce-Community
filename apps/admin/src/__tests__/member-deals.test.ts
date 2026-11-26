import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { memberDealsService } from "@/services/member-deals.service";
import { flashSaleService } from "@/services/flash-sale.service";

describe("member deals & tier gating — contract paths", () => {
  it("creates/updates deals with idempotency and sets flash min_tier", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "d1" } } });
    apiMock.put.mockResolvedValue({ data: { data: { id: "d1", state: "disabled" } } });
    await memberDealsService.list();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/member-deals");
    await memberDealsService.create({ name: "Gold week", kind: "tier_window", min_tier: "gold", percent_off: 10 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/member-deals", { name: "Gold week", kind: "tier_window", min_tier: "gold", percent_off: 10 }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await expect(memberDealsService.create({ name: "Bad", percent_off: 91 })).rejects.toThrow(/1\.\.90/);
    await memberDealsService.update("d1", { state: "disabled" });
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/member-deals/d1", { state: "disabled" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await flashSaleService.update("s1", { min_tier: "gold" });
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/flash-sales/s1", { min_tier: "gold" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
  });
});
