import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import VendorsPage from "@/app/(dashboard)/vendors/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const envelope = (body: unknown) => Promise.resolve({ data: body, status: 200, headers: new Headers() });
const vendor = { id: "v-1", name: "North Loom", slug: "north-loom", status: "active", commission_bps: 500, contact_email: null, description: "Independent studio", products_count: 2, created_at: "2026-11-20T10:00:00Z" };
const ledgerEntry = { id: "l-1", vendor_id: "v-1", order_id: "o-1", order_line_id: "ol-1", type: "sale", gross_minor: 2000, commission_minor: 100, net_minor: 1900, currency: "VND", state: "available", payout_id: null, created_at: "2026-11-20T10:00:00Z" };
const payout = { id: "p-1", vendor_id: "v-1", amount_minor: 1900, currency: "VND", state: "pending", period_start: "2026-11-01 00:00:00", period_end: "2026-11-21 23:59:59", entries_count: 1, created_at: "2026-11-21T10:00:00Z", paid_at: null, cancelled_at: null };

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/vendors") return envelope({ data: [vendor], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } });
    if (path === "/api/admin/vendors/v-1/ledger") return envelope({ data: { vendor_id: "v-1", entries: [ledgerEntry], totals: { gross: 2000, commission: 100, net: 1900, pending: 0, available: 1900, paid: 0 } } });
    if (path === "/api/admin/vendors/v-1/payouts") return envelope({ data: [{ ...payout, id: "p-1" }, { ...payout, id: "p-2" }] });
    return envelope({ data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } });
  });
  apiMock.post.mockResolvedValue(ok({ ...vendor, id: "v-2", name: "Second Loom", slug: "second-loom" }));
  apiMock.put.mockResolvedValue(ok({ ...vendor, status: "suspended" }));
  apiMock.delete.mockResolvedValue({ data: {}, status: 204, headers: new Headers() });
});

afterEach(() => {
  cleanup();
  useAuthStore.setState({ user: null, isAuthenticated: false });
});

describe("vendors admin page", () => {
  it("renders vendors with product counts and commission", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    expect(screen.getByText("north-loom")).toBeVisible();
    expect(screen.getByText("2")).toBeVisible();
    expect(screen.getByText("500 bps")).toBeVisible();
  });

  it("creates a vendor through the admin contract", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Create vendor" }));
    const dialog = await screen.findByRole("dialog");
    fireEvent.change(within(dialog).getByLabelText("Name"), { target: { value: "Second Loom" } });
    fireEvent.change(within(dialog).getByLabelText("Slug"), { target: { value: "second-loom" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create vendor" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/vendors", expect.objectContaining({ name: "Second Loom", slug: "second-loom" }), expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("deletes a vendor only after confirmation", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Delete North Loom" }));
    const dialog = await screen.findByRole("dialog");
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/vendors/v-1", expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("suspends an active vendor", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Suspend North Loom" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith("/api/admin/vendors/v-1", { status: "suspended" }, expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("renders vendor balances, ledger and payout history", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Manage payouts North Loom" }));
    expect(await screen.findByText("North Loom payouts")).toBeVisible();
    expect(await screen.findByRole("table", { name: "Vendor ledger entries" })).toBeVisible();
    await waitFor(() => expect(screen.getByLabelText("Available balance")).toHaveTextContent("1,900"));
    expect(screen.getByText("sale")).toBeVisible();
    expect(await screen.findByRole("table", { name: "Vendor payout history" })).toBeVisible();
    expect(await screen.findByRole("button", { name: "Mark payout p-1 paid" })).toBeVisible();
    expect(await screen.findByRole("button", { name: "Export bank CSV p-1" })).toBeVisible();
  });

  it("creates a payout window with an idempotency key", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Manage payouts North Loom" }));
    expect(await screen.findByText("North Loom payouts")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Period start"), { target: { value: "2026-11-01" } });
    fireEvent.change(screen.getByLabelText("Period end"), { target: { value: "2026-11-21" } });
    fireEvent.click(screen.getByRole("button", { name: "Create payout" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalled());
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/vendors/v-1/payouts",
      { period_start: "2026-11-01", period_end: "2026-11-21" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("marks a payout paid only after confirmation", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Manage payouts North Loom" }));
    expect(await screen.findByText("North Loom payouts")).toBeVisible();
    const markButton = await screen.findByRole("button", { name: "Mark payout p-1 paid" });
    fireEvent.click(markButton);
    expect(await screen.findByText("Mark payout paid?")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Mark paid" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/vendor-payouts/p-1/mark-paid", {}));
  });

  it("cancels a payout only after confirmation", async () => {
    renderWithProviders(<VendorsPage />);
    expect(await screen.findByText("North Loom")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Manage payouts North Loom" }));
    expect(await screen.findByText("North Loom payouts")).toBeVisible();
    const cancelButton = await screen.findByRole("button", { name: "Cancel payout p-2" });
    fireEvent.click(cancelButton);
    expect(await screen.findByText("Cancel payout?")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Cancel payout" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/vendor-payouts/p-2/cancel", {}));
  });
});
