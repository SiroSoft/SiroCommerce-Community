import { beforeEach, describe, expect, it, vi } from "vitest";
import { AdminApiError, toAdminApiError } from "@/services/api-errors";
import { merchantService } from "@/services/merchant.service";
import { ordersService } from "@/services/orders.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
beforeEach(() => vi.clearAllMocks());

describe("latest generated admin contract regressions", () => {
  it("sends the generated inventory, cancel, refund, and fulfillment shapes", async () => {
    apiMock.put.mockResolvedValueOnce(ok({ variant_id: "v-1", source_id: "main", on_hand: 12, reserved: 2, available: 10 }));
    apiMock.post.mockResolvedValue(ok({ state: "accepted" }));
    await merchantService.adjustInventory("v-1", { on_hand: 12, source_id: "main" }, "inv-1");
    await merchantService.transferInventory("v-1", { from_source_id: "main", to_source_id: "overflow", qty: 2, reason: "cycle count" }, "transfer-1");
    await ordersService.cancel(42, { reason: "customer request" }, "cancel-1");
    await ordersService.refund(42, { amount_minor: 1250, reason: "partial refund" }, "refund-1");
    await merchantService.cancelFulfillment("ful-1", "fulfillment-cancel-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/inventory/v-1", { on_hand: 12, source_id: "main" }, { idempotencyKey: "inv-1" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/inventory/v-1/transfer", { from_source_id: "main", to_source_id: "overflow", qty: 2, reason: "cycle count" }, { idempotencyKey: "transfer-1" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/42/cancel", { reason: "customer request" }, { idempotencyKey: "cancel-1" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/42/refund", { amount_minor: 1250, reason: "partial refund" }, { idempotencyKey: "refund-1" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/ful-1/cancel", undefined, { idempotencyKey: "fulfillment-cancel-1" });
  });

  it("rejects invalid minor-unit refunds before transport", async () => {
    await expect(ordersService.refund(42, { amount_minor: 0 }, "refund-invalid")).rejects.toThrow(/positive integer/);
    expect(apiMock.post).not.toHaveBeenCalledWith("/api/admin/orders/42/refund", expect.anything(), expect.anything());
  });

  it("classifies every contract error status without collapsing permission or conflict states", () => {
    const cases: Array<[number, string]> = [[401, "auth"], [403, "permission"], [404, "unknown_outcome"], [409, "conflict"], [422, "validation"], [429, "rate_limited"], [500, "server"]];
    for (const [status, kind] of cases) expect(toAdminApiError({ status })).toMatchObject({ status, kind });
    expect(new AdminApiError({ message: "missing", status: 404 }).status).toBe(404);
  });
});
