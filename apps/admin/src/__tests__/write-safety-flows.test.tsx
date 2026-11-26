import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import { ProductTable } from "@/modules/products/components/product-table";
import InventoryPage from "@/app/(dashboard)/inventory/page";
import FinancePage from "@/app/(dashboard)/finance/page";
import B2bPage from "@/app/(dashboard)/b2b/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/" }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "admin", email: "admin@example.test" }, login: vi.fn(), logout: vi.fn(), isLoginPending: false, loginError: null, isLoading: false, isAuthenticated: true, refetchSession: vi.fn() }) }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin", email: "admin@example.test" } as never, isAuthenticated: true });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("product lifecycle write safety", () => {
  const product = { id: 7, name: "Desk lamp", sku: "DL-1", price: 25, stock: 4, is_active: true, status: "published", created_at: "2026-01-01" };

  function mockList() {
    apiMock.get.mockImplementation((path: string) =>
      path === "/api/admin/products"
        ? Promise.resolve({ data: { data: [product], meta: { page: 1, last_page: 1, total: 1 } }, status: 200, headers: new Headers() })
        : ok({}),
    );
  }

  it("asks for confirmation before archiving and sends an idempotency key", async () => {
    mockList();
    apiMock.post.mockResolvedValue(ok({ id: 7 }));
    renderWithProviders(<ProductTable onEdit={vi.fn()} onCreate={vi.fn()} params={{ page: 1 }} onParamsChange={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Archive Desk lamp" }));
    const dialog = await screen.findByRole("dialog", { name: "Archive product" });
    expect(within(dialog).getByText(/Desk lamp/)).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.click(within(dialog).getByRole("button", { name: "Archive" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/products/7/lifecycle",
      expect.objectContaining({ state: "archived" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("aborts the lifecycle change when confirmation is declined", async () => {
    mockList();
    renderWithProviders(<ProductTable onEdit={vi.fn()} onCreate={vi.fn()} params={{ page: 1 }} onParamsChange={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Archive Desk lamp" }));
    const dialog = await screen.findByRole("dialog", { name: "Archive product" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Archive product" })).toBeNull());
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("shows the failure with a retry that re-sends the change", async () => {
    mockList();
    apiMock.post.mockRejectedValueOnce(Object.assign(new Error("conflict"), { status: 409 }));
    apiMock.post.mockResolvedValue(ok({ id: 7 }));
    renderWithProviders(<ProductTable onEdit={vi.fn()} onCreate={vi.fn()} params={{ page: 1 }} onParamsChange={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Archive Desk lamp" }));
    const dialog = await screen.findByRole("dialog", { name: "Archive product" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Archive" }));
    const retry = await screen.findByRole("button", { name: /retry lifecycle change/i });
    expect(retry).toBeVisible();
    fireEvent.click(retry);
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(2));
  });
});

describe("inventory adjustment write safety", () => {
  function mockStock() {
    apiMock.get.mockImplementation((path: string) =>
      path === "/api/admin/inventory/sku-1"
        ? ok({ variant_id: "sku-1", source_id: "main", on_hand: 10, reserved: 2, available: 8 })
        : ok({}),
    );
  }

  it("confirms the adjustment and retries the exact payload after failure", async () => {
    mockStock();
    apiMock.put.mockRejectedValueOnce(Object.assign(new Error("timeout"), { status: 503 }));
    apiMock.put.mockResolvedValue(ok({ variant_id: "sku-1" }));
    renderWithProviders(<InventoryPage />);
    fireEvent.change(screen.getByRole("textbox", { name: "Variant ID" }), { target: { value: "sku-1" } });
    expect(await screen.findByText("sku-1")).toBeVisible();
    fireEvent.change(screen.getByRole("spinbutton", { name: "New on-hand quantity" }), { target: { value: "12" } });
    fireEvent.click(screen.getByRole("button", { name: "Apply adjustment" }));
    const dialog = await screen.findByRole("dialog", { name: "Apply inventory adjustment" });
    expect(within(dialog).getByText(/12/)).toBeVisible();
    expect(apiMock.put).not.toHaveBeenCalled();
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/inventory/sku-1",
      { on_hand: 12 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(await screen.findByRole("button", { name: /try again/i }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(2));
    expect(apiMock.put).toHaveBeenNthCalledWith(2, "/api/admin/inventory/sku-1", { on_hand: 12 }, expect.objectContaining({ idempotencyKey: expect.any(String) }));
  });

  it("does not send the adjustment when confirmation is declined", async () => {
    mockStock();
    renderWithProviders(<InventoryPage />);
    fireEvent.change(screen.getByRole("textbox", { name: "Variant ID" }), { target: { value: "sku-1" } });
    expect(await screen.findByText("sku-1")).toBeVisible();
    fireEvent.change(screen.getByRole("spinbutton", { name: "New on-hand quantity" }), { target: { value: "12" } });
    fireEvent.click(screen.getByRole("button", { name: "Apply adjustment" }));
    const dialog = await screen.findByRole("dialog", { name: "Apply inventory adjustment" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Apply inventory adjustment" })).toBeNull());
    expect(apiMock.put).not.toHaveBeenCalled();
  });
});

describe("finance invoice issue write safety", () => {
  const row = { invoice_id: "inv-1", invoice_number: "INV-001", order_id: "order-1", state: "issued", currency: "USD", minor_unit: 2, amount_minor: 2500, credited_minor: 0, reconciliation: "balanced", evidence_available: true };
  const invoice = { id: "inv-1", number: "INV-001", order_id: "order-1", payment_id: "pay-1", state: "draft", currency: "USD", minor_unit: 2, amount_minor: 2500, issued_at: null };

  function mockFinance() {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/finance/reconciliation") return Promise.resolve({ data: { data: [row], meta: { page: 1, per_page: 25, total: 1, last_page: 1 } }, status: 200, headers: new Headers() });
      if (path === "/api/admin/invoices/inv-1") return ok(invoice);
      return ok({});
    });
  }

  it("confirms the issue and retries the same request after failure", async () => {
    mockFinance();
    apiMock.post.mockRejectedValueOnce(Object.assign(new Error("timeout"), { status: 503 }));
    apiMock.post.mockResolvedValue(ok({ ...invoice, state: "issued" }));
    renderWithProviders(<FinancePage />);
    await screen.findByText("INV-001");
    fireEvent.change(screen.getByLabelText("Invoice ID"), { target: { value: "inv-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Load invoice" }));
    expect(await screen.findByText(/Payment pay-1/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Issue invoice" }));
    const dialog = await screen.findByRole("dialog", { name: "Issue invoice" });
    expect(within(dialog).getByText(/inv-1/)).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/invoices/inv-1/issue",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(await screen.findByRole("button", { name: /try again/i }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(2));
    expect(await screen.findByText("Invoice issued.")).toBeVisible();
  });
});

describe("b2b negotiated-price validation", () => {
  beforeEach(() => {
    apiMock.get.mockResolvedValue(ok([]));
    apiMock.post.mockResolvedValue(ok({ id: "np-1" }));
  });

  it("blocks non-positive prices before transport and accepts valid minor units", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.change(screen.getByLabelText("Organization ID"), { target: { value: "c-1" } });
    fireEvent.change(screen.getAllByLabelText("Variant ID")[0], { target: { value: "v-1" } });
    fireEvent.change(screen.getByLabelText("Unit price in minor units"), { target: { value: "0" } });
    fireEvent.click(screen.getByRole("button", { name: "Save negotiated price" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/greater than zero/i);
    expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText("Unit price in minor units"), { target: { value: "1200" } });
    fireEvent.click(screen.getByRole("button", { name: "Save negotiated price" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/negotiated-prices",
      expect.objectContaining({ variant_id: "v-1", unit_price_minor: 1200 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("confirms quote approval and reports success", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.click(screen.getByRole("tab", { name: "Quotes" }));
    fireEvent.change(screen.getByLabelText("Quote ID"), { target: { value: "q-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Approve quote" }));
    expect(confirm).toHaveBeenCalledWith(expect.stringMatching(/approve quote/i));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/quotes/q-1/approve",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/quote approved/i)).toBeVisible();
  });
});
