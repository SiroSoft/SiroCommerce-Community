import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { BusinessPulse } from "@/modules/dashboard/business-pulse";
import { formatMinor } from "@/lib/utils";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({
  default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
}));

const ok = (data: unknown) => Promise.resolve({ data: { success: true, data }, status: 200, headers: new Headers() });

function payload(range = "7d") {
  return {
    range,
    window: { from: "2026-09-14 00:00:00", to: "2026-09-20 23:59:59" },
    sales: {
      currency: "VND",
      gross_minor: 123400,
      orders: 2,
      aov_minor: 61700,
      refunds_minor: 1000,
      by_day: [
        { date: "2026-09-19", gross_minor: 23400, orders: 1 },
        { date: "2026-09-20", gross_minor: 100000, orders: 1 },
      ],
    },
    funnel: { sessions: 10, product_views: 6, add_to_cart: 3, checkout_started: 2, orders: 1 },
    top_products: [{ product_id: "p1", name: "Alpha", slug: "alpha", qty: 2, revenue_minor: 200 }],
    low_stock: [{ variant_id: "v1", name: "Alpha small", sku: "SKU-A", available: 2 }],
    ops: { failed_payments: 1, pending_refunds: 2, queue_dead_letters: 3, open_ops_alerts: 4 },
    generated_at: "2026-09-20T12:00:00Z",
  };
}

function renderPulse() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><BusinessPulse /></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  apiMock.get.mockImplementation((path: string, config?: { params?: { range?: string } }) => {
    if (path === "/api/v2/admin/dashboard/bi") return ok(payload(config?.params?.range ?? "7d"));
    return ok({});
  });
});

afterEach(() => {
  cleanup();
});

describe("dashboard business pulse", () => {
  it("renders mocked sales, funnel, products, low stock and operations", async () => {
    renderPulse();

    expect(await screen.findByText(formatMinor(123400, "VND"))).toBeVisible();
    expect(screen.getByText(formatMinor(61700, "VND"))).toBeVisible();
    expect(screen.getByText("Alpha")).toBeVisible();
    expect(screen.getByText("Alpha small")).toBeVisible();
    expect(screen.getByText("Dead letters")).toBeVisible();
    expect(screen.getByText("Open ops alerts")).toBeVisible();
    expect(screen.getAllByText("50%").length).toBeGreaterThan(0);

    const links = screen.getAllByRole("link");
    expect(links.some((link) => link.getAttribute("href") === "/products/p1")).toBe(true);
    expect(links.some((link) => link.getAttribute("href") === "/inventory?variant=v1")).toBe(true);
    expect(links.filter((link) => link.getAttribute("href") === "/operations")).toHaveLength(4);
  });

  it("re-queries the API when the range changes", async () => {
    renderPulse();
    await screen.findByText(formatMinor(123400, "VND"));

    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/dashboard/bi", { params: { range: "7d" } });

    fireEvent.click(screen.getByRole("button", { name: "Last 30 days" }));

    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/dashboard/bi", { params: { range: "30d" } }));
  });

  it("falls back to an error state instead of crashing", async () => {
    apiMock.get.mockImplementation(() => Promise.reject(Object.assign(new Error("dashboard failed"), { status: 422 })));
    renderPulse();

    expect(await screen.findByText("Business pulse unavailable")).toBeVisible();
    expect(screen.getByText(/could not be loaded/i)).toBeVisible();
  });

  it("shows empty states with zero data", async () => {
    apiMock.get.mockImplementation(() => ok({
      ...payload(),
      sales: { currency: "VND", gross_minor: 0, orders: 0, aov_minor: 0, refunds_minor: 0, by_day: [] },
      top_products: [],
      low_stock: [],
    }));
    renderPulse();

    expect(await screen.findByText("No sales in this range")).toBeVisible();
    expect(screen.getByText("No product sales in this range.")).toBeVisible();
    expect(screen.getByText("No variants at or below 5 units.")).toBeVisible();
  });
});
