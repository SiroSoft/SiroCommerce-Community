import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
const fetchWithAuthMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/api", () => ({
  default: apiMock,
  fetchWithAuth: fetchWithAuthMock,
  scopeCoordinator: { value: null },
}));

import { VendorPayoutsDialog } from "@/modules/vendors/components/vendor-payouts-dialog";
import type { Vendor } from "@/services/vendors.service";

const vendor: Vendor = {
  id: "v-1",
  name: "North Loom",
  slug: "north-loom",
  status: "active",
  commission_bps: 500,
};

const ledger = {
  vendor_id: "v-1",
  entries: [{
    id: "l-1", vendor_id: "v-1", type: "sale", gross_minor: 2000, commission_minor: 100,
    net_minor: 1900, currency: "VND", state: "available", created_at: "2026-11-20T10:00:00Z",
  }],
  totals: { gross: 2000, commission: 100, net: 1900, pending: 0, available: 1900, paid: 0 },
};

const payout = {
  id: "p-1", vendor_id: "v-1", amount_minor: 1900, currency: "VND", state: "pending",
  period_start: "2026-11-01 00:00:00", period_end: "2026-11-21 23:59:59", entries_count: 1,
  created_at: "2026-11-21T10:00:00Z", paid_at: null, cancelled_at: null,
};

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <VendorPayoutsDialog open vendor={vendor} onOpenChange={() => undefined} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  apiMock.get.mockImplementation((path: string) => {
    if (path.endsWith("/ledger")) return Promise.resolve({ data: { data: ledger } });
    if (path.endsWith("/payouts")) return Promise.resolve({ data: { data: [payout] } });
    return Promise.resolve({ data: { data: [] } });
  });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("VendorPayoutsDialog", () => {
  it("renders balances, ledger rows and payout history", async () => {
    renderDialog();
    expect(await screen.findByRole("table", { name: "Vendor ledger entries" })).toBeTruthy();
    expect(screen.getByLabelText("Available balance")).toHaveTextContent("1,900");
    expect(await screen.findByRole("button", { name: "Export bank CSV p-1" })).toBeTruthy();
  });

  it("exports the bank CSV and revokes the object URL", async () => {
    const createObjectURL = vi.fn(() => "blob:mock");
    const revokeObjectURL = vi.fn();
    Object.defineProperty(URL, "createObjectURL", { value: createObjectURL, configurable: true });
    Object.defineProperty(URL, "revokeObjectURL", { value: revokeObjectURL, configurable: true });
    const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
    fetchWithAuthMock.mockResolvedValueOnce(new Response("a,b", {
      status: 200,
      headers: {
        "Content-Disposition": 'attachment; filename="payout-p-1.csv"',
        "X-Export-Rows": "1",
      },
    }));

    renderDialog();
    fireEvent.click(await screen.findByRole("button", { name: "Export bank CSV p-1" }));

    await waitFor(() => expect(fetchWithAuthMock).toHaveBeenCalledWith(
      "/api/admin/vendor-payouts/p-1/export.csv",
      expect.objectContaining({ method: "GET" }),
    ));
    expect(createObjectURL).toHaveBeenCalled();
    expect(click).toHaveBeenCalled();
    expect(revokeObjectURL).toHaveBeenCalledWith("blob:mock");
  });

  it("surfaces an export failure without crashing the dialog", async () => {
    fetchWithAuthMock.mockResolvedValueOnce(new Response(JSON.stringify({ message: "No bank details" }), {
      status: 422,
      headers: { "Content-Type": "application/json" },
    }));
    renderDialog();
    fireEvent.click(await screen.findByRole("button", { name: "Export bank CSV p-1" }));
    await waitFor(() => expect(fetchWithAuthMock).toHaveBeenCalled());
    expect(screen.getByRole("button", { name: "Export bank CSV p-1" })).toBeEnabled();
  });

  it("renders empty ledger and payout states", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path.endsWith("/ledger")) return Promise.resolve({
        data: { data: { entries: [], totals: { gross: 0, commission: 0, net: 0, pending: 0, available: 0, paid: 0 } } },
      });
      return Promise.resolve({ data: { data: [] } });
    });
    renderDialog();
    expect(await screen.findByText("No commission entries yet.")).toBeTruthy();
    expect(await screen.findByText("No payout batches yet.")).toBeTruthy();
  });

  it("renders the ledger error message", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path.endsWith("/ledger")) return Promise.reject(Object.assign(new Error("Ledger offline"), { status: 503 }));
      return Promise.resolve({ data: { data: [] } });
    });
    renderDialog();
    expect(await screen.findByRole("alert")).toHaveTextContent("Ledger offline");
  });
});
