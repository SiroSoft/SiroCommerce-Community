import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import CommercePage from "@/app/(dashboard)/commerce/page";
import OperationsPage from "@/app/(dashboard)/operations/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "admin" }, isLoading: false, isAuthenticated: true }) }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const coupon = { id: "c-1", code: "WELCOME10", rules: JSON.stringify({ type: "percent", value: 10, exclusive: false, usage_limit: 5 }), state: "active" };

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/v2/catalog/product-types") return body([{ id: "pt-1", name: "Physical" }]);
    if (path === "/api/v2/catalog/brands") return body([{ id: "b-1", name: "Acme" }]);
    if (path === "/api/v2/catalog/categories") return body([{ id: "cat-1", name: "Home" }]);
    if (path === "/api/v2/catalog/tags") return body([{ id: "tg-1", name: "New" }]);
    if (path === "/api/admin/coupons") return body([coupon]);
    if (path === "/api/admin/promotion-decisions/audit") return body([{ id: "d-1", code: "WELCOME10", decided_at: "2026-01-01" }]);
    if (path === "/api/admin/coupons/c-1/versions") return body([{ id: "v-1", version: 2 }]);
    if (path === "/api/admin/coupons/c-1/usage") return body({ uses: 3, orders: 2 });
    if (path === "/api/admin/coupons/pnl") return body([{ coupon_id: "c-1", code: "WELCOME10", uses: 3, orders: 2, revenue_minor: 500000, discount_minor: 50000, net_minor: 450000, currency: "VND" }]);
    if (path === "/api/admin/stores") return body([{ id: "st-1", name: "Main store", status: "active" }]);
    if (path === "/api/admin/stores/st-1/channels") return body([{ id: "ch-1", name: "Web" }]);
    if (path === "/api/admin/analytics/events") return body([{ id: "e-1", name: "order.created", created_at: "2026-01-01" }]);
    if (path === "/api/v2/admin/notification-events") return body([{ id: "n-1", event_name: "order.paid", state: "sent", created_at: "2026-01-01" }]);
    if (path === "/api/v2/admin/vendors") return body([{ id: "vendor-1", name: "Acme Supply", status: "active" }]);
    if (path === "/api/v2/admin/commissions") return body([{ id: "com-1", gross_minor: 5000, currency: "USD" }]);
    if (path === "/api/v2/admin/seller-orders") return body([{ id: "so-1", order_id: "o-1", state: "paid" }]);
    if (path === "/api/v2/admin/audit-logs") return body([{ id: "a-1", action: "product.updated", created_at: "2026-01-01", actor: "ada" }]);
    if (path === "/api/v2/admin/products/p-1/versions") return body([{ id: "pv-1", version: 4, status: "draft" }]);
    if (path === "/api/v2/admin/jobs/j-1") return body({ id: "j-1", state: "failed" });
    if (path === "/api/v2/admin/exports/exp-1") return body({ id: "exp-1", state: "completed" });
    return body({});
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/admin/coupons") return body({ id: "c-2", code: "NEW10" });
    if (path === "/api/admin/coupons/bulk") return body({ created: 3 });
    if (path === "/api/admin/coupons/c-1/disable") return body(null);
    if (path === "/api/admin/coupons/c-1/enable") return body(null);
    if (path === "/api/promotions/preview") return body({ discount_minor: 10000 });
    if (path === "/api/v2/admin/vendors") return body({ id: "vendor-2" });
    if (path === "/api/v2/admin/jobs") return body({ id: "j-2" });
    if (path === "/api/v2/admin/catalog/imports") return body({ id: "imp-1" });
    if (path === "/api/v2/admin/exports") return body({ id: "exp-1" });
    if (path === "/api/v2/admin/exports/exp-1/retry") return body({ id: "exp-1" });
    if (path === "/api/v2/admin/warehouses/reservations") return body({ id: "rsv-1" });
    return body({ id: "op-1" });
  });
  apiMock.put.mockImplementation((path: string) => {
    if (path === "/api/admin/products/p-1") return body({ id: "p-1", status: "published" });
    return body({ id: "put-1" });
  });
});

afterEach(() => {
  cleanup();
});

describe("commerce page", () => {
  it("renders catalog types, brands, categories and tags", async () => {
    renderWithProviders(<CommercePage />);
    expect(await screen.findByText("Physical")).toBeVisible();
    expect(await screen.findByText("Acme")).toBeVisible();
    expect(await screen.findByText("Home")).toBeVisible();
    expect(await screen.findByText("New")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/catalog/product-types");
  });

  it("updates lifecycle, approval, versions and page publishing", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Lifecycle" }));
    fireEvent.change(screen.getByLabelText("Product ID"), { target: { value: "p-1" } });
    fireEvent.change(screen.getByLabelText("Status"), { target: { value: "published" } });
    fireEvent.change(screen.getByLabelText(/Version/), { target: { value: "4" } });
    fireEvent.click(screen.getByRole("button", { name: "Update lifecycle" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/products/p-1",
      { status: "published", version: 4 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Save approval" })).not.toBeDisabled());
    fireEvent.change(screen.getByLabelText("Approval status"), { target: { value: "approved" } });
    fireEvent.click(screen.getByRole("button", { name: "Save approval" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/products/p-1/approval",
      { approval_status: "approved", version: 4 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Load versions" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Load versions" }));
    expect(await screen.findByText(/v4 · draft/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Page ID"), { target: { value: "pg-1" } });
    fireEvent.change(screen.getByLabelText("Schedule time (optional)"), { target: { value: "2026-09-01T10:00" } });
    await waitFor(() => expect(screen.getByRole("button", { name: "Schedule publish" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Schedule publish" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/pages/pg-1/publish",
      expect.objectContaining({ publish_at: expect.any(String) }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Accepted. The backend is authoritative/)).toBeVisible();
  });

  it("creates coupons with validation and previews server discounts", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Promotions" }));
    expect((await screen.findAllByText("WELCOME10")).length).toBeGreaterThan(0);
    fireEvent.change(screen.getByLabelText("Code *"), { target: { value: "new10" } });
    fireEvent.change(screen.getByLabelText("Value *"), { target: { value: "10" } });
    fireEvent.change(screen.getByLabelText("Min spend (minor)"), { target: { value: "200000" } });
    fireEvent.change(screen.getByLabelText("Max discount (minor)"), { target: { value: "50000" } });
    fireEvent.change(screen.getByLabelText("Usage limit (global)"), { target: { value: "100" } });
    fireEvent.change(screen.getByLabelText("Per-customer limit"), { target: { value: "1" } });
    fireEvent.click(screen.getByLabelText("Exclusive (no stacking)"));
    fireEvent.click(screen.getByRole("button", { name: "Create coupon" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/coupons",
      expect.objectContaining({ code: "NEW10", rules: expect.stringContaining("\"value\":10") }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Code *"), { target: { value: "new10" } });
    await waitFor(() => expect(screen.getByRole("button", { name: "Preview server discount" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Preview server discount" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/promotions/preview", { code: "NEW10", subtotal_minor: 100000 }));
    expect((await screen.findAllByText(/discount_minor/)).length).toBeGreaterThan(0);
  });

  it("builds buy-x-get-y rules and rejects invalid percent values", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Promotions" }));
    await screen.findAllByText("WELCOME10");
    fireEvent.change(screen.getByLabelText("Code *"), { target: { value: "COMBO" } });
    fireEvent.change(screen.getByLabelText("Value *"), { target: { value: "150" } });
    fireEvent.click(screen.getByRole("button", { name: "Create coupon" }));
    await waitFor(() => expect(apiMock.post).not.toHaveBeenCalledWith("/api/admin/coupons", expect.anything(), expect.anything()));
    fireEvent.change(screen.getByLabelText("Type"), { target: { value: "buy_x_get_y" } });
    fireEvent.change(screen.getByLabelText("Buy variant IDs (comma-separated) *"), { target: { value: "v1, v2" } });
    fireEvent.change(screen.getByLabelText("Gift variant IDs (comma-separated) *"), { target: { value: "v3" } });
    fireEvent.change(screen.getByLabelText("Max sets (0 = unlimited)"), { target: { value: "2" } });
    fireEvent.click(screen.getByRole("button", { name: "Create coupon" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/coupons",
      expect.objectContaining({ code: "COMBO", rules: expect.stringContaining("buy_variant_ids") }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("loads coupon versions, usage, P&L and disables a coupon", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Promotions" }));
    await screen.findAllByText("WELCOME10");
    fireEvent.click(screen.getByRole("button", { name: "Versions" }));
    expect(await screen.findByText(/Coupon detail -- c-1/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Usage" }));
    expect(await screen.findByText(/Usage analytics/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Disable" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/coupons/c-1/disable",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: /Load P&L/ }));
    expect(await screen.findByText(/Revenue/)).toBeVisible();
    expect(screen.getByText(/Revenue/)).toBeVisible();
  });

  it("creates bulk coupons and shows the result", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Promotions" }));
    await screen.findAllByText("WELCOME10");
    fireEvent.change(screen.getByLabelText("Pattern"), { target: { value: "WELCOME{0000}" } });
    fireEvent.change(screen.getByLabelText("Count (1-1000)"), { target: { value: "3" } });
    fireEvent.click(screen.getByRole("button", { name: /Create bulk/ }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/coupons/bulk",
      expect.objectContaining({ pattern: "WELCOME{0000}", count: 3 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/"created": 3/)).toBeVisible();
  });

  it("loads sites, events, vendors and audit data", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Sites" }));
    expect(await screen.findByText("Main store")).toBeVisible();
    expect(await screen.findByText("Web")).toBeVisible();
    fireEvent.click(screen.getByRole("tab", { name: "Events" }));
    expect(await screen.findByText("order.created")).toBeVisible();
    expect(await screen.findByText("order.paid")).toBeVisible();
    fireEvent.click(screen.getByRole("tab", { name: "Vendors" }));
    expect(await screen.findByText("Acme Supply")).toBeVisible();
    expect(await screen.findByText("com-1")).toBeVisible();
    expect(await screen.findByText("o-1")).toBeVisible();
    fireEvent.click(screen.getByRole("tab", { name: "Audit" }));
    expect(await screen.findByText("product.updated")).toBeVisible();
  });

  it("onboards a vendor after confirmation", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Vendors" }));
    await screen.findByText("Acme Supply");
    fireEvent.change(screen.getByLabelText("Code"), { target: { value: "acme-2" } });
    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "Acme Two" } });
    fireEvent.click(screen.getByRole("button", { name: "Onboard" }));
    const dialog = await screen.findByRole("dialog", { name: "Onboard vendor" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/vendors",
      { code: "acme-2", name: "Acme Two" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("enqueues a job, refreshes it and applies an action", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Jobs" }));
    fireEvent.change(document.querySelector("#job-payload") as HTMLTextAreaElement, { target: { value: "{\"order\":1}" } });
    fireEvent.click(screen.getByRole("button", { name: "Enqueue" }));
    const enqueue = await screen.findByRole("dialog", { name: "Enqueue job" });
    fireEvent.click(within(enqueue).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/jobs",
      expect.objectContaining({ name: "export", payload: { order: 1 } }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Enqueue job accepted.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Job ID"), { target: { value: "j-1" } });
    const refresh = screen.getByRole("button", { name: "Refresh" });
    await waitFor(() => expect(refresh).not.toBeDisabled());
    fireEvent.click(refresh);
    const refreshDialog = await screen.findByRole("dialog", { name: "Refresh job" });
    fireEvent.click(within(refreshDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(document.body.textContent).toContain("\"state\": \"failed\""));
    fireEvent.click(screen.getByRole("button", { name: "Apply action" }));
    const action = await screen.findByRole("dialog", { name: "cancel job" });
    fireEvent.click(within(action).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/jobs/j-1/cancel",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("runs import, export and reservation operations", async () => {
    renderWithProviders(<CommercePage />);
    await screen.findByText("Physical");
    fireEvent.click(screen.getByRole("tab", { name: "Operations" }));
    fireEvent.click(screen.getByRole("button", { name: "Validate CSV import" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/catalog/imports",
      { format: "csv", source: "admin-upload", validate_only: true },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Start export" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Start export" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/exports",
      { dataset: "products", format: "csv" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Operation ID"), { target: { value: "exp-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Refresh status" }));
    expect(await screen.findByText(/"state": "completed"/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Variant"), { target: { value: "v-1" } });
    fireEvent.change(screen.getByLabelText("Cart ID"), { target: { value: "cart-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Reserve" }));
    const reserve = await screen.findByRole("dialog", { name: "Create reservation" });
    fireEvent.click(within(reserve).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/warehouses/reservations",
      { variant_id: "v-1", qty: 1, cart_id: "cart-1" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Release" }));
    const release = await screen.findByRole("dialog", { name: "Release reservation" });
    fireEvent.click(within(release).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/warehouses/reservations/rsv-1/release",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("From source"), { target: { value: "main" } });
    fireEvent.change(screen.getByLabelText("To source"), { target: { value: "store" } });
    fireEvent.click(screen.getByRole("button", { name: "Transfer" }));
    const transfer = await screen.findByRole("dialog", { name: "Transfer inventory" });
    fireEvent.click(within(transfer).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/warehouses/transfers",
      { from_source_id: "main", to_source_id: "store", qty: 1, variant_id: "v-1" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});

describe("operations page", () => {
  const operationRows = [
    { id: "job-1", kind: "job", label: "Export job", state: "failed", attempts: 2, last_error: "timeout", order_id: null, created_at: "2026-01-01" },
    { id: "notif-1", kind: "notification", label: "Payment email", state: "sending", attempts: 1, last_error: null, order_id: "o-1", created_at: "2026-01-01" },
    { id: "pay-1", kind: "payment", label: "Card capture", state: "succeeded", attempts: 1, last_error: null, order_id: "o-2", created_at: "2026-01-01" },
  ];

  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/operations") return Promise.resolve({ data: { data: operationRows, meta: { page: 1, per_page: 25, total: 3, last_page: 1 } }, status: 200, headers: new Headers() });
      if (path === "/api/admin/ops-alerts") return body([{ kind: "refund_failures", state: "open", window_hours: 24, observed: 8, baseline_x100: 100, ratio_x100: 800, last_seen_at: "2026-01-01" }]);
      if (path === "/api/admin/ops/scheduled/run") return body({ expired_promotions: 2 });
      return body({});
    });
    apiMock.post.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/operations/job-1/retry") return body({ id: "job-1", state: "queued" });
      if (path === "/api/v2/admin/operations/notif-1/replay-notification") return body({ id: "notif-1", state: "queued" });
      if (path === "/api/admin/payments/reconcile") return body({ inspected: 3, resolved: 2, unknown: 0 });
      if (path === "/api/admin/ops/scheduled/run?apply=true") return body({ expired_promotions: 2 });
      if (path === "/api/admin/ops-alerts/refund_failures/ack") return body({ ok: true });
      return body({});
    });
  });

  it("lists operations and retries a failed job after confirmation", async () => {
    renderWithProviders(<OperationsPage />);
    expect(await screen.findByText("Export job")).toBeVisible();
    expect(screen.getByText("timeout")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: /Retry/ }));
    const dialog = await screen.findByRole("dialog", { name: "Retry operation job" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/operations/job-1/retry",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Retry queued.")).toBeVisible();
  });

  it("replays notifications and runs payment reconciliation", async () => {
    renderWithProviders(<OperationsPage />);
    await screen.findByText("Payment email");
    fireEvent.click(screen.getByRole("button", { name: /Replay/ }));
    const replay = await screen.findByRole("dialog", { name: "Replay notification" });
    fireEvent.click(within(replay).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/operations/notif-1/replay-notification",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Inspect payments" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/payments/reconcile",
      { apply: false },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Inspection complete: 3 inspected/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Apply fixes" }));
    const apply = await screen.findByRole("dialog", { name: "Apply reconciliation fixes" });
    fireEvent.click(within(apply).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/payments/reconcile",
      { apply: true },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Reconciliation applied: 2 resolved/)).toBeVisible();
  });

  it("dry-runs and applies scheduled maintenance", async () => {
    renderWithProviders(<OperationsPage />);
    await screen.findByText("Export job");
    fireEvent.click(screen.getByRole("button", { name: "Dry-run" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/ops/scheduled/run"));
    await waitFor(() => expect(screen.getByRole("button", { name: "Apply" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Apply" }));
    const apply = await screen.findByRole("dialog", { name: "Apply scheduled maintenance" });
    fireEvent.click(within(apply).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/ops/scheduled/run?apply=true", undefined));
    expect(await screen.findByText("Scheduled maintenance applied")).toBeVisible();
  });

  it("loads anomaly alerts and acknowledges them", async () => {
    renderWithProviders(<OperationsPage />);
    await screen.findByText("Export job");
    fireEvent.click(screen.getByRole("button", { name: "Load alerts" }));
    expect(await screen.findByText(/refund failures/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Acknowledge" }));
    const dialog = await screen.findByRole("dialog", { name: "Acknowledge anomaly alert" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/ops-alerts/refund_failures/ack", {}));
  });

  it("shows empty and retryable error states", async () => {
    apiMock.get.mockResolvedValueOnce(Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } }, status: 200, headers: new Headers() }));
    const { unmount } = renderWithProviders(<OperationsPage />);
    expect(await screen.findByText("No operations found")).toBeVisible();
    unmount();
    apiMock.get.mockRejectedValueOnce(Object.assign(new Error("down"), { status: 422 }));
    renderWithProviders(<OperationsPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
  });
});
