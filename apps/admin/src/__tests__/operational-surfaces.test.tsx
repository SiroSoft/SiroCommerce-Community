import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import ReportingPage from "@/app/(dashboard)/reporting/page";
import { mapProductToApi } from "@/lib/api-mapping";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

const insightsInner = {
  authoritative: { orders: 3, sales_minor_by_currency: { USD: 5000 }, refunds_minor_by_currency: {} },
  funnel_estimate: { counts: { product_view: 10 }, conversion: {} },
  product_performance: { order_lines: [], engagement_signals: [] },
  retention_estimate: { identified_customers: 2, repeat_customers: 1, repeat_rate: 0.5 },
  engagement_estimate: { wishlist_add: 4 },
  recommendation_signals: { status: "unavailable" },
};

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/v2/admin/reports/advanced") return ok({ schema_version: 1, dimension: "insights", data: insightsInner });
    if (path === "/api/v2/admin/reports/failures") return Promise.resolve({ data: { data: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 } }, status: 200, headers: new Headers() });
    if (path === "/api/v2/admin/reports/contract") return ok({ schema_version: 1, dimensions: ["insights"], export_datasets: ["orders"], max_window_days: 366 });
    return ok({});
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/v2/admin/exports") return ok({ id: "exp-1", dataset: "orders", format: "csv", state: "queued", rows_written: 0 });
    return ok({});
  });
});

afterEach(() => {
  cleanup();
});

describe("operational surfaces", () => {
  it("loads live insights, failures, and contract without fixture data", async () => {
    render(<I18nProvider><ReportingPage /></I18nProvider>);
    expect(await screen.findByText("3")).toBeVisible();
    expect(await screen.findByText(/no failed or dead jobs/i)).toBeVisible();
    expect(await screen.findByText(/export datasets/i)).toBeVisible();
    expect(screen.queryByText(/fixture preview only/i)).not.toBeInTheDocument();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/advanced", expect.objectContaining({ params: expect.objectContaining({ dimension: "insights" }) }));
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/failures", expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }));
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/contract");
  });

  it("starts a live export job through the exports contract", async () => {
    render(<I18nProvider><ReportingPage /></I18nProvider>);
    const start = await screen.findByRole("button", { name: /start export/i });
    expect(start).toBeEnabled();
    fireEvent.click(start);
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/exports",
      expect.objectContaining({ dataset: "orders", format: "csv" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/queued for orders/i)).toBeVisible();
  });

  it("does not send response-only product cover images in write payloads", () => {
    expect(mapProductToApi({ name: "Lamp", price: 12, cover_image: "https://invalid.test/a.jpg" })).toEqual({ name: "Lamp", price: 12 });
  });
});
