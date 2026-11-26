import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { giftCreditService } from "@/services/gift-credit.service";
import { ordersService } from "@/services/orders.service";

describe("gift cards & store credit — contract paths", () => {
  it("lists, issues with idempotency, voids, and refunds orders to credit", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "g1", code: "GC-1" } } });
    await giftCreditService.list({ customer_id: "7" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/gift-cards", { params: { customer_id: "7" } });
    await giftCreditService.issue({ amount_minor: 50000, currency: "VND" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/gift-cards", { amount_minor: 50000, currency: "VND" }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await expect(giftCreditService.issue({ amount_minor: 0 })).rejects.toThrow(/positive integer/);
    await giftCreditService.void("g1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/gift-cards/g1/void", {}, expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await ordersService.refundToCredit(42, "oos", "key-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/42/refund-to-credit", { reason: "oos" }, expect.objectContaining({ idempotencyKey: "key-1" }));
  });
});
