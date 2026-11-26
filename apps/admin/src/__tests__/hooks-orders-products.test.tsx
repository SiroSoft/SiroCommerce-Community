import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const ordersServiceMock = vi.hoisted(() => ({
  list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), transition: vi.fn(), cancel: vi.fn(),
  refund: vi.fn(), refundRemaining: vi.fn(), refundToCredit: vi.fn(), bulkTransition: vi.fn(), delete: vi.fn(),
}));
const productsServiceMock = vi.hoisted(() => ({
  list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn(),
  importCsv: vi.fn(), transition: vi.fn(),
}));
const merchantServiceMock = vi.hoisted(() => ({
  actionCenter: vi.fn(), inventory: vi.fn(), inventorySources: vi.fn(), track: vi.fn(),
  allocate: vi.fn(), ship: vi.fn(), deliver: vi.fn(), cancelFulfillment: vi.fn(),
  adjustInventory: vi.fn(), transferInventory: vi.fn(),
}));
const idempotencyKeyMock = vi.hoisted(() => vi.fn(() => "idem-key"));
vi.mock("@/services/orders.service", () => ({ ordersService: ordersServiceMock }));
vi.mock("@/services/products.service", () => ({ productsService: productsServiceMock }));
vi.mock("@/services/merchant.service", () => ({ merchantService: merchantServiceMock, idempotencyKey: idempotencyKeyMock }));

import {
  useBulkOrderTransition, useCancelOrder, useCreateOrder, useDeleteOrder, useOrder, useOrderTransition,
  useOrders, useRefundOrder, useRefundRemaining, useRefundToCredit, useUpdateOrder,
} from "@/hooks/use-orders";
import {
  useCreateProduct, useDeleteProduct, useImportProducts, useProduct, useProducts,
  useTransitionProduct, useUpdateProduct,
} from "@/hooks/use-products";
import {
  useActionCenter, useAllocateFulfillment, useCancelFulfillment, useFulfillmentAction,
  useFulfillmentTrack, useInventory, useInventoryMutation, useInventorySources,
} from "@/hooks/use-merchant";

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return { client, wrapper };
}

function makeOrder(id: number) {
  return { id, status: "pending" };
}

beforeEach(() => {
  vi.clearAllMocks();
  idempotencyKeyMock.mockReturnValue("idem-key");
});

describe("order hooks", () => {
  it("dedupes orders and only loads a detail for a positive id", async () => {
    ordersServiceMock.list.mockResolvedValueOnce({
      data: [makeOrder(1), makeOrder(1), makeOrder(2)], meta: { total: 2 },
    });
    const { wrapper } = setup();
    const list = renderHook(() => useOrders({ status: "pending" }), { wrapper });
    await waitFor(() => expect(list.result.current.orders).toHaveLength(2));
    expect(list.result.current.orders.map((order) => order.id)).toEqual([1, 2]);
    expect(ordersServiceMock.list).toHaveBeenCalledWith({ status: "pending" });

    const disabled = renderHook(() => useOrder(0), { wrapper });
    await waitFor(() => expect(disabled.result.current.isLoading).toBe(false));
    expect(ordersServiceMock.get).not.toHaveBeenCalled();

    ordersServiceMock.get.mockResolvedValueOnce(makeOrder(5));
    const detail = renderHook(() => useOrder(5), { wrapper });
    await waitFor(() => expect(detail.result.current.data).toEqual(makeOrder(5)));

    ordersServiceMock.list.mockResolvedValueOnce({ data: null });
    const empty = renderHook(() => useOrders(), { wrapper });
    await waitFor(() => expect(empty.result.current.isLoading).toBe(false));
    expect(empty.result.current.orders).toEqual([]);
  });

  it("creates, updates and deletes orders and invalidates the list", async () => {
    ordersServiceMock.create.mockResolvedValue(makeOrder(1));
    ordersServiceMock.update.mockResolvedValue(makeOrder(1));
    ordersServiceMock.delete.mockResolvedValue(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const create = renderHook(() => useCreateOrder(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ items: [] } as never); });
    expect(ordersServiceMock.create).toHaveBeenCalledWith({ items: [] });

    const update = renderHook(() => useUpdateOrder(1), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ status: "paid" } as never); });
    expect(ordersServiceMock.update).toHaveBeenCalledWith(1, { status: "paid" });

    const remove = renderHook(() => useDeleteOrder(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync(1); });
    expect(ordersServiceMock.delete).toHaveBeenCalledWith(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["orders"] });
  });

  it("transitions an order and refetches the scoped detail", async () => {
    ordersServiceMock.transition.mockResolvedValue(makeOrder(1));
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const refetch = vi.spyOn(client, "refetchQueries");
    const transition = renderHook(() => useOrderTransition(1), { wrapper });
    expect(idempotencyKeyMock).toBeDefined();
    await act(async () => { await transition.result.current.mutateAsync("processing"); });
    expect(ordersServiceMock.transition).toHaveBeenCalledWith(1, { to: "processing" }, "idem-key");
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["orders"] });
    expect(refetch).toHaveBeenCalledWith({ queryKey: expect.arrayContaining(["orders", 1]) });

    await act(async () => {
      await expect(transition.result.current.mutateAsync(undefined as never)).rejects.toThrow("A target status is required");
    });
  });

  it("cancels refunds and refunds remaining balance with optional reasons", async () => {
    ordersServiceMock.cancel.mockResolvedValue(undefined);
    ordersServiceMock.refund.mockResolvedValue(undefined);
    ordersServiceMock.refundRemaining.mockResolvedValue(undefined);
    ordersServiceMock.refundToCredit.mockResolvedValue(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const cancel = renderHook(() => useCancelOrder(1), { wrapper });
    await act(async () => { await cancel.result.current.mutateAsync("customer request"); });
    expect(ordersServiceMock.cancel).toHaveBeenCalledWith(1, { reason: "customer request" }, "idem-key");
    await act(async () => { await cancel.result.current.mutateAsync(undefined); });
    expect(ordersServiceMock.cancel).toHaveBeenCalledWith(1, {}, "idem-key");

    const refund = renderHook(() => useRefundOrder(1), { wrapper });
    await act(async () => { await refund.result.current.mutateAsync({ amount_minor: 500, reason: "damaged" }); });
    expect(ordersServiceMock.refund).toHaveBeenCalledWith(1, { amount_minor: 500, reason: "damaged" }, "idem-key");

    const remaining = renderHook(() => useRefundRemaining(1), { wrapper });
    await act(async () => { await remaining.result.current.mutateAsync("late"); });
    expect(ordersServiceMock.refundRemaining).toHaveBeenCalledWith(1, "late", "idem-key");

    const credit = renderHook(() => useRefundToCredit(1), { wrapper });
    await act(async () => { await credit.result.current.mutateAsync("gift"); });
    expect(ordersServiceMock.refundToCredit).toHaveBeenCalledWith(1, "gift", "idem-key");
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["gift-credits"] });
  });

  it("validates bulk transitions and reports partial failures", async () => {
    const { wrapper } = setup();
    const bulk = renderHook(() => useBulkOrderTransition(), { wrapper });
    await act(async () => {
      await expect(bulk.result.current.mutateAsync({ ids: [], to: "paid" })).rejects.toThrow("Select at least one order");
    });

    ordersServiceMock.bulkTransition.mockResolvedValueOnce({ succeeded: [], failed: [{ id: "1", message: "bad" }] });
    await act(async () => {
      await expect(bulk.result.current.mutateAsync({ ids: [1, 1], to: "paid", useBulkEndpoint: true }))
        .rejects.toMatchObject({ partialFailure: false });
    });
    expect(ordersServiceMock.bulkTransition).toHaveBeenCalledWith({ ids: ["1"], to: "paid" }, "idem-key");

    ordersServiceMock.transition.mockResolvedValueOnce(makeOrder(1));
    const perOrder = await bulk.result.current.mutateAsync({ ids: [1], to: "processing" });
    expect(perOrder.failed).toEqual([]);

    ordersServiceMock.transition.mockRejectedValueOnce(Object.assign(new Error("nope"), { status: 409 }));
    await act(async () => {
      await expect(bulk.result.current.mutateAsync({ ids: [1, 2], to: "processing" }))
        .rejects.toMatchObject({ partialFailure: true });
    });
  });
});

describe("product hooks", () => {
  it("lists products and guards detail loads", async () => {
    productsServiceMock.list.mockResolvedValueOnce({ data: [{ id: 1, name: "One" }], meta: { total: 1 } });
    const { wrapper } = setup();
    const list = renderHook(() => useProducts(), { wrapper });
    await waitFor(() => expect(list.result.current.products).toEqual([{ id: 1, name: "One" }]));
    const disabled = renderHook(() => useProduct(0), { wrapper });
    await waitFor(() => expect(disabled.result.current.isLoading).toBe(false));
    expect(productsServiceMock.get).not.toHaveBeenCalled();

    productsServiceMock.get.mockResolvedValueOnce({ id: 2, name: "Two" });
    const detail = renderHook(() => useProduct(2), { wrapper });
    await waitFor(() => expect(detail.result.current.data).toEqual({ id: 2, name: "Two" }));

    productsServiceMock.list.mockResolvedValueOnce({ data: null });
    const empty = renderHook(() => useProducts(), { wrapper: setup().wrapper });
    await waitFor(() => expect(empty.result.current.isLoading).toBe(false));
    expect(empty.result.current.products).toEqual([]);
  });

  it("creates, updates, deletes and transitions products", async () => {
    productsServiceMock.create.mockResolvedValue({ id: 1 });
    productsServiceMock.update.mockResolvedValue({ id: 1 });
    productsServiceMock.delete.mockResolvedValue(undefined);
    productsServiceMock.transition.mockResolvedValue({ id: 1, status: "archived" });
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const create = renderHook(() => useCreateProduct(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ name: "One" } as never); });
    expect(productsServiceMock.create).toHaveBeenCalledWith({ name: "One" }, "idem-key");

    const update = renderHook(() => useUpdateProduct(1), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ name: "Two" } as never); });
    expect(productsServiceMock.update).toHaveBeenCalledWith(1, { name: "Two" }, "idem-key");

    const remove = renderHook(() => useDeleteProduct(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync(1); });
    expect(productsServiceMock.delete).toHaveBeenCalledWith(1, "idem-key");

    const transition = renderHook(() => useTransitionProduct(), { wrapper });
    await act(async () => { await transition.result.current.mutateAsync({ id: 1, state: "archived", version: 2 }); });
    expect(productsServiceMock.transition).toHaveBeenCalledWith(1, "archived", 2, "idem-key");
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["products"] });
  });

  it("imports products and only invalidates after a real import", async () => {
    productsServiceMock.importCsv
      .mockResolvedValueOnce({ created: 1, updated: 0, skipped: 0, errors: [], dry_run: true, rows_seen: 1 })
      .mockResolvedValueOnce({ created: 1, updated: 0, skipped: 0, errors: [], dry_run: false, rows_seen: 1 });
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const importProducts = renderHook(() => useImportProducts(), { wrapper });

    await act(async () => { await importProducts.result.current.mutateAsync({ csv: "sku,name", dryRun: true }); });
    expect(productsServiceMock.importCsv).toHaveBeenCalledWith({ csv: "sku,name", dryRun: true }, "idem-key");
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ["products"] });

    await act(async () => { await importProducts.result.current.mutateAsync({ csv: "sku,name" }); });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["products"] });
  });
});

describe("merchant hooks", () => {
  it("loads action center, inventory and fulfillment tracking with guards", async () => {
    merchantServiceMock.actionCenter.mockResolvedValueOnce({ tasks: [] });
    merchantServiceMock.inventory.mockResolvedValueOnce({ on_hand: 5 });
    merchantServiceMock.inventorySources.mockResolvedValueOnce([{ id: "s1" }]);
    merchantServiceMock.track.mockResolvedValueOnce({ state: "in_transit" });
    const { wrapper } = setup();

    const actionCenter = renderHook(() => useActionCenter(), { wrapper });
    await waitFor(() => expect(actionCenter.result.current.data).toEqual({ tasks: [] }));

    const blank = renderHook(() => useInventory("   "), { wrapper });
    await waitFor(() => expect(blank.result.current.isLoading).toBe(false));
    expect(merchantServiceMock.inventory).not.toHaveBeenCalled();

    const inventory = renderHook(() => useInventory("SKU-1"), { wrapper });
    await waitFor(() => expect(inventory.result.current.data).toEqual({ on_hand: 5 }));
    const sources = renderHook(() => useInventorySources("SKU-1"), { wrapper });
    await waitFor(() => expect(sources.result.current.data).toEqual([{ id: "s1" }]));
    const track = renderHook(() => useFulfillmentTrack("f-1"), { wrapper });
    await waitFor(() => expect(track.result.current.data).toEqual({ state: "in_transit" }));
  });

  it("invalidates merchant caches for fulfillment and inventory mutations", async () => {
    merchantServiceMock.allocate.mockResolvedValue({});
    merchantServiceMock.ship.mockResolvedValue({});
    merchantServiceMock.deliver.mockResolvedValue({});
    merchantServiceMock.cancelFulfillment.mockResolvedValue({});
    merchantServiceMock.adjustInventory.mockResolvedValue({});
    merchantServiceMock.transferInventory.mockResolvedValue({});
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const allocate = renderHook(() => useAllocateFulfillment(), { wrapper });
    await act(async () => { await allocate.result.current.mutateAsync({ order_id: 1 } as never); });
    expect(merchantServiceMock.allocate).toHaveBeenCalledWith({ order_id: 1 }, "idem-key");

    const ship = renderHook(() => useFulfillmentAction("ship"), { wrapper });
    await act(async () => { await ship.result.current.mutateAsync({ id: "f-1" }); });
    expect(merchantServiceMock.ship).toHaveBeenCalledWith("f-1", undefined, "idem-key");

    const deliver = renderHook(() => useFulfillmentAction("deliver"), { wrapper });
    await act(async () => { await deliver.result.current.mutateAsync({ id: "f-2" }); });
    expect(merchantServiceMock.deliver).toHaveBeenCalledWith("f-2", "idem-key");

    const cancel = renderHook(() => useCancelFulfillment(), { wrapper });
    await act(async () => { await cancel.result.current.mutateAsync({ id: "f-3" }); });
    expect(merchantServiceMock.cancelFulfillment).toHaveBeenCalledWith("f-3", "idem-key");

    const adjust = renderHook(() => useInventoryMutation("adjust", "SKU-1"), { wrapper });
    await act(async () => { await adjust.result.current.mutateAsync({ delta: 2 } as never); });
    expect(merchantServiceMock.adjustInventory).toHaveBeenCalledWith("SKU-1", { delta: 2 }, "idem-key");

    const transfer = renderHook(() => useInventoryMutation("transfer", "SKU-1"), { wrapper });
    await act(async () => { await transfer.result.current.mutateAsync({ to: "warehouse", quantity: 1 } as never); });
    expect(merchantServiceMock.transferInventory).toHaveBeenCalledWith("SKU-1", { to: "warehouse", quantity: 1 }, "idem-key");

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["merchant"] });
  });
});
