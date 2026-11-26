import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { taxRulesService } from "@/services/tax-rules.service";

describe("tax rules admin contract", () => {
  it("uses the tax-rules routes with idempotent writes and filters", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [{ id: "t1", code: "vn-standard", compound: 0 }] } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "t1" } } });
    apiMock.put.mockResolvedValue({ data: { data: { id: "t1", compound: 1 } } });
    apiMock.delete.mockResolvedValue({ data: { data: null } });

    await expect(taxRulesService.list({ state: "active", country: "VN", tax_class: "standard" })).resolves.toHaveLength(1);
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/tax-rules", {
      params: { state: "active", country: "VN", tax_class: "standard" },
    });

    const payload = {
      code: "vn-standard",
      destination_country: "VN",
      destination_region: null,
      destination_postal_code: null,
      tax_class: "standard",
      rate_basis_points: 1000,
      priority: 1,
      state: "active" as const,
      compound: true,
      effective_at: null,
      expires_at: null,
    };
    await taxRulesService.create(payload);
    expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/tax-rules",
      payload,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    );

    await taxRulesService.update("t1", { state: "disabled" });
    expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/tax-rules/t1",
      { state: "disabled" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    );

    await taxRulesService.remove("t1");
    expect(apiMock.delete).toHaveBeenCalledWith(
      "/api/admin/tax-rules/t1",
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    );
  });
});
