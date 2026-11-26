import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const hookMocks = vi.hoisted(() => ({ useOrders: vi.fn(), useDeleteOrder: vi.fn() }));
vi.mock("@/hooks/use-orders", () => hookMocks);

import { OrderTable } from "@/modules/orders/components/order-table";
import { I18nProvider } from "@/providers/i18n-provider";

const orders = [
  { id: 1, user_name: "Ada", total: 120, status: "paid", payment_status: "paid", created_at: "2026-11-20T10:00:00Z" },
  { id: 2, user_name: null, total: 0, status: "pending", payment_status: "failed", created_at: null },
];

function renderTable(overrides: Partial<Record<string, unknown>> = {}) {
  const onEdit = vi.fn();
  const onCreate = vi.fn();
  const onView = vi.fn();
  const onParamsChange = vi.fn();
  render(
    <I18nProvider>
      <OrderTable
        onEdit={onEdit}
        onCreate={onCreate}
        onView={onView}
        params={{ page: 1 }}
        onParamsChange={onParamsChange}
        {...overrides}
      />
    </I18nProvider>,
  );
  return { onEdit, onCreate, onView, onParamsChange };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(hookMocks.useOrders).mockReturnValue({
    orders,
    meta: { page: 1, per_page: 2, total: 6, last_page: 3 },
    isLoading: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  });
  hookMocks.useDeleteOrder.mockReturnValue({ mutate: vi.fn(), isPending: false });
});

afterEach(() => {
  cleanup();
});

describe("OrderTable", () => {
  it("renders order rows with customer, totals, statuses and actions", () => {
    const handlers = renderTable();
    expect(screen.getByText("#1")).toBeTruthy();
    expect(screen.getByText("Ada")).toBeTruthy();
    expect(screen.getByText("N/A")).toBeTruthy();
    expect(screen.getAllByText("paid").length).toBeGreaterThan(0);
    expect(screen.getAllByText("failed").length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole("button", { name: /View #1/ }));
    expect(handlers.onView).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    fireEvent.click(screen.getByRole("button", { name: /Edit #1/ }));
    expect(handlers.onEdit).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });

  it("moves to the next page through pagination", () => {
    const handlers = renderTable();
    fireEvent.click(screen.getByRole("button", { name: "Next page" }));
    expect(handlers.onParamsChange).toHaveBeenCalledWith({ page: 2 });
  });

  it("deletes an order through the confirmation dialog", () => {
    const mutate = vi.fn((_id, options?: { onSettled?: () => void }) => options?.onSettled?.());
    hookMocks.useDeleteOrder.mockReturnValue({ mutate, isPending: false });
    renderTable();
    fireEvent.click(screen.getByRole("button", { name: /Delete #1/ }));
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    expect(mutate).toHaveBeenCalledWith(1, expect.any(Object));
  });

  it("renders the empty state and invokes create", () => {
    hookMocks.useOrders.mockReturnValue({
      orders: [], meta: undefined, isLoading: false, isError: false, error: null, refetch: vi.fn(),
    });
    const handlers = renderTable();
    const create = screen.getByRole("button", { name: /Create|New|Add/i });
    fireEvent.click(create);
    expect(handlers.onCreate).toHaveBeenCalled();
  });

  it("renders the error state and retries", () => {
    const refetch = vi.fn();
    hookMocks.useOrders.mockReturnValue({
      orders: [], meta: undefined, isLoading: false, isError: true, error: new Error("Orders offline"), refetch,
    });
    renderTable();
    expect(screen.getByText("Orders offline")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: /Try again|Retry/i }));
    expect(refetch).toHaveBeenCalled();
  });

  it("shows the loading state while orders are fetched", () => {
    hookMocks.useOrders.mockReturnValue({
      orders: [], meta: undefined, isLoading: true, isError: false, error: null, refetch: vi.fn(),
    });
    const { container } = render(
      <I18nProvider>
        <OrderTable onEdit={vi.fn()} onCreate={vi.fn()} params={{}} onParamsChange={vi.fn()} />
      </I18nProvider>,
    );
    expect(container.querySelector("table")).toBeTruthy();
  });
});
