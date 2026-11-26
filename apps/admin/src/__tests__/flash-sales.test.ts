import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { flashSaleService } from "@/services/flash-sale.service";

describe("flash sales & member prices — contract paths", () => {
  it("calls flash sale and member price endpoints with idempotency keys", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "s1" } } });
    apiMock.put.mockResolvedValue({ data: { data: { id: "s1" } } });
    await flashSaleService.list();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/flash-sales");
    await flashSaleService.create({ name: "Gio vang", starts_at: "2026-01-01T00:00:00Z", ends_at: "2026-01-02T00:00:00Z" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/flash-sales", expect.anything(), expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await flashSaleService.update("s1", { state: "active" });
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/flash-sales/s1", { state: "active" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await flashSaleService.addItem("s1", { variant_id: "v1", sale_price_minor: 1000, cap_qty: 10 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/flash-sales/s1/items", expect.anything(), expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await flashSaleService.saveMemberPrice({ variant_id: "v1", tier: "gold", price_minor: 900 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/member-prices", expect.anything(), expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await flashSaleService.inventorySources("v1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/inventory/v1/sources");
  });
});
