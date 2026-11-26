import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { promotionRulesService } from "@/services/promotion-rules.service";

describe("price rules admin contract", () => {
  it("uses the promotion-rules routes with idempotent writes", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [{ id: "r1", name: "Ten off" }] } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "r1" } } });
    apiMock.put.mockResolvedValue({ data: { data: { id: "r1", state: "disabled" } } });
    apiMock.delete.mockResolvedValue({ data: { data: null } });

    await expect(promotionRulesService.list()).resolves.toHaveLength(1);
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/promotion-rules");

    await promotionRulesService.create({ name: "Ten off", kind: "cart_percent", value: 10, category_slugs: ["skincare"] });
    expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/promotion-rules",
      { name: "Ten off", kind: "cart_percent", value: 10, category_slugs: ["skincare"] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    );

    await promotionRulesService.update("r1", { state: "disabled" });
    expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/promotion-rules/r1",
      { state: "disabled" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    );

    await promotionRulesService.remove("r1");
    expect(apiMock.delete).toHaveBeenCalledWith(
      "/api/admin/promotion-rules/r1",
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    );
  });
});
