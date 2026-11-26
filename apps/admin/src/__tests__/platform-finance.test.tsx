import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import FinancePage from "@/app/(dashboard)/finance/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const row = { invoice_id: "inv-1", invoice_number: "INV-001", order_id: "order-1", state: "issued", currency: "USD", minor_unit: 2, amount_minor: 2500, credited_minor: 0, reconciliation: "balanced", evidence_available: true };
const invoice = { id: "inv-1", number: "INV-001", order_id: "order-1", payment_id: "pay-1", state: "draft", currency: "USD", minor_unit: 2, amount_minor: 2500, issued_at: null };

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/v2/admin/finance/reconciliation") return Promise.resolve({ data: { data: [row], meta: { page: 1, per_page: 25, total: 1, last_page: 1 } }, status: 200, headers: new Headers() });
    if (path === "/api/admin/invoices/inv-1") return ok(invoice);
    if (path === "/api/admin/invoices/inv-1/evidence") return ok({ invoice_id: "inv-1", evidence: { provider: "test" } });
    if (path === "/api/admin/credit-memos/cm-1") return ok({ id: "cm-1", invoice_id: "inv-1", state: "issued", currency: "USD", minor_unit: 2, amount_minor: 500, reason: "partial" });
    if (path === "/api/v2/payments/pay-1/status") return ok({ id: "pay-1", state: "captured" });
    return ok({});
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/admin/invoices") return ok({ ...invoice, id: "inv-2", number: "INV-002" });
    if (path === "/api/admin/invoices/inv-1/issue") return ok({ ...invoice, state: "issued" });
    if (path === "/api/admin/invoices/inv-1/credit-memos") return ok({ id: "cm-1", invoice_id: "inv-1", state: "issued", currency: "USD", minor_unit: 2, amount_minor: 500 });
    if (path === "/api/admin/payments/reconcile") return ok({ applied: false, matched: 3 });
    return ok({});
  });
});

afterEach(() => {
  cleanup();
});

describe("finance invoice operations", () => {
  it("loads the reconciliation ledger with real paginated data", async () => {
    renderWithProviders(<FinancePage />);
    expect(await screen.findByText("INV-001")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/finance/reconciliation", expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }));
  });

  it("looks up, issues, and reads evidence for an invoice", async () => {
    renderWithProviders(<FinancePage />);
    await screen.findByText("INV-001");
    fireEvent.change(screen.getByLabelText("Invoice ID"), { target: { value: "inv-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Load invoice" }));
    expect(await screen.findByText(/Payment pay-1/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Issue invoice" }));
    const issueDialog = await screen.findByRole("dialog", { name: "Issue invoice" });
    fireEvent.click(within(issueDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/invoices/inv-1/issue", undefined, expect.objectContaining({ idempotencyKey: expect.any(String) })));
    fireEvent.click(screen.getByRole("button", { name: "Load evidence" }));
    expect(await screen.findByText(/provider/)).toBeVisible();
  });

  it("creates an invoice through the invoice contract", async () => {
    renderWithProviders(<FinancePage />);
    await screen.findByText("INV-001");
    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "order-9" } });
    fireEvent.change(screen.getAllByLabelText("Payment ID")[0], { target: { value: "pay-9" } });
    fireEvent.click(screen.getByRole("button", { name: "Create invoice" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/invoices", { order_id: "order-9", payment_id: "pay-9" }, expect.objectContaining({ idempotencyKey: expect.any(String) })));
    expect(await screen.findByText("INV-002")).toBeVisible();
  });

  it("issues and looks up a credit memo with minor-unit validation", async () => {
    renderWithProviders(<FinancePage />);
    await screen.findByText("INV-001");
    const issue = screen.getByRole("button", { name: "Issue credit memo" });
    expect(issue).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Invoice ID"), { target: { value: "inv-1" } });
    fireEvent.change(screen.getByLabelText("Credit memo amount in minor units"), { target: { value: "500" } });
    fireEvent.click(screen.getByRole("button", { name: "Load invoice" }));
    await screen.findByText(/Payment pay-1/);
    fireEvent.click(screen.getByRole("button", { name: "Issue credit memo" }));
    const memoDialog = await screen.findByRole("dialog", { name: "Issue credit memo" });
    fireEvent.click(within(memoDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/invoices/inv-1/credit-memos", expect.objectContaining({ amount_minor: 500 }), expect.objectContaining({ idempotencyKey: expect.any(String) })));
    fireEvent.change(screen.getByLabelText("Credit memo ID"), { target: { value: "cm-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Load credit memo" }));
    expect(await screen.findByText(/Reason: partial/)).toBeVisible();
  });

  it("runs payment status and reconciliation through live endpoints", async () => {
    renderWithProviders(<FinancePage />);
    await screen.findByText("INV-001");
    const paymentInputs = screen.getAllByLabelText("Payment ID");
    fireEvent.change(paymentInputs[1], { target: { value: "pay-1" } });
    await waitFor(() => expect((paymentInputs[1] as HTMLInputElement).value).toBe("pay-1"));
    fireEvent.click(screen.getByRole("button", { name: "Load payment status" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(expect.stringContaining("/api/v2/payments/pay-1/status")));
    await waitFor(() => expect(document.body.textContent?.toLowerCase()).toMatch(/captured/), { timeout: 3000 });
    fireEvent.click(screen.getByRole("button", { name: "Dry-run reconcile" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/payments/reconcile", { apply: false }, expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });
});
