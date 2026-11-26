import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import DashboardPage from "@/app/(dashboard)/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { name: "Ada Admin", role: "admin" }, isLoading: false, isAuthenticated: true }) }));
vi.mock("@/modules/dashboard/business-pulse", () => ({ BusinessPulse: () => <div>Business pulse</div> }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const stats = {
  total_users: 1200,
  active_users: 900,
  total_orders: 345,
  total_products: 67,
  total_revenue: 1250000,
  recent_activity: [
    { id: 1, action: "order.created", description: "Order #345 created", user: "ada", created_at: "2026-06-01T10:00:00Z" },
  ],
  api_status: { status: "up", version: "2.4.0", uptime: 7200, response_time: 42 },
  monthly_revenue: [{ month: "Jan", revenue: 100000 }, { month: "Feb", revenue: 200000 }],
  orders_by_status: { pending: 3, paid: 8 },
  scope: { store_id: "s-1", channel_id: "c-1" },
  freshness: { generated_at: "2026-06-01T10:00:00Z", max_age_seconds: 3600, status: "stale" as const },
  ranges: {
    today: { orders: 2, completed_orders: 1, revenue_minor: 500000, refunds_minor: 0, net_minor: 500000, aov_minor: 250000 },
    week: { orders: 12, completed_orders: 10, revenue_minor: 4200000, refunds_minor: 200000, net_minor: 4000000, aov_minor: 350000 },
  },
};

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/dashboard/stats") return body(stats);
    if (path === "/api/v2/admin/audit-logs") return body([{ id: "ev-1", action: "product.updated", actor: "ada", store_id: "s-1", channel_id: "c-1", created_at: "2026-06-01T09:00:00Z" }]);
    return body({});
  });
});

afterEach(() => {
  cleanup();
});

describe("dashboard home", () => {
  it("renders stats, ranges, recent activity, status, api health, chart and audit", async () => {
    renderWithProviders(<DashboardPage />);
    expect(await screen.findByText(/Ada Admin/)).toBeVisible();
    expect(screen.getByText("1,200")).toBeVisible();
    expect(screen.getByText("345")).toBeVisible();
    expect(screen.getByText("Today")).toBeVisible();
    expect(screen.getByText("Last 7 days")).toBeVisible();
    expect(screen.getByText("order.created")).toBeVisible();
    expect(screen.getByText("pending")).toBeVisible();
    expect(screen.getByText("2.4.0")).toBeVisible();
    expect(screen.getByText("42ms")).toBeVisible();
    expect(screen.getByText("Business pulse")).toBeVisible();
    expect(screen.getByText(/Dashboard data is older than expected/)).toBeVisible();
    expect(await screen.findByText("product.updated")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/dashboard/stats");
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/audit-logs", { params: { limit: 8, offset: 0 } });
  });

  it("refreshes data and customizes visible widgets", async () => {
    renderWithProviders(<DashboardPage />);
    await screen.findByText(/Ada Admin/);
    fireEvent.click(screen.getByRole("button", { name: "Customize" }));
    expect(screen.getByText("Dashboard widgets")).toBeVisible();
    const revenueToggle = screen.getByRole("checkbox", { name: "Revenue chart" });
    fireEvent.click(revenueToggle);
    await waitFor(() => expect(screen.queryByText("Monthly Revenue")).not.toBeInTheDocument());
    fireEvent.click(screen.getByRole("button", { name: /Refresh/ }));
    await waitFor(() => expect(apiMock.get.mock.calls.filter(([path]) => path === "/api/dashboard/stats").length).toBeGreaterThan(1));
    expect(screen.getByText("Last Checked")).toBeVisible();
  });

  it("shows the empty database warning for a fresh install", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/dashboard/stats") return body({ ...stats, total_users: 0, total_orders: 0, total_products: 0, ranges: undefined, recent_activity: [], orders_by_status: {}, monthly_revenue: [] });
      if (path === "/api/v2/admin/audit-logs") return body([]);
      return body({});
    });
    renderWithProviders(<DashboardPage />);
    expect(await screen.findByText(/No data yet|empty/i)).toBeVisible();
    expect(await screen.findByText("No recent audit activity.")).toBeVisible();
  });

  it("shows a retryable error state and audit failure fallback", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/dashboard/stats") return Promise.reject(Object.assign(new Error("down"), { status: 422 }));
      if (path === "/api/v2/admin/audit-logs") return Promise.reject(new Error("audit down"));
      return body({});
    });
    renderWithProviders(<DashboardPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(screen.getByRole("button", { name: /Try again/ })).toBeVisible();
  });
});
