import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { pickupPointsService } from "@/services/pickup-points.service";
import { merchantService } from "@/services/merchant.service";

describe("pickup points & handover — contract paths", () => {
  it("lists, creates with idempotency, toggles, and advances handover", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    apiMock.post.mockResolvedValue({ data: { data: { code: "HN-01" } } });
    apiMock.put.mockResolvedValue({ data: { data: { code: "HN-01", state: "disabled" } } });
    await pickupPointsService.list();
    expect(apiMock.get).toHaveBeenCalledWith("/api/pickup-points");
    await pickupPointsService.create({ code: "HN-01", name: "Hoan Kiem" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/pickup-points", { code: "HN-01", name: "Hoan Kiem" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await expect(pickupPointsService.create({ code: " ", name: "x" })).rejects.toThrow(/required/);
    await pickupPointsService.update("HN-01", { state: "disabled" });
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/pickup-points/HN-01", { state: "disabled" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await merchantService.markReady("f1", undefined, "key-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f1/ready", {}, expect.objectContaining({ idempotencyKey: "key-1" }));
    await merchantService.collect("f1", "key-2");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f1/collect", undefined, expect.objectContaining({ idempotencyKey: "key-2" }));
  });
});
