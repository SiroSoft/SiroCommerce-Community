import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import PickupPointsPage from "@/app/(dashboard)/pickup-points/page";
import GiftCardsPage from "@/app/(dashboard)/gift-cards/page";
import MediaPage from "@/app/(dashboard)/media/page";
import ExtensionsPage from "@/app/(dashboard)/extensions/page";
import type { ExtensionItem } from "@/services/extensions.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const nested = (data: unknown) => Promise.resolve({ data: { data: { data } }, status: 200, headers: new Headers() });

const pickup = { code: "HN-01", name: "Hoan Kiem", source_id: "main", address_line: "1 Pho Hue", city: "Ha Noi", sla_hours: 4, state: "active" };
const gift = { id: "gc-1", code: "GC-001", owner_customer_id: null, type: "gift_card", amount_minor: 100000, balance_minor: 75000, currency: "VND", state: "active", expires_at: null };
const assets = [
  { id: "a-1", url: "https://cdn.test/a.jpg", type: "image", title: "Hero", alt: "Hero", size_bytes: 1200, mime_type: "image/jpeg", variants: [{ w: 320, url: "https://cdn.test/a-320.webp", format: "webp" }] },
  { id: "a-2", url: "https://cdn.test/b.mp4", type: "video", title: "Clip", size_bytes: 2200, mime_type: "video/mp4" },
  { id: "a-3", url: "https://cdn.test/c.pdf", type: "file", title: "Spec", size_bytes: 3300, mime_type: "application/pdf" },
];

function renderWithProviders(ui: React.ReactElement) {
  return render(<I18nProvider>{ui}</I18nProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("pickup points page", () => {
  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => path === "/api/pickup-points" ? body([pickup]) : body([]));
    apiMock.post.mockResolvedValue({ data: { data: { ...pickup, code: "HN-02", name: "Cau Giay" } }, status: 201, headers: new Headers() });
    apiMock.put.mockResolvedValue(body({ ...pickup, state: "disabled" }));
  });

  it("lists points and issues a create request with an idempotency key", async () => {
    renderWithProviders(<PickupPointsPage />);
    expect(await screen.findByText("HN-01")).toBeVisible();
    expect(screen.getByText(/Hoan Kiem/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Point code"), { target: { value: "hn-02" } });
    fireEvent.change(screen.getByLabelText("Point name"), { target: { value: "Cau Giay" } });
    fireEvent.change(screen.getByLabelText("Inventory source"), { target: { value: "main" } });
    fireEvent.change(screen.getByLabelText("Address"), { target: { value: "8 Ton That Thuyet" } });
    fireEvent.change(screen.getByLabelText("City"), { target: { value: "Ha Noi" } });
    fireEvent.click(screen.getByRole("button", { name: "Add point" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/pickup-points",
      expect.objectContaining({ code: "HN-02", name: "Cau Giay", source_id: "main" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("HN-02 is taking pickup orders.")).toBeVisible();
  });

  it("disables an active point after confirmation", async () => {
    renderWithProviders(<PickupPointsPage />);
    await screen.findByText("HN-01");
    fireEvent.click(screen.getByRole("button", { name: "Disable" }));
    const dialog = await screen.findByRole("dialog", { name: "Disable pickup point" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/pickup-points/HN-01",
      { state: "disabled" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("shows the empty state and surfaces load failures", async () => {
    apiMock.get.mockResolvedValueOnce(body([]));
    const { unmount } = renderWithProviders(<PickupPointsPage />);
    expect(await screen.findByText("No pickup points yet.")).toBeVisible();
    unmount();
    apiMock.get.mockRejectedValueOnce(new Error("network"));
    renderWithProviders(<PickupPointsPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
  });
});

describe("gift cards page", () => {
  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => path === "/api/admin/gift-cards" ? body([gift]) : body([]));
    apiMock.post.mockResolvedValue({ data: { data: { ...gift, code: "GC-002", balance_minor: 50000 } }, status: 201, headers: new Headers() });
  });

  it("renders balances and issues store credit", async () => {
    renderWithProviders(<GiftCardsPage />);
    expect(await screen.findByText("GC-001")).toBeVisible();
    expect(screen.getByText(/balance 75,000/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Amount in minor units"), { target: { value: "50000" } });
    fireEvent.change(screen.getByLabelText("Owner customer id"), { target: { value: "cust-1" } });
    fireEvent.change(screen.getByLabelText("Gift code"), { target: { value: "gc-002" } });
    fireEvent.click(screen.getByRole("button", { name: "Issue" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/gift-cards",
      expect.objectContaining({ amount_minor: 50000, owner_customer_id: "cust-1", code: "GC-002" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Issued GC-002/)).toBeVisible();
  });

  it("rejects invalid amounts without an API call and voids active credit", async () => {
    renderWithProviders(<GiftCardsPage />);
    await screen.findByText("GC-001");
    fireEvent.change(screen.getByLabelText("Amount in minor units"), { target: { value: "0" } });
    fireEvent.click(screen.getByRole("button", { name: "Issue" }));
    await waitFor(() => expect(apiMock.post).not.toHaveBeenCalled());
    fireEvent.click(screen.getByRole("button", { name: "Void" }));
    const dialog = await screen.findByRole("dialog", { name: "Void gift card" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/gift-cards/gc-1/void",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("filters by customer and renders the empty state", async () => {
    renderWithProviders(<GiftCardsPage />);
    await screen.findByText("GC-001");
    apiMock.get.mockResolvedValueOnce(body([]));
    fireEvent.change(screen.getByLabelText("Filter by customer"), { target: { value: "cust-9" } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    expect(await screen.findByText("No credit on record.")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/gift-cards", { params: { customer_id: "cust-9" } });
  });
});

describe("media library page", () => {
  beforeEach(() => {
    apiMock.get.mockResolvedValue(nested(assets));
    apiMock.post.mockResolvedValue({ data: { data: { id: "a-4", url: "https://cdn.test/new.jpg", type: "image" } }, status: 201, headers: new Headers() });
    apiMock.delete.mockResolvedValue({ data: { data: null }, status: 200, headers: new Headers() });
    Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });
  });

  it("renders image, video and file assets with derivative details", async () => {
    renderWithProviders(<MediaPage />);
    expect(await screen.findByAltText("Hero")).toBeVisible();
    expect(screen.getByText("Clip")).toBeVisible();
    expect(screen.getByText(/file · application\/pdf/)).toBeVisible();
    expect(screen.getByText(/1 derivatives · WebP 320/)).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/media-library", expect.objectContaining({ params: expect.objectContaining({ per_page: 48 }) }));
  });

  it("uploads a selected file and deletes an asset through the library contract", async () => {
    const file = new File(["hello"], "hero.jpg", { type: "image/jpeg" });
    renderWithProviders(<MediaPage />);
    await screen.findByAltText("Hero");
    const input = screen.getByLabelText(/File \(image/);
    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.change(screen.getByLabelText("Title (optional)"), { target: { value: "New hero" } });
    fireEvent.change(screen.getByLabelText("Title (optional)"), { target: { value: "New hero" } });
    fireEvent.click(screen.getByRole("button", { name: "Upload" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/media-library",
      expect.any(FormData),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/Uploaded image/)).toBeVisible();
    fireEvent.click(screen.getAllByRole("button", { name: "Delete" })[0]);
    const dialog = await screen.findByRole("dialog", { name: "Delete media asset" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/media-library/a-1"));
  });

  it("shows a notice when no file is chosen and filters via search", async () => {
    renderWithProviders(<MediaPage />);
    await screen.findByAltText("Hero");
    fireEvent.click(screen.getByRole("button", { name: "Upload" }));
    expect(await screen.findByText(/Choose a file first/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Search"), { target: { value: "hero" } });
    fireEvent.change(screen.getByLabelText("Type"), { target: { value: "image" } });
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith(
      "/api/admin/media-library",
      expect.objectContaining({ params: expect.objectContaining({ q: "hero", type: "image" }) }),
    ));
  });
});

const storeLocatorExtension: ExtensionItem = {
  id: "siro/store-locator",
  version: "1.0.0",
  manifest_version: "1",
  state: "enabled",
  last_error: null,
  capabilities: ["store-locator:read", "store-locator:manage"],
  permissions: ["store-locator:manage"],
  migrations: ["001"],
  routes: ["/api/ext/siro/store-locator"],
  requires: {},
  admin_ui: { label: "Store locator", section: "commerce", href: "/extensions/siro/store-locator", panels: [] },
};

const failedExtension: ExtensionItem = {
  ...storeLocatorExtension,
  id: "siro/broken",
  state: "failed",
  last_error: "manifest invalid",
  admin_ui: null,
  routes: [],
  migrations: [],
};

describe("extensions pages", () => {
  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/admin/extensions") return body([storeLocatorExtension, failedExtension]);
      return body({});
    });
    apiMock.post.mockImplementation((path: string) => {
      if (path === "/api/admin/extensions/health") return body({ ok: true });
      return body({ ok: true });
    });
  });

  it("renders extension states and runs lifecycle actions", async () => {
    renderWithProviders(<ExtensionsPage />);
    expect(await screen.findByText("siro/store-locator")).toBeVisible();
    expect(screen.getByText("manifest invalid")).toBeVisible();
    fireEvent.click(screen.getAllByRole("button", { name: "Health" })[0]);
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/health", { id: "siro/store-locator" }));
    expect(await screen.findByText(/"ok":true/)).toBeVisible();
    const disableButtons = screen.getAllByRole("button", { name: "Disable" });
    fireEvent.click(disableButtons[0]);
    const dialog = await screen.findByRole("dialog", { name: "Disable extension" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/extensions/disable",
      { id: "siro/store-locator" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("uninstalls with data removal after a destructive confirmation", async () => {
    renderWithProviders(<ExtensionsPage />);
    await screen.findByText("siro/store-locator");
    fireEvent.click(screen.getAllByRole("checkbox", { name: /remove data/i })[0]);
    fireEvent.click(screen.getAllByRole("button", { name: "Uninstall" })[0]);
    const dialog = await screen.findByRole("dialog", { name: "Uninstall extension" });
    expect(within(dialog).getByText(/data will be deleted/i)).toBeVisible();
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/extensions/uninstall",
      { id: "siro/store-locator", remove_data: true },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});