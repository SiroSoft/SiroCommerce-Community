import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  isPositiveInteger,
  isValidRefundAmount,
  parseJsonArraySafe,
  parseJsonObjectSafe,
  validateAllocateLines,
  validateReturnLines,
} from "@/app/(dashboard)/fulfillment/validators";
import { ordersService } from "@/services/orders.service";
import { merchantService } from "@/services/merchant.service";
import { operationsService } from "@/services/operations.service";
import { adminWorkbenchService } from "@/services/admin-workbench.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));

describe("orders & fulfillment payload validators", () => {
  it("accepts only positive integer quantities", () => {
    expect(isPositiveInteger(1)).toBe(true);
    expect(isPositiveInteger(0)).toBe(false);
    expect(isPositiveInteger(-2)).toBe(false);
    expect(isPositiveInteger(1.5)).toBe(false);
    expect(isPositiveInteger("1")).toBe(false);
  });

  it("validates refund amounts as positive minor-unit integers", () => {
    expect(isValidRefundAmount("100")).toBe(true);
    expect(isValidRefundAmount("0")).toBe(false);
    expect(isValidRefundAmount("-5")).toBe(false);
    expect(isValidRefundAmount("10.5")).toBe(false);
    expect(isValidRefundAmount("")).toBe(false);
  });

  it("validates allocate lines require variant_id and qty", () => {
    expect(validateAllocateLines([{ variant_id: "v-1", qty: 2 }])).toEqual({ ok: true });
    expect(validateAllocateLines([]).ok).toBe(false);
    expect(validateAllocateLines([{ variant_id: "", qty: 1 }]).ok).toBe(false);
    expect(validateAllocateLines([{ variant_id: "v-1", qty: 0 }]).ok).toBe(false);
    expect(validateAllocateLines("nope").ok).toBe(false);
  });

  it("validates return lines require line_id and qty", () => {
    expect(validateReturnLines([{ line_id: "l-1", qty: 1 }])).toEqual({ ok: true });
    expect(validateReturnLines([]).ok).toBe(false);
    expect(validateReturnLines([{ line_id: "l-1", qty: -1 }]).ok).toBe(false);
    expect(validateReturnLines([{ qty: 1 }]).ok).toBe(false);
  });

  it("parses JSON objects and arrays safely", () => {
    expect(parseJsonObjectSafe('{"a":1}', "Shipping")).toEqual({ ok: true, value: { a: 1 } });
    expect(parseJsonObjectSafe("", "Shipping")).toEqual({ ok: true, value: {} });
    expect(parseJsonObjectSafe("[1]", "Shipping").ok).toBe(false);
    expect(parseJsonObjectSafe("{bad", "Shipping").ok).toBe(false);
    expect(parseJsonArraySafe('[{"a":1}]', "Lines").ok).toBe(true);
    expect(parseJsonArraySafe('{"a":1}', "Lines").ok).toBe(false);
  });
});

describe("orders service uses only real backend endpoints", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists and shows orders on existing routes", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [], meta: {} } });
    await ordersService.list({ page: 1 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/orders", expect.objectContaining({ params: { page: 1 } }));
    apiMock.get.mockResolvedValue({ data: { data: { id: 1 } } });
    await ordersService.get(1);
    expect(apiMock.get).toHaveBeenCalledWith("/api/orders/1");
  });

  it("sends transitions, cancels, and refunds with idempotency keys", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { id: 1 } } });
    await ordersService.transition(7, { to: "confirmed" }, "key-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/7/transition", { to: "confirmed" }, { idempotencyKey: "key-1" });
    await ordersService.cancel(7, {}, "key-2");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/7/cancel", {}, { idempotencyKey: "key-2" });
    await ordersService.refund(7, { amount_minor: 100 }, "key-3");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/7/refund", { amount_minor: 100 }, { idempotencyKey: "key-3" });
  });

  it("rejects non-positive refund amounts before any request", async () => {
    await expect(ordersService.refund(7, { amount_minor: 0 }, "k")).rejects.toThrow();
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("reconciles payments on the real route", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { inspected: 1, resolved: 0, unknown: 0 } } });
    await ordersService.reconcilePayments(false, "rec-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/payments/reconcile", { apply: false }, { idempotencyKey: "rec-1" });
  });
});

describe("fulfillment service uses only real backend endpoints", () => {
  beforeEach(() => vi.clearAllMocks());

  it("allocates, ships, delivers, cancels, and tracks", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { id: "f-1" } } });
    await merchantService.allocate({ order_id: "o-1", lines: [{ variant_id: "v-1", qty: 1 }] }, "k");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/allocate", { order_id: "o-1", lines: [{ variant_id: "v-1", qty: 1 }] }, { idempotencyKey: "k" });
    await merchantService.ship("f-1", undefined, "k");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f-1/ship", undefined, { idempotencyKey: "k" });
    await merchantService.deliver("f-1", "k");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f-1/deliver", undefined, { idempotencyKey: "k" });
    await merchantService.cancelFulfillment("f-1", "k");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f-1/cancel", undefined, { idempotencyKey: "k" });
    apiMock.get.mockResolvedValue({ data: { data: { id: "f-1" } } });
    await merchantService.track("f-1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/fulfillment/f-1/track");
  });
});

describe("operations and workbench services use only real backend endpoints", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists operations and retries/replays with idempotency keys", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [], meta: {} } });
    await operationsService.list({ page: 1, per_page: 25 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/operations", expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }));
    apiMock.post.mockResolvedValue({ data: { data: { id: "j-1" } } });
    await operationsService.retry("j-1", "k");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/operations/j-1/retry", undefined, { idempotencyKey: "k" });
    await operationsService.replayNotification("n-1", "k");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/operations/n-1/replay-notification", undefined, { idempotencyKey: "k" });
  });

  it("runs workbench approvals and exports on real routes", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { id: "a-1" } } });
    await adminWorkbenchService.requestApproval({ workflow_id: "w-1", resource_id: "o-1" }, "approval-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/workbench/approvals", { workflow_id: "w-1", resource_id: "o-1" }, { idempotencyKey: "approval-1" });
    await adminWorkbenchService.decideApproval("a-1", "approve", "decision-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/workbench/approvals/a-1/decision", { decision: "approve" }, { idempotencyKey: "decision-1" });
    await adminWorkbenchService.createExport({ dataset: "orders", format: "csv" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/exports", { dataset: "orders", format: "csv" }, expect.objectContaining({ idempotencyKey: expect.stringMatching(/^admin-export-/) }));
  });
});
