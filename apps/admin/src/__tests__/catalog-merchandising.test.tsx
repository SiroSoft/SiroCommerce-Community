import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import TagsPage from "@/app/(dashboard)/tags/page";
import CategoriesPage from "@/app/(dashboard)/categories/page";
import InventoryRoute from "@/app/(dashboard)/inventory/page";
import CampaignsPage from "@/app/(dashboard)/campaigns/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/tags" }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "admin" }, login: vi.fn(), isLoginPending: false, loginError: null, isLoading: false }) }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const tag = { id: 3, name: "Summer", slug: "summer", created_at: "2026-09-23T10:00:00Z" };
const category = { id: 5, name: "Apparel", slug: "apparel", is_active: true, sort_order: 1, created_at: "2026-09-23T10:00:00Z" };
const inventory = { variant_id: "sku-1", source_id: "main", on_hand: 10, reserved: 2, available: 8 };
const automation = { enabled: false, max_messages_per_day: 1000, quiet_hours_start: 21, quiet_hours_end: 8, allowed_channels: ["email"] };

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "admin" }, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/tags") return Promise.resolve({ data: { data: [tag], meta: { page: 1, last_page: 1, total: 1 } }, status: 200, headers: new Headers() });
    if (path === "/api/categories") return Promise.resolve({ data: { data: [category], meta: { page: 1, last_page: 1, total: 1 } }, status: 200, headers: new Headers() });
    if (path === "/api/admin/inventory/sku-1") return ok(inventory);
    if (path === "/api/v2/admin/campaign-automation") return ok(automation);
    return ok({ data: [], meta: {} });
  });
  apiMock.put.mockImplementation((path: string) => {
    if (path === "/api/admin/inventory/sku-1") return ok(inventory);
    if (path === "/api/v2/admin/campaign-automation") return ok({ ...automation, max_messages_per_day: 500 });
    return ok({});
  });
});

afterEach(() => {
  cleanup();
});

describe("catalog merchandising: tags and categories lists", () => {
  it("forwards tag header sorting to the real list endpoint", async () => {
    renderWithProviders(<TagsPage />);
    expect(await screen.findByText("Summer")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Name" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/tags", expect.objectContaining({ params: expect.objectContaining({ sort: "name", order: "asc" }) })));
  });

  it("resets tag pagination for a new search", async () => {
    renderWithProviders(<TagsPage />);
    expect(await screen.findByText("Summer")).toBeVisible();
    fireEvent.change(screen.getByRole("textbox"), { target: { value: "sum" } });
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/tags", expect.objectContaining({ params: expect.objectContaining({ search: "sum", page: 1 }) })));
  });

  it("loads categories from the real list endpoint", async () => {
    renderWithProviders(<CategoriesPage />);
    expect(await screen.findByText("Apparel")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/categories", expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }));
  });
});

describe("catalog merchandising: live-only inventory", () => {
  it("shows no fixture or demo affordances before a variant is entered", () => {
    renderWithProviders(<InventoryRoute />);
    expect(screen.getByRole("heading", { name: "Inventory" })).toBeVisible();
    expect(screen.getByText(/enter a variant id to load live inventory/i)).toBeVisible();
    expect(screen.queryByText(/demo/i)).not.toBeInTheDocument();
    expect(apiMock.get).not.toHaveBeenCalled();
  });

  it("loads live stock and blocks adjustments below reserved before transport", async () => {
    renderWithProviders(<InventoryRoute />);
    fireEvent.change(screen.getByRole("textbox", { name: "Variant ID" }), { target: { value: "sku-1" } });
    expect(await screen.findByText("sku-1")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/inventory/sku-1");
    fireEvent.change(screen.getByRole("spinbutton", { name: "New on-hand quantity" }), { target: { value: "1" } });
    expect(screen.getByRole("alert")).toHaveTextContent(/below reserved stock/i);
    expect(screen.getByRole("button", { name: "Apply adjustment" })).toBeDisabled();
    expect(apiMock.put).not.toHaveBeenCalled();
  });

  it("submits a valid adjustment and confirms success", async () => {
    renderWithProviders(<InventoryRoute />);
    fireEvent.change(screen.getByRole("textbox", { name: "Variant ID" }), { target: { value: "sku-1" } });
    expect(await screen.findByText("sku-1")).toBeVisible();
    fireEvent.change(screen.getByRole("spinbutton", { name: "New on-hand quantity" }), { target: { value: "12" } });
    fireEvent.click(screen.getByRole("button", { name: "Apply adjustment" }));
    const dialog = await screen.findByRole("dialog", { name: "Apply inventory adjustment" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith("/api/admin/inventory/sku-1", { on_hand: 12 }, expect.objectContaining({ idempotencyKey: expect.any(String) })));
    expect(await screen.findByText(/adjustment accepted/i)).toBeVisible();
  });
});

describe("catalog merchandising: campaign automation settings", () => {
  it("loads settings from the real endpoint", async () => {
    renderWithProviders(<CampaignsPage />);
    expect(screen.getByText(/loading settings/i)).toBeVisible();
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/campaign-automation"));
    expect(await screen.findByLabelText("Daily message cap")).toHaveValue(1000);
    expect(screen.getByRole("checkbox", { name: "Email" })).toBeChecked();
  });

  it("validates ranges and channels before saving", async () => {
    renderWithProviders(<CampaignsPage />);
    await screen.findByLabelText("Daily message cap");
    fireEvent.change(screen.getByLabelText("Daily message cap"), { target: { value: "" } });
    fireEvent.change(screen.getByLabelText(/quiet hours start/i), { target: { value: "30" } });
    fireEvent.click(screen.getByRole("checkbox", { name: "Email" }));
    fireEvent.click(screen.getByRole("button", { name: "Save settings" }));
    expect(await screen.findByText(/daily message cap must be a whole number/i)).toBeVisible();
    expect(screen.getByText(/quiet hours start must be a whole number/i)).toBeVisible();
    expect(screen.getByText(/at least one allowed channel/i)).toBeVisible();
    expect(apiMock.put).not.toHaveBeenCalled();
  });

  it("saves valid settings and confirms success", async () => {
    renderWithProviders(<CampaignsPage />);
    await screen.findByLabelText("Daily message cap");
    fireEvent.change(screen.getByLabelText("Daily message cap"), { target: { value: "500" } });
    fireEvent.click(screen.getByRole("button", { name: "Save settings" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith("/api/v2/admin/campaign-automation", expect.objectContaining({ max_messages_per_day: 500 }), expect.objectContaining({ idempotencyKey: expect.any(String) })));
    expect(await screen.findByText(/changes apply only to this commerce scope/i)).toBeVisible();
  });

  it("recovers from a load failure through retry", async () => {
    apiMock.get.mockRejectedValueOnce(new TypeError("network down"));
    renderWithProviders(<CampaignsPage />);
    expect(await screen.findByText(/campaign settings are unavailable/i)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByLabelText("Daily message cap")).toBeVisible();
  });
});
