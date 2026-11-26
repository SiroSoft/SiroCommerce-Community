import { describe, expect, it, vi } from "vitest";
import { fe1Service } from "@/services/fe1.service";
import api from "@/services/api";

vi.mock("@/services/api", () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

describe("FE 1 API boundary", () => {
  it("uses only generated option and coupon routes", async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [{ id: 1, name: "Color" }] } } as never);
    await expect(fe1Service.options(12)).resolves.toHaveLength(1);
    expect(api.get).toHaveBeenCalledWith("/api/admin/products/12/options");

    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: "c1" } } } as never);
    await fe1Service.createCoupon({ code: "WELCOME" });
    expect(api.post).toHaveBeenCalledWith("/api/admin/coupons", { code: "WELCOME" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
  });

  it("does not invent a request for unsupported facets or warehouse resources", () => {
    expect(api.get).not.toHaveBeenCalledWith(expect.stringContaining("facets"));
    expect(api.get).not.toHaveBeenCalledWith(expect.stringContaining("warehouses"));
    expect(api.get).not.toHaveBeenCalledWith(expect.stringContaining("reservations"));
  });

  it("uses contract routes for lifecycle, sites, events, and export status", async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } } as never);
    vi.mocked(api.put).mockResolvedValue({ data: { data: { id: "p1" } } } as never);
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: "page-1" } } } as never);

    await fe1Service.updateProductLifecycle("p1", { status: "published", version: 2 });
    await fe1Service.publishPage("page-1", { publish_at: "2030-01-01T00:00:00.000Z" });
    await fe1Service.stores();
    await fe1Service.events({ limit: 10 });
    await fe1Service.exportStatus("job-1");

    expect(api.put).toHaveBeenCalledWith("/api/admin/products/p1", { status: "published", version: 2 }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    expect(api.post).toHaveBeenCalledWith("/api/admin/pages/page-1/publish", { publish_at: "2030-01-01T00:00:00.000Z" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    expect(api.get).toHaveBeenCalledWith("/api/admin/stores");
    expect(api.get).toHaveBeenCalledWith("/api/admin/analytics/events", { params: { limit: 10 } });
    expect(api.get).toHaveBeenCalledWith("/api/v2/admin/exports/job-1");
  });
});
