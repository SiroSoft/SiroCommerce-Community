import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import SubscriptionsPage from "@/app/(dashboard)/subscriptions/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/subscriptions" }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const summary = { active: 2, paused: 1, cancelled: 1, churn_rate: 0.3333, active_recurring_revenue: { USD: 4000 }, renewal_revenue: {}, payment_failure_queue: 1, privacy: { pii: false } };
const listRows = [{ id: "sub-1", plan_id: "plan-a", provider: "test", currency: "USD", amount_minor: 2000, interval: "month", interval_count: 1, state: "active", next_billing_at: "2026-11-01", latest_attempt_state: "failed", customer_ref: "abc123def456" }];
const detail = { id: "sub-1", plan_id: "plan-a", provider: "test", currency: "USD", amount_minor: 2000, interval: "month", interval_count: 1, state: "active", next_billing_at: "2026-11-01", latest_attempt_state: "failed", customer_ref: "abc123def456", renewals: [{ id: "att-1", state: "failed", currency: "USD", amount_minor: 2000, error_code: "payment_failed" }] };
const planRows = [{ id: "plan-1", name: "Monthly care", amount_minor: 1200, currency: "USD", interval: "monthly", interval_count: 1, trial_period: "weekly", trial_period_count: 7, state: "active", provider: "sandbox" }];

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: false });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/v2/admin/subscriptions/summary") return ok(summary);
    if (path === "/api/v2/admin/subscription-plans") return ok(planRows);
    if (path === "/api/v2/admin/subscriptions") return Promise.resolve({ data: { data: listRows, meta: { page: 1, per_page: 25, total: 1, last_page: 1 } }, status: 200, headers: new Headers() });
    if (path === "/api/v2/admin/subscriptions/sub-1") return ok(detail);
    return ok({});
  });
  apiMock.post.mockResolvedValue(ok({ attempt_id: "att-1", state: "pending" }));
  apiMock.delete.mockResolvedValue({ data: {}, status: 204, headers: new Headers() });
});

afterEach(() => {
  cleanup();
});

describe("subscriptions operations", () => {
  it("loads summary, paginated list, and detail context from live endpoints", async () => {
    renderWithProviders(<SubscriptionsPage />);
    expect(await screen.findByText("sub-1")).toBeVisible();
    expect(screen.getByText("33.3%")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/subscriptions", expect.objectContaining({ params: expect.objectContaining({ page: 1, per_page: 25 }) }));
    fireEvent.click(screen.getByRole("button", { name: "View context" }));
    expect(await screen.findByText(/Subscription context: sub-1/)).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/subscriptions/sub-1");
  });

  it("queues a safe retry with an idempotency key and confirms success", async () => {
    renderWithProviders(<SubscriptionsPage />);
    await screen.findByText("sub-1");
    fireEvent.click(screen.getByRole("button", { name: "View context" }));
    await screen.findByText(/Subscription context: sub-1/);
    fireEvent.click(screen.getByRole("button", { name: "Queue safe retry" }));
    const dialog = await screen.findByRole("dialog", { name: "Queue safe retry" });
    expect(within(dialog).getByText(/att-1/)).toBeVisible();
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Queue safe retry" })).toBeNull());
    expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Queue safe retry" }));
    const confirmDialog = await screen.findByRole("dialog", { name: "Queue safe retry" });
    fireEvent.click(within(confirmDialog).getByRole("button", { name: "Queue retry" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/subscriptions/att-1/retry", undefined, expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("renders loading, empty, and error states for the list", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/subscriptions/summary") return ok(summary);
      if (path === "/api/v2/admin/subscriptions") return new Promise(() => {});
      return ok({});
    });
    renderWithProviders(<SubscriptionsPage />);
    expect(await screen.findByText("Loading subscriptions...")).toBeVisible();
    cleanup();
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/subscriptions/summary") return ok(summary);
      if (path === "/api/v2/admin/subscriptions") return Promise.reject(new TypeError("network down"));
      return ok({});
    });
    renderWithProviders(<SubscriptionsPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
    cleanup();
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/subscriptions/summary") return ok(summary);
      if (path === "/api/v2/admin/subscriptions") return Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } }, status: 200, headers: new Headers() });
      return ok({});
    });
    renderWithProviders(<SubscriptionsPage />);
    expect(await screen.findByText(/no subscriptions in this scope/i)).toBeVisible();
  });

  it("shows the plans tab, creates a plan, and confirms deletion", async () => {
    renderWithProviders(<SubscriptionsPage />);
    fireEvent.click(screen.getByRole("tab", { name: "Plans" }));
    expect(await screen.findByText("Monthly care")).toBeVisible();
    expect(screen.getByText("7 weekly")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/subscription-plans", expect.objectContaining({ params: {} }));

    fireEvent.click(screen.getByRole("button", { name: "Create plan" }));
    const createDialog = await screen.findByRole("dialog", { name: "Create plan" });
    fireEvent.change(within(createDialog).getByLabelText("Plan name"), { target: { value: "Weekly box" } });
    fireEvent.change(within(createDialog).getByLabelText("Amount (minor units)"), { target: { value: "900" } });
    fireEvent.click(within(createDialog).getByRole("button", { name: "Create plan" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/subscription-plans", expect.objectContaining({ name: "Weekly box", amount_minor: 900, interval: "monthly", state: "active" }), expect.objectContaining({ idempotencyKey: expect.any(String) })));

    fireEvent.click(screen.getAllByRole("button", { name: "Delete" })[0]!);
    const deleteDialog = await screen.findByRole("dialog", { name: "Delete plan" });
    fireEvent.click(within(deleteDialog).getByRole("button", { name: "Delete plan" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/v2/admin/subscription-plans/plan-1", expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });
});
