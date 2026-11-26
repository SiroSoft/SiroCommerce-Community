import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import FlashSalesPage from "@/app/(dashboard)/flash-sales/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const sale = {
  id: "s-1",
  name: "Gio vang 20h",
  state: "draft",
  starts_at: "2026-06-01T12:00:00Z",
  ends_at: "2026-06-01T14:00:00Z",
  min_tier: "gold",
  items: [{ id: "i-1", variant_id: "v-1", sale_price_minor: 99000, cap_qty: 10, sold_qty: 2 }],
};
const memberPrice = { id: "mp-1", variant_id: "v-1", tier: "gold", price_minor: 88000, currency: "VND" };
const deal = { id: "d-1", name: "Summer tier", kind: "tier_window", min_tier: "silver", percent_off: 15, starts_at: null, ends_at: null, state: "active" };

function renderWithProviders(ui: React.ReactElement) {
  return render(<I18nProvider>{ui}</I18nProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/flash-sales") return body([sale]);
    if (path === "/api/admin/member-prices") return body([memberPrice]);
    if (path === "/api/admin/member-deals") return body([deal]);
    return body([]);
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/admin/flash-sales") return body({ ...sale, id: "s-2", state: "draft" });
    if (path === "/api/admin/flash-sales/s-1/items") return body({ id: "i-2" });
    if (path === "/api/admin/member-prices") return body(memberPrice);
    if (path === "/api/admin/member-deals") return body({ ...deal, id: "d-2" });
    return body({});
  });
  apiMock.put.mockImplementation((path: string) => {
    if (path === "/api/admin/flash-sales/s-1") return body({ ...sale, state: "active" });
    if (path === "/api/admin/member-deals/d-1") return body({ ...deal, state: "disabled" });
    return body({});
  });
});

afterEach(() => {
  cleanup();
});

describe("flash sales page", () => {
  it("renders time-boxed sales, member prices and member deals", async () => {
    renderWithProviders(<FlashSalesPage />);
    expect(await screen.findByText("Gio vang 20h")).toBeVisible();
    expect(screen.getByText(/sold 2\/10/)).toBeVisible();
    expect(screen.getByText(/v-1 · gold/)).toBeVisible();
    expect(screen.getByText(/Summer tier/)).toBeVisible();
    expect(screen.getByRole("combobox", { name: /Minimum tier for/ })).toHaveValue("gold");
  });

  it("creates a sale and validates required schedule fields", async () => {
    renderWithProviders(<FlashSalesPage />);
    await screen.findByText("Gio vang 20h");
    fireEvent.click(screen.getByRole("button", { name: "Create sale" }));
    fireEvent.change(screen.getByLabelText("Name", { selector: "#flash-name" }), { target: { value: "Flash Weekend" } });
    fireEvent.change(screen.getByLabelText("Starts at"), { target: { value: "2026-07-01T08:00" } });
    fireEvent.change(screen.getByLabelText("Ends at"), { target: { value: "2026-07-01T20:00" } });
    await waitFor(() => expect(screen.getByRole("button", { name: "Create sale" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Create sale" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/flash-sales",
      expect.objectContaining({ name: "Flash Weekend", state: "draft" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Flash sale created as draft/)).toBeVisible();
  });

  it("adds a sale item and rejects invalid minor-unit prices", async () => {
    renderWithProviders(<FlashSalesPage />);
    await screen.findByText("Gio vang 20h");
    fireEvent.change(screen.getByLabelText("Sale ID"), { target: { value: "s-1" } });
    fireEvent.change(screen.getByLabelText("Variant ID", { selector: "#flash-variant" }), { target: { value: "v-9" } });
    fireEvent.change(screen.getByLabelText("Sale price (minor)"), { target: { value: "-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Add deal item" }));
    expect(await screen.findByText(/non-negative integer/)).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText("Sale price (minor)"), { target: { value: "49000" } });
    fireEvent.change(screen.getByLabelText("Cap qty"), { target: { value: "25" } });
    fireEvent.click(screen.getByRole("button", { name: "Add deal item" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/flash-sales/s-1/items",
      { variant_id: "v-9", sale_price_minor: 49000, cap_qty: 25 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Item added")).toBeVisible();
  });

  it("activates and archives a sale after confirmation", async () => {
    renderWithProviders(<FlashSalesPage />);
    await screen.findByText("Gio vang 20h");
    fireEvent.click(screen.getByRole("button", { name: "Activate" }));
    const activate = await screen.findByRole("dialog", { name: "Update flash sale" });
    fireEvent.click(within(activate).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/flash-sales/s-1",
      { state: "active" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    const archiveButton = screen.getByRole("button", { name: "Archive" });
    await waitFor(() => expect(archiveButton).not.toBeDisabled());
    fireEvent.click(archiveButton);
    const archive = await screen.findByRole("dialog", { name: "Update flash sale" });
    fireEvent.click(within(archive).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/flash-sales/s-1",
      { state: "archived" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("changes the minimum tier through the sales contract", async () => {
    renderWithProviders(<FlashSalesPage />);
    await screen.findByText("Gio vang 20h");
    fireEvent.change(screen.getByRole("combobox", { name: /Minimum tier for/ }), { target: { value: "platinum" } });
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/flash-sales/s-1",
      { min_tier: "platinum" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("saves member prices and validates integer amounts", async () => {
    renderWithProviders(<FlashSalesPage />);
    await screen.findByText("Gio vang 20h");
    fireEvent.change(screen.getByLabelText("Variant ID", { selector: "#member-variant" }), { target: { value: "v-1" } });
    fireEvent.change(screen.getByLabelText("Price (minor)"), { target: { value: "-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    expect(await screen.findByText(/non-negative integer/)).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText("Price (minor)"), { target: { value: "88000" } });
    fireEvent.change(screen.getByLabelText("Tier"), { target: { value: "platinum" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/member-prices",
      { variant_id: "v-1", tier: "platinum", price_minor: 88000 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Member price saved \(platinum\)/)).toBeVisible();
  });

  it("creates member deals and toggles them after confirmation", async () => {
    renderWithProviders(<FlashSalesPage />);
    await screen.findByText("Summer tier");
    fireEvent.change(screen.getByLabelText("Name", { selector: "#deal-name" }), { target: { value: "Birthday 20" } });
    fireEvent.change(screen.getByLabelText("Kind"), { target: { value: "birthday" } });
    fireEvent.change(screen.getByLabelText("Min tier"), { target: { value: "gold" } });
    fireEvent.change(screen.getByLabelText("Percent off"), { target: { value: "20" } });
    fireEvent.change(screen.getByLabelText("Starts", { selector: "#deal-starts" }), { target: { value: "2026-08-01T00:00" } });
    fireEvent.change(screen.getByLabelText("Ends", { selector: "#deal-ends" }), { target: { value: "2026-08-31T00:00" } });
    fireEvent.click(screen.getByRole("button", { name: "Create deal" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/member-deals",
      expect.objectContaining({ name: "Birthday 20", kind: "birthday", min_tier: "gold", percent_off: 20 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    const disableButton = screen.getByRole("button", { name: "Disable" });
    await waitFor(() => expect(disableButton).not.toBeDisabled());
    fireEvent.click(disableButton);
    const dialog = await screen.findByRole("dialog", { name: "Update member deal" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/member-deals/d-1",
      { state: "disabled" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("renders the empty state when no sales exist", async () => {
    apiMock.get.mockImplementation((path: string) => path === "/api/admin/flash-sales" ? body([]) : body([]));
    renderWithProviders(<FlashSalesPage />);
    expect(await screen.findByText("No flash sales yet.")).toBeVisible();
  });
});
