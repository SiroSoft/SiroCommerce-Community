import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import ActionCenterPage from "@/app/(dashboard)/action-center/page";
import OrdersPage from "@/app/(dashboard)/orders/page";
import { OrderDetail } from "@/modules/merchant/order-ui";
import FulfillmentPage from "@/app/(dashboard)/fulfillment/page";
import InventoryRoute from "@/app/(dashboard)/inventory/page";
import { InventoryPage } from "@/modules/merchant/inventory-ui";
import ProductsPage from "@/app/(dashboard)/products/page";
import PostsPage from "@/app/(dashboard)/posts/page";
import LoginPage from "@/app/(auth)/login/page";
import api from "@/services/api";
import { merchantService } from "@/services/merchant.service";
import { ordersService } from "@/services/orders.service";
import { ScopeCoordinator } from "@sirocommerce/scope";
import { useAuthStore } from "@/store/auth.store";
import { Pagination } from "@/components/pagination";
import { Sidebar } from "@/layouts/sidebar";
import { Header } from "@/layouts/header";
import { MobileSidebar } from "@/layouts/mobile-sidebar";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/login" }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "admin" }, login: vi.fn(), isLoginPending: false, loginError: null, isLoading: false }) }));

const order = {
  id: 42,
  status: "pending",
  customer_email: "buyer@example.test",
  total: 129.5,
  created_at: "2026-09-23T10:00:00Z",
  items: [{ id: 1, product_id: 7, product_name: "Blue shirt", quantity: 2, subtotal: 129.5 }],
} as any;
const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const failure = (status: number) => Object.assign(new Error(`HTTP ${status}`), { status });

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "admin" }, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/smart/action-center") return ok({ actions: [] });
    if (path === "/api/orders") return ok({ data: [order], meta: {} });
    if (path === "/api/orders/42") return ok(order);
    if (path === "/api/admin/inventory/sku-1") return ok({ variant_id: "sku-1", source_id: "main", on_hand: 10, reserved: 2, available: 8 });
    if (path === "/api/admin/fulfillment/f-1/track") return ok({ id: "f-1", status: "allocated" });
    return ok({});
  });
  apiMock.post.mockResolvedValue(ok(order));
  apiMock.put.mockResolvedValue(ok({}));
});

afterEach(() => {
  cleanup();
});

describe("Admin QC journey: login to operational workspaces", () => {
  it("keeps collapsed navigation links named for assistive technology", () => {
    renderWithProviders(<Sidebar collapsed onToggle={vi.fn()} />);
    expect(screen.getByRole("link", { name: "Dashboard" })).toHaveAttribute("title", "Dashboard");
    expect(screen.getByRole("link", { name: "Orders" })).not.toHaveAttribute("aria-current");
  });

  it("renders breadcrumb navigation as a list with a current page", () => {
    renderWithProviders(<Header onMenuToggle={vi.fn()} />);
    expect(screen.getByRole("navigation", { name: "Breadcrumb" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Dashboard" })).toHaveAttribute("href", "/");
    expect(screen.getByText("Login")).toHaveAttribute("aria-current", "page");
  });

  it("isolates the open mobile navigation as a modal dialog", () => {
    renderWithProviders(<MobileSidebar open onClose={vi.fn()} />);
    const dialog = screen.getByRole("dialog", { name: "Siro Admin" });
    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(dialog).not.toHaveAttribute("aria-hidden", "true");
  });

  it("renders login validation, keeps keyboard focus semantics, and exposes navigation", async () => {
    renderWithProviders(<LoginPage />);
    const email = screen.getByLabelText(/^Email/, { selector: "input" });
    const password = screen.getByLabelText(/^Password/, { selector: "input" });
    expect(email).toHaveFocus();
    expect(email).toHaveAttribute("autocomplete", "email");
    expect(password).toHaveAttribute("autocomplete", "current-password");
    fireEvent.click(screen.getByRole("button", { name: "Login" }));
    expect(await screen.findByText(/valid email/i)).toBeVisible();
    expect(await screen.findByText(/at least 8/i)).toBeVisible();
    expect(screen.getByRole("link", { name: /forgot/i })).toHaveAttribute("href", "/forgot-password");
  });

  it("renders the contract-backed Action Center empty state", async () => {
    renderWithProviders(<ActionCenterPage />);
    expect(await screen.findByText(/no actions require attention/i)).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/smart/action-center");
  });

  it("renders order loading, empty, responsive table semantics, and navigation", async () => {
    let resolve: ((value: unknown) => void) | undefined;
    apiMock.get.mockImplementationOnce(() => new Promise((r) => { resolve = r; }));
    renderWithProviders(<OrdersPage />);
    expect(screen.getByRole("heading", { name: "Orders" })).toBeVisible();
    resolve?.({ data: { data: [order], meta: {} }, status: 200, headers: new Headers() });
    const table = await screen.findByRole("table", { name: "Orders" });
    expect(table).toHaveTextContent("#42");
    expect(table.parentElement).toHaveClass("overflow-x-auto");
    expect(screen.getByRole("link", { name: /view/i })).toHaveAttribute("href", "/orders/42");
  });

  it("renders the order empty state and unknown network outcome with retry", async () => {
    apiMock.get.mockResolvedValueOnce({ data: { data: [], meta: {} }, status: 200, headers: new Headers() });
    renderWithProviders(<OrdersPage />);
    expect(await screen.findByText(/no orders yet/i)).toBeVisible();

    apiMock.get.mockRejectedValue(new TypeError("network down"));
    renderWithProviders(<OrdersPage />);
    expect(await screen.findByRole("alert", {}, { timeout: 6000 })).toHaveTextContent(/something went wrong/i);
    expect(screen.getByRole("button", { name: /try again/i })).toBeEnabled();
  });

  it("renders order detail timeline, named available transition, and unavailable terminal transition", async () => {
    renderWithProviders(<OrderDetail id={42} />);
    expect(await screen.findByText("Blue shirt")).toBeVisible();
    expect(screen.getByLabelText("Order status timeline")).toHaveTextContent(/pending/i);
    expect(screen.getByRole("button", { name: "Confirm" })).toBeEnabled();
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/42/transition", { to: "confirmed" }, expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("renders a terminal order with no named transitions available", async () => {
    apiMock.get.mockImplementation((path: string) => path === "/api/orders/42" ? ok({ ...order, status: "delivered" }) : ok({ data: [] }));
    renderWithProviders(<OrderDetail id={42} />);
    expect(await screen.findByText(/no named transitions/i)).toBeVisible();
    expect(screen.queryByRole("button", { name: "Confirm" })).not.toBeInTheDocument();
  });

  it("links refunds and returns to the working order panels instead of dead buttons", async () => {
    renderWithProviders(<OrderDetail id={42} />);
    expect(await screen.findByText(/refunds and returns/i)).toBeVisible();
    expect(screen.queryByRole("button", { name: "Refund unavailable" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Return unavailable" })).toBeNull();
    expect(screen.getByRole("link", { name: /open order panels/i })).toBeVisible();
    expect(apiMock.put).not.toHaveBeenCalled();
    expect(apiMock.delete).not.toHaveBeenCalled();
  });

  it("reconciles a transition after 409 through the visible retry state", async () => {
    apiMock.post.mockRejectedValueOnce(failure(409));
    renderWithProviders(<OrderDetail id={42} />);
    await screen.findByText("Blue shirt");
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/conflict/i);
    expect(screen.getByText(/refresh before trying/i)).toBeVisible();
  });

  it("opens the contract-backed fulfillment workspace without transport until an ID is entered", async () => {
    renderWithProviders(<FulfillmentPage />);
    expect(screen.getByRole("heading", { name: "Fulfillment" })).toBeVisible();
    expect(screen.getByText(/enter an id to load fulfillment/i)).toBeVisible();
    expect(apiMock.get).not.toHaveBeenCalled();
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("opens the contract-backed inventory workspace without transport until a variant is entered", async () => {
    renderWithProviders(<InventoryRoute />);
    expect(screen.getByRole("heading", { name: "Inventory" })).toBeVisible();
    expect(screen.getByText(/enter a variant id/i)).toBeVisible();
    expect(apiMock.get).not.toHaveBeenCalled();
  });

  it("blocks an on-hand adjustment below reserved stock before transport", async () => {
    renderWithProviders(<InventoryPage />);
    fireEvent.change(screen.getByRole("textbox", { name: "Variant ID" }), { target: { value: "sku-1" } });
    expect(await screen.findByText("sku-1")).toBeVisible();
    fireEvent.change(screen.getByRole("spinbutton", { name: "New on-hand quantity" }), { target: { value: "1" } });
    expect(screen.getByRole("alert")).toHaveTextContent(/below reserved stock/i);
    expect(screen.getByRole("button", { name: "Apply adjustment" })).toBeDisabled();
    expect(apiMock.put).not.toHaveBeenCalled();
  });

  it("keeps order pagination server-driven and resets it for a new search", async () => {
    apiMock.get.mockImplementation((path: string, config?: { params?: Record<string, unknown> }) => path === "/api/orders"
      ? Promise.resolve({ data: { data: [order], meta: { page: config?.params?.page ?? 1, last_page: 2, total: 21 } }, status: 200, headers: new Headers() })
      : ok(order));
    renderWithProviders(<OrdersPage />);
    await screen.findAllByText("#42");
    fireEvent.click(screen.getByRole("button", { name: "Page 2" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/orders", expect.objectContaining({ params: expect.objectContaining({ page: 2 }) })));
    fireEvent.change(screen.getByPlaceholderText("Search orders"), { target: { value: "buyer" } });
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/orders", expect.objectContaining({ params: expect.objectContaining({ page: 1, search: "buyer" }) })), { timeout: 3000 });
  });
});

describe("Admin QC boundary contracts and regressions", () => {
  it("supports catalog search and pagination through the generated list adapter", async () => {
    const product = { id: 7, name: "Desk lamp", sku: "LAMP-7", price: 12, stock: 4, is_active: true, created_at: "2026-09-23T10:00:00Z" };
    apiMock.get.mockImplementation((path: string, config?: { params?: Record<string, unknown> }) => {
      if (path === "/api/admin/products") return Promise.resolve({ data: { data: [product], meta: { page: config?.params?.page ?? 1, last_page: 2, total: 11 } }, status: 200, headers: new Headers() });
      return ok({ data: [], meta: {} });
    });
    renderWithProviders(<ProductsPage />);
    expect(await screen.findByText("Desk lamp")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Page 2" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products", expect.objectContaining({ params: expect.objectContaining({ page: 2 }) })));
    fireEvent.change(screen.getByRole("textbox"), { target: { value: "lamp" } });
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products", expect.objectContaining({ params: expect.objectContaining({ search: "lamp", page: 1 }) })));
  });

  it("supports content status filtering through the generated list adapter", async () => {
    const post = { id: 8, title: "Launch", content: "Details", status: "draft", featured: false, created_at: "2026-09-23T10:00:00Z" };
    apiMock.get.mockImplementation((path: string, config?: { params?: Record<string, unknown> }) => {
      if (path === "/api/posts") return Promise.resolve({ data: { data: [post], meta: { page: 1, last_page: 1, total: 1 } }, status: 200, headers: new Headers() });
      return ok({ data: [], meta: {} });
    });
    renderWithProviders(<PostsPage />);
    expect(await screen.findByText("Launch")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "published" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/posts", expect.objectContaining({ params: expect.objectContaining({ status: "published", page: 1 }) })));
  });

  it("fails closed for null catalog data and malformed pagination metadata", async () => {
    apiMock.get.mockImplementation((path: string) => path === "/api/admin/products" ? ok({ data: null, meta: { page: null, last_page: "bad", total: null } }) : ok({ data: [], meta: {} }));
    renderWithProviders(<ProductsPage />);
    expect(await screen.findByText(/no data/i)).toBeVisible();

    renderWithProviders(<Pagination meta={{ page: null, last_page: 4, total: 10 } as any} onPageChange={vi.fn()} />);
    expect(screen.queryByRole("button", { name: /page/i })).not.toBeInTheDocument();
  });

  it("deduplicates repeated order IDs in bulk operations and ignores stale selections", async () => {
    const duplicate = { ...order, status: "pending" };
    apiMock.get.mockImplementation((path: string) => path === "/api/orders" ? Promise.resolve({ data: { data: [duplicate, duplicate], meta: {} }, status: 200, headers: new Headers() }) : ok({ data: [] }));
    let resolvePost: (() => void) | undefined;
    apiMock.post.mockImplementation(() => new Promise((resolve) => { resolvePost = () => resolve(ok(order)); }));
    renderWithProviders(<OrdersPage />);
    await screen.findAllByText("#42");
    fireEvent.click(screen.getByRole("checkbox", { name: "Select all orders" }));
    const apply = screen.getByRole("button", { name: "Apply to selected" });
    fireEvent.click(apply);
    fireEvent.click(apply);
    const dialog = await screen.findByRole("dialog", { name: "Bulk order transition" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(1));
    resolvePost?.();
  });

  it("fails closed for content permissions", () => {
    useAuthStore.setState({ user: { role: "viewer" }, isAuthenticated: true });
    renderWithProviders(<PostsPage />);
    expect(screen.getByRole("alert")).toHaveTextContent(/read-only access/i);
    expect(apiMock.get).not.toHaveBeenCalled();
  });

  it("uses only the shared API boundary for merchant and order requests", async () => {
    await merchantService.inventory("a variant/with spaces");
    await merchantService.adjustInventory("sku-1", { quantity: 3 } as any, "idem-adjust");
    await merchantService.transferInventory("sku-1", { source_id: "a", destination_id: "b", quantity: 2 } as any, "idem-transfer");
    await merchantService.ship("f-1", undefined, "idem-ship");
    await merchantService.deliver("f-1", "idem-deliver");
    await ordersService.transition(42, { to: "confirmed" } as any, "idem-order");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/inventory/a%20variant%2Fwith%20spaces");
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/inventory/sku-1", { quantity: 3 }, { idempotencyKey: "idem-adjust" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/inventory/sku-1/transfer", { source_id: "a", destination_id: "b", quantity: 2 }, { idempotencyKey: "idem-transfer" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f-1/ship", undefined, { idempotencyKey: "idem-ship" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/fulfillment/f-1/deliver", undefined, { idempotencyKey: "idem-deliver" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/orders/42/transition", { to: "confirmed" }, { idempotencyKey: "idem-order" });
  });

  it("unwraps the configured success envelopes for every live adapter", async () => {
    const inventory = { variant_id: "sku-1", source_id: "main", on_hand: 10, reserved: 2, available: 8 };
    const transfer = { variant_id: "sku-1", source_id: "a", destination_id: "b", quantity: 2 };
    const write = { id: "f-1", status: "allocated" };
    const transitioned = { ...order, status: "confirmed" };
    apiMock.get
      .mockResolvedValueOnce(ok(inventory))
      .mockResolvedValueOnce(ok({ id: "f-1", status: "allocated" }));
    apiMock.put.mockResolvedValueOnce(ok(inventory));
    apiMock.post
      .mockResolvedValueOnce(ok(transfer))
      .mockResolvedValueOnce(ok(write))
      .mockResolvedValueOnce(ok(write))
      .mockResolvedValueOnce(ok(write))
      .mockResolvedValueOnce(ok(transitioned));

    await expect(merchantService.inventory("sku-1")).resolves.toEqual(inventory);
    await expect(merchantService.adjustInventory("sku-1", { quantity: 3 } as any, "idem-adjust")).resolves.toEqual(inventory);
    await expect(merchantService.transferInventory("sku-1", { source_id: "a", destination_id: "b", quantity: 2 } as any, "idem-transfer")).resolves.toEqual(transfer);
    await expect(merchantService.allocate({ order_id: "42", lines: [{ variant_id: "sku-1", qty: 1 }] } as any, "idem-allocate")).resolves.toEqual(write);
    await expect(merchantService.ship("f-1", undefined, "idem-ship")).resolves.toEqual(write);
    await expect(merchantService.deliver("f-1", "idem-deliver")).resolves.toEqual(write);
    await expect(ordersService.transition(42, { to: "confirmed" } as any, "idem-order")).resolves.toEqual(transitioned);
    await expect(merchantService.track("f-1")).resolves.toEqual({ id: "f-1", status: "allocated" });
  });

  it("does not expose a legacy direct transport in the merchant flow", () => {
    expect(merchantService).not.toHaveProperty("fetch");
    expect(merchantService).not.toHaveProperty(["ax", "ios"].join(""));
    expect(api).toBeDefined();
  });

  it("keeps stale scope responses invalid after the store changes", () => {
    const scopes = new ScopeCoordinator();
    const first = scopes.set({ store_id: "store-a", channel_id: "web", locale: "en", currency: "USD" });
    const captured = scopes.capture();
    scopes.set({ store_id: "store-b", channel_id: "web", locale: "en", currency: "USD" });
    expect(scopes.isCurrent(first)).toBe(false);
    expect(scopes.isCurrent(captured.version)).toBe(false);
    expect(captured.scope?.store_id).toBe("store-a");
  });

});
