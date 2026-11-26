import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const vendorsServiceMock = vi.hoisted(() => ({
  list: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn(), ledger: vi.fn(),
  payouts: vi.fn(), createPayout: vi.fn(), markPayoutPaid: vi.fn(), cancelPayout: vi.fn(),
}));
const subscriptionsServiceMock = vi.hoisted(() => ({
  plans: vi.fn(), createPlan: vi.fn(), updatePlan: vi.fn(), deletePlan: vi.fn(),
}));
const taxRulesServiceMock = vi.hoisted(() => ({
  list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(),
}));
const usersServiceMock = vi.hoisted(() => ({
  list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn(),
}));
vi.mock("@/services/vendors.service", () => ({ vendorsService: vendorsServiceMock }));
vi.mock("@/services/subscriptions.service", () => ({ subscriptionsService: subscriptionsServiceMock }));
vi.mock("@/services/tax-rules.service", () => ({ taxRulesService: taxRulesServiceMock }));
vi.mock("@/services/users.service", () => ({ usersService: usersServiceMock }));

import {
  useCancelVendorPayout,
  useCreateVendor,
  useCreateVendorPayout,
  useDeleteVendor,
  useMarkVendorPayoutPaid,
  useUpdateVendor,
  useVendorLedger,
  useVendorPayouts,
  useVendors,
} from "@/hooks/use-vendors";
import {
  useCreateSubscriptionPlan,
  useDeleteSubscriptionPlan,
  useSubscriptionPlans,
  useUpdateSubscriptionPlan,
} from "@/hooks/use-subscription-plans";
import { useCreateTaxRule, useDeleteTaxRule, useTaxRules, useUpdateTaxRule } from "@/hooks/use-tax-rules";
import { useCreateUser, useDeleteUser, useUpdateUser, useUsers } from "@/hooks/use-users";

const vendor = { id: "v-1", name: "North Loom", slug: "north-loom", status: "active", commission_bps: 500 };

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return { client, wrapper };
}

beforeEach(() => {
  vi.clearAllMocks();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("vendor hooks", () => {
  it("returns vendor data and falls back to an empty list for malformed payloads", async () => {
    vendorsServiceMock.list.mockResolvedValueOnce({ data: [vendor], meta: { total: 1 } });
    const { wrapper } = setup();
    const { result } = renderHook(() => useVendors({ state: "active" }), { wrapper });
    expect(result.current.vendors).toEqual([]);
    await waitFor(() => expect(result.current.vendors).toEqual([vendor]));
    expect(result.current.meta).toEqual({ total: 1 });

    vendorsServiceMock.list.mockResolvedValueOnce({ data: null, meta: undefined });
    const second = renderHook(() => useVendors(), { wrapper });
    await waitFor(() => expect(second.result.current.isLoading).toBe(false));
    expect(second.result.current.vendors).toEqual([]);
  });

  it("creates updates and deletes vendors with idempotency and invalidates caches", async () => {
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    vendorsServiceMock.create.mockResolvedValueOnce(vendor);
    vendorsServiceMock.update.mockResolvedValueOnce({ ...vendor, status: "suspended" });
    vendorsServiceMock.delete.mockResolvedValueOnce(undefined);

    const create = renderHook(() => useCreateVendor(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ name: "North Loom", slug: "north-loom" }); });
    expect(vendorsServiceMock.create).toHaveBeenCalledWith({ name: "North Loom", slug: "north-loom" }, expect.any(String));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["vendors"] });

    const update = renderHook(() => useUpdateVendor(), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ id: "v-1", data: { status: "suspended" } }); });
    expect(vendorsServiceMock.update).toHaveBeenCalledWith("v-1", { status: "suspended" }, expect.any(String));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["products"] });

    const remove = renderHook(() => useDeleteVendor(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync("v-1"); });
    expect(vendorsServiceMock.delete).toHaveBeenCalledWith("v-1", expect.any(String));
  });

  it("reconciles a 409 conflict by invalidating the vendor list", async () => {
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    vendorsServiceMock.create.mockRejectedValueOnce(Object.assign(new Error("conflict"), { status: 409 }));
    const create = renderHook(() => useCreateVendor(), { wrapper });
    await act(async () => {
      await expect(create.result.current.mutateAsync({ name: "Dup" })).rejects.toThrow("conflict");
    });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["vendors"] });
    await waitFor(() => expect(create.result.current.isError).toBe(true));
  });

  it("only loads the ledger and payouts when a vendor is selected", async () => {
    const { wrapper } = setup();
    const disabled = renderHook(() => useVendorLedger(undefined), { wrapper });
    await waitFor(() => expect(disabled.result.current.isLoading).toBe(false));
    expect(vendorsServiceMock.ledger).not.toHaveBeenCalled();

    vendorsServiceMock.ledger.mockResolvedValueOnce({ entries: [], totals: { available: 0 } });
    const ledger = renderHook(() => useVendorLedger("v-1", { state: "available" }), { wrapper });
    await waitFor(() => expect(ledger.result.current.ledger).toEqual({ entries: [], totals: { available: 0 } }));
    expect(vendorsServiceMock.ledger).toHaveBeenCalledWith("v-1", { state: "available" });

    vendorsServiceMock.payouts.mockResolvedValueOnce({ not: "an array" });
    const payouts = renderHook(() => useVendorPayouts("v-1"), { wrapper });
    await waitFor(() => expect(payouts.result.current.isLoading).toBe(false));
    expect(payouts.result.current.payouts).toEqual([]);
  });

  it("creates, marks paid and cancels payouts while invalidating finance caches", async () => {
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    vendorsServiceMock.createPayout.mockResolvedValueOnce({ id: "p-1" });
    vendorsServiceMock.markPayoutPaid.mockResolvedValueOnce({ id: "p-1", state: "paid" });
    vendorsServiceMock.cancelPayout.mockResolvedValueOnce({ id: "p-2", state: "cancelled" });

    const create = renderHook(() => useCreateVendorPayout("v-1"), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ period_start: "2026-11-01", period_end: "2026-11-21" }); });
    expect(vendorsServiceMock.createPayout).toHaveBeenCalledWith(
      "v-1",
      { period_start: "2026-11-01", period_end: "2026-11-21" },
      expect.any(String),
    );
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["vendor-ledger"] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["vendor-payouts"] });

    const mark = renderHook(() => useMarkVendorPayoutPaid("v-1"), { wrapper });
    await act(async () => { await mark.result.current.mutateAsync("p-1"); });
    expect(vendorsServiceMock.markPayoutPaid).toHaveBeenCalledWith("p-1");

    const cancel = renderHook(() => useCancelVendorPayout("v-1"), { wrapper });
    await act(async () => { await cancel.result.current.mutateAsync("p-2"); });
    expect(vendorsServiceMock.cancelPayout).toHaveBeenCalledWith("p-2");
  });
});

describe("subscription plan hooks", () => {
  it("lists plans, tolerates malformed payloads and mutates plans idempotently", async () => {
    subscriptionsServiceMock.plans.mockResolvedValueOnce([{ id: "pl-1", name: "Monthly" }]);
    subscriptionsServiceMock.createPlan.mockResolvedValue({ id: "pl-2" });
    subscriptionsServiceMock.updatePlan.mockResolvedValue({ id: "pl-2" });
    subscriptionsServiceMock.deletePlan.mockResolvedValue(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const list = renderHook(() => useSubscriptionPlans({ state: "active" }), { wrapper });
    await waitFor(() => expect(list.result.current.plans).toEqual([{ id: "pl-1", name: "Monthly" }]));

    const input = {
      name: "Monthly", amount_minor: 1000, currency: "VND", interval: "month", interval_count: 1, state: "active",
    };
    const create = renderHook(() => useCreateSubscriptionPlan(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync(input); });
    expect(subscriptionsServiceMock.createPlan).toHaveBeenCalledWith(input, expect.any(String));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["subscription-plans"] });

    const update = renderHook(() => useUpdateSubscriptionPlan(), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ id: "pl-2", data: input }); });
    expect(subscriptionsServiceMock.updatePlan).toHaveBeenCalledWith("pl-2", input, expect.any(String));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["products"] });

    const remove = renderHook(() => useDeleteSubscriptionPlan(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync("pl-2"); });
    expect(subscriptionsServiceMock.deletePlan).toHaveBeenCalledWith("pl-2", expect.any(String));

    subscriptionsServiceMock.plans.mockResolvedValueOnce(null);
    const empty = renderHook(() => useSubscriptionPlans(), { wrapper });
    await waitFor(() => expect(empty.result.current.isLoading).toBe(false));
    expect(empty.result.current.plans).toEqual([]);
  });
});

describe("tax rule hooks", () => {
  it("lists rules and invalidates on every write", async () => {
    taxRulesServiceMock.list.mockResolvedValueOnce([{ id: "t1", code: "vn" }]);
    taxRulesServiceMock.create.mockResolvedValue({ id: "t2" });
    taxRulesServiceMock.update.mockResolvedValue({ id: "t2" });
    taxRulesServiceMock.remove.mockResolvedValue(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const list = renderHook(() => useTaxRules({ country: "VN" }), { wrapper });
    await waitFor(() => expect(list.result.current.rules).toEqual([{ id: "t1", code: "vn" }]));
    expect(taxRulesServiceMock.list).toHaveBeenCalledWith({ country: "VN" });

    const create = renderHook(() => useCreateTaxRule(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ code: "t2" } as never); });
    expect(taxRulesServiceMock.create).toHaveBeenCalledWith({ code: "t2" });
    const update = renderHook(() => useUpdateTaxRule(), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ id: "t2", data: { state: "disabled" } }); });
    expect(taxRulesServiceMock.update).toHaveBeenCalledWith("t2", { state: "disabled" });
    const remove = renderHook(() => useDeleteTaxRule(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync("t2"); });
    expect(taxRulesServiceMock.remove).toHaveBeenCalledWith("t2");
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["tax-rules"] });

    taxRulesServiceMock.list.mockResolvedValueOnce({});
    const empty = renderHook(() => useTaxRules(), { wrapper });
    await waitFor(() => expect(empty.result.current.isLoading).toBe(false));
    expect(empty.result.current.rules).toEqual([]);
  });
});

describe("user hooks", () => {
  it("dedupes list results and mutates users with idempotency", async () => {
    usersServiceMock.list.mockResolvedValueOnce({
      data: [{ id: 1, name: "A" }, { id: 1, name: "A" }, { id: 2, name: "B" }, { name: "no-id" }],
      meta: { total: 3 },
    });
    usersServiceMock.create.mockResolvedValue({ id: 3 });
    usersServiceMock.update.mockResolvedValue({ id: 3 });
    usersServiceMock.delete.mockResolvedValue(undefined);
    const { client, wrapper } = setup();
    const invalidate = vi.spyOn(client, "invalidateQueries");

    const list = renderHook(() => useUsers(), { wrapper });
    await waitFor(() => expect(list.result.current.users).toHaveLength(3));
    expect(list.result.current.users.map((user) => user.id)).toEqual([1, 2, undefined]);

    const create = renderHook(() => useCreateUser(), { wrapper });
    await act(async () => { await create.result.current.mutateAsync({ name: "C" } as never); });
    expect(usersServiceMock.create).toHaveBeenCalledWith({ name: "C" }, expect.any(String));
    const update = renderHook(() => useUpdateUser(3), { wrapper });
    await act(async () => { await update.result.current.mutateAsync({ name: "D" } as never); });
    expect(usersServiceMock.update).toHaveBeenCalledWith(3, { name: "D" }, expect.any(String));
    const remove = renderHook(() => useDeleteUser(), { wrapper });
    await act(async () => { await remove.result.current.mutateAsync(3); });
    expect(usersServiceMock.delete).toHaveBeenCalledWith(3, expect.any(String));
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["users"] });
  });
});
