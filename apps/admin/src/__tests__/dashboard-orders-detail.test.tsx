import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import OrderDetailRoute from "@/app/(dashboard)/orders/[id]/page";
import OrdersPage from "@/app/(dashboard)/orders/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "admin" }, isLoading: false, isAuthenticated: true }) }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const order = {
  id: 11,
  status: "pending",
  customer_email: "buyer@example.test",
  user_name: "Buyer",
  total: 250000,
  created_at: "2026-06-01T10:00:00Z",
  pickup_point_code: "HN-01",
  items: [
    { id: 1, product_id: 3, product_name: "Desk lamp", quantity: 2, subtotal: 200000, unit_price: 100000 },
    { id: 2, product_id: 4, product_name: "Cable", quantity: 1, subtotal: 50000, unit_price: 50000 },
  ],
  payments: [{ id: "pay-1", provider: "stripe", state: "captured", amount: 250000, captured: 250000, refunded: 0 }],
  payment_id: "pay-1",
};

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/orders/11") return body(order);
    if (path === "/api/orders") return Promise.resolve({ data: { data: [order], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } }, status: 200, headers: new Headers() });
    if (path === "/api/admin/orders/11") return body(order);
    if (path === "/api/v2/payments/pay-1/status") return body({ id: "pay-1", provider: "stripe", state: "captured", proof_reference: "BANK-REF", proof_url: "https://cdn.test/proof.png" });
    if (path === "/api/admin/orders/11/timeline") return body([{ id: "ev-1", event_type: "order.created", created_at: "2026-06-01T10:00:00Z", actor: "system" }]);
    return body({});
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/admin/orders/11/transition") return body({ ...order, status: "confirmed" });
    if (path === "/api/admin/orders/11/refund-to-credit") return body({ order: { id: "11" }, credit: { id: "gc-9", code: "GC-009" } });
    if (path === "/api/v2/admin/workbench/bulk-orders") return body({ succeeded: [{ id: 11 }], failed: [] });
    return body({ id: "ok" });
  });
});

afterEach(() => {
  cleanup();
});

describe("order detail route", () => {
  it("renders order items, payments, pickup lane and payment proof", async () => {
    renderWithProviders(await OrderDetailRoute({ params: Promise.resolve({ id: "11" }) }));
    expect(await screen.findByText("Order #11")).toBeVisible();
    expect(screen.getByText("Desk lamp")).toBeVisible();
    expect(screen.getAllByText("stripe").length).toBeGreaterThan(0);
    expect(screen.getByText(/Store pickup at/)).toBeVisible();
    expect(await screen.findByText(/BANK-REF/)).toBeVisible();
    expect(await screen.findByText("order.created")).toBeVisible();
  });

  it("transitions and cancels the order through backend-authoritative routes", async () => {
    renderWithProviders(await OrderDetailRoute({ params: Promise.resolve({ id: "11" }) }));
    await screen.findByText("Order #11");
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/transition",
      { to: "confirmed" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Order updated to confirmed/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    const cancel = await screen.findByRole("dialog", { name: "Cancel order" });
    fireEvent.click(within(cancel).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/cancel",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("refunds a specific amount, the remainder and to store credit", async () => {
    renderWithProviders(await OrderDetailRoute({ params: Promise.resolve({ id: "11" }) }));
    await screen.findByText("Order #11");
    fireEvent.change(screen.getByLabelText("Refund amount in minor units"), { target: { value: "1000" } });
    fireEvent.click(screen.getByRole("button", { name: "Refund" }));
    const refund = await screen.findByRole("dialog", { name: "Refund payment" });
    fireEvent.click(within(refund).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/refund",
      { amount_minor: 1000 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Refund remaining" }));
    const remaining = await screen.findByRole("dialog", { name: "Refund remaining balance" });
    fireEvent.click(within(remaining).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/refund-remaining",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Refund to credit" }));
    const credit = await screen.findByRole("dialog", { name: "Refund to store credit" });
    fireEvent.click(within(credit).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/refund-to-credit",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/GC-009/)).toBeVisible();
  });

  it("refunds lines, requests returns and issues digital downloads from the panels", async () => {
    renderWithProviders(await OrderDetailRoute({ params: Promise.resolve({ id: "11" }) }));
    await screen.findByText("Order #11");
    fireEvent.change(screen.getByLabelText("Reason (optional)", { selector: "#refund-lines-reason-11" }), { target: { value: "damaged" } });
    fireEvent.click(screen.getByRole("button", { name: "Refund lines" }));
    const refundLines = await screen.findByRole("dialog", { name: "Refund order lines" });
    fireEvent.click(within(refundLines).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/refund-lines",
      expect.objectContaining({ reason: "damaged", lines: expect.any(Array) }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Fulfillment ID"), { target: { value: "ff-1" } });
    fireEvent.change(screen.getByLabelText("Reason (optional)", { selector: "#order-return-reason-11" }), { target: { value: "wrong size" } });
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    const orderReturn = await screen.findByRole("dialog", { name: "Request order return" });
    fireEvent.click(within(orderReturn).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/returns",
      expect.objectContaining({ order_id: "11", fulfillment_id: "ff-1", reason: "wrong size" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Asset ID"), { target: { value: "asset-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Issue download" }));
    const download = await screen.findByRole("dialog", { name: "Issue digital download" });
    fireEvent.click(within(download).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/orders/11/downloads",
      { asset_id: "asset-1", order_id: "11" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Download issued.")).toBeVisible();
  });

  it("validates refund line JSON and required return fields", async () => {
    renderWithProviders(await OrderDetailRoute({ params: Promise.resolve({ id: "11" }) }));
    await screen.findByText("Order #11");
    fireEvent.change(screen.getByLabelText("Lines JSON", { selector: "#refund-lines-11" }), { target: { value: "not-json" } });
    fireEvent.click(screen.getByRole("button", { name: "Refund lines" }));
    expect(await screen.findByText("Lines must be valid JSON.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Lines JSON", { selector: "#refund-lines-11" }), { target: { value: "[]" } });
    fireEvent.click(screen.getByRole("button", { name: "Refund lines" }));
    expect(await screen.findByText(/Provide at least one line/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    expect(await screen.findByText(/Fulfillment ID and at least one line/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Issue download" }));
    expect(await screen.findByText("Asset ID is required.")).toBeVisible();
  });
});

describe("orders list page", () => {
  it("renders orders and bulk transitions the selection", async () => {
    renderWithProviders(<OrdersPage />);
    expect((await screen.findAllByText("#11")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Buyer").length).toBeGreaterThan(0);
    fireEvent.click(screen.getAllByRole("checkbox", { name: "Select order 11" })[0]);
    fireEvent.change(screen.getByRole("combobox", { name: "Bulk target status" }), { target: { value: "confirmed" } });
    fireEvent.click(screen.getByRole("button", { name: "Apply to selected" }));
    const dialog = await screen.findByRole("dialog", { name: "Bulk order transition" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/bulk-orders",
      { ids: ["11"], to: "confirmed" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/1 succeeded, 0 failed/)).toBeVisible();
  });

  it("renders the empty order state", async () => {
    apiMock.get.mockResolvedValueOnce(Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } }, status: 200, headers: new Headers() }));
    renderWithProviders(<OrdersPage />);
    expect(await screen.findByText("No orders yet")).toBeVisible();
  });
});
