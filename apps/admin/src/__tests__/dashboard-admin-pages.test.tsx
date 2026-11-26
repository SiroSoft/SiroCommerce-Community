import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import SearchSeoPage from "@/app/(dashboard)/search-seo/page";
import ConfigurationPage from "@/app/(dashboard)/configuration/page";
import AiCopilotPage from "@/app/(dashboard)/ai-copilot/page";
import AdminWorkbenchPage from "@/app/(dashboard)/admin-workbench/page";
import UsersPage from "@/app/(dashboard)/users/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "owner", name: "Owner", email: "owner@example.test" }, isLoading: false, isAuthenticated: true }) }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const nested = (data: unknown) => Promise.resolve({ data: { data: { data } }, status: 200, headers: new Headers() });
const nestedObject = (key: string, data: unknown) => Promise.resolve({ data: { data: { [key]: data } }, status: 200, headers: new Headers() });

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "owner" } as never, isAuthenticated: true });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("search and SEO page", () => {
  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/admin/search-seo/url-rewrites") return nested([{ id: "r-1", request_path: "/old-path", target_path: "/new-path", redirect_type: 301 }]);
      if (path.startsWith("/api/admin/search-seo/search-terms")) return nested([{ id: "t-1", term: "ao", hits: 5, misses: 1 }]);
      if (path === "/api/admin/search-seo/synonyms") return nested([{ id: "s-1", term: "ao", synonyms: ["shirt", "top"] }]);
      if (path === "/api/admin/search-seo/sitemaps") return nestedObject("settings", {
        products: { frequency: "daily", priority: 0.8 },
        categories: { frequency: "weekly", priority: 0.6 },
        cms_pages: { frequency: "monthly", priority: 0.4 },
        brands: { frequency: "yearly", priority: 0.2 },
        images_enabled: true,
      });
      return body({});
    });
    apiMock.post.mockResolvedValue(body({ id: "created" }));
    apiMock.put.mockResolvedValue(body({ ok: true }));
    apiMock.delete.mockResolvedValue({ data: { data: null }, status: 200, headers: new Headers() });
  });

  it("creates and deletes URL rewrites", async () => {
    renderWithProviders(<SearchSeoPage />);
    expect(await screen.findByText(/\/old-path/)).toBeVisible();
    fireEvent.change(screen.getByPlaceholderText("/old"), { target: { value: "/legacy" } });
    fireEvent.change(screen.getByPlaceholderText("/new or https://"), { target: { value: "/modern" } });
    fireEvent.click(screen.getByRole("button", { name: "Create" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/search-seo/url-rewrites",
      { request_path: "/legacy", target_path: "/modern", redirect_type: 301 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/search-seo/url-rewrites/r-1"));
  });

  it("searches terms and manages synonyms", async () => {
    renderWithProviders(<SearchSeoPage />);
    await screen.findByText(/\/old-path/);
    fireEvent.click(screen.getByRole("button", { name: "Terms" }));
    expect(await screen.findByText("ao")).toBeVisible();
    fireEvent.change(screen.getByPlaceholderText("Search term"), { target: { value: "shirt" } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/search-seo/search-terms?q=shirt"));
    fireEvent.click(screen.getByRole("button", { name: "Synonyms" }));
    expect(await screen.findByText("ao → shirt, top")).toBeVisible();
    fireEvent.change(screen.getByPlaceholderText("áo"), { target: { value: "quan" } });
    fireEvent.change(screen.getByPlaceholderText("shirt, ao"), { target: { value: "pants, trousers" } });
    fireEvent.click(screen.getByRole("button", { name: "Create" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/search-seo/synonyms",
      { term: "quan", synonyms: ["pants", "trousers"] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/search-seo/synonyms/s-1"));
  });

  it("saves sitemap settings and requests generation", async () => {
    renderWithProviders(<SearchSeoPage />);
    await screen.findByText(/\/old-path/);
    fireEvent.click(screen.getByRole("button", { name: "Sitemaps" }));
    expect(await screen.findByText("Sitemaps")).toBeVisible();
    fireEvent.change(screen.getAllByRole("combobox")[0], { target: { value: "weekly" } });
    fireEvent.change(screen.getAllByRole("spinbutton")[0], { target: { value: "0.9" } });
    fireEvent.click(screen.getByRole("checkbox"));
    fireEvent.click(screen.getByRole("button", { name: "Save settings" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith("/api/admin/search-seo/sitemaps", expect.objectContaining({
      products: expect.objectContaining({ frequency: "weekly", priority: 0.9 }),
      images_enabled: false,
    })));
    expect(await screen.findByText("Sitemap settings saved")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Generate sitemap" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/search-seo/sitemaps/generate",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});

describe("configuration page", () => {
  const groups = [{
    key: "sales",
    name: "Sales",
    sections: [{
      key: "general",
      name: "General",
      fields: [
        { key: "app_name", type: "text", label: "App name", default: "Siro", required: true },
        { key: "enabled", type: "boolean", label: "Enabled", default: true },
        { key: "country", type: "select", label: "Country", default: "VN", options: ["VN", "US"] },
        { key: "description", type: "textarea", label: "Description", default: "Store" },
        { key: "page_size", type: "number", label: "Page size", default: 20 },
        { key: "logo", type: "image", label: "Logo", default: "https://cdn.test/logo.png" },
      ],
    }],
  }];

  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/admin/configuration") return body({ groups, values: { "sales.general.app_name": "Siro" } });
      if (path === "/api/admin/configuration/sales/general") return body({ version: 3 });
      return body({});
    });
    apiMock.put.mockResolvedValue(body({ values: { app_name: "Shop" }, version: 4 }));
  });

  it("renders configurable fields and saves a section with optimistic version", async () => {
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByText("Sales")).toBeVisible();
    expect(screen.getByText("General")).toBeVisible();
    expect(screen.getByAltText("")).toBeVisible();
    fireEvent.change(screen.getByLabelText(/App name/), { target: { value: "Shop" } });
    fireEvent.change(screen.getByLabelText("Enabled"), { target: { checked: false } });
    fireEvent.change(screen.getByLabelText("Country"), { target: { value: "US" } });
    fireEvent.change(screen.getByLabelText("Description"), { target: { value: "Next store" } });
    fireEvent.change(screen.getByLabelText("Page size"), { target: { value: "50" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/configuration/sales/general"));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/configuration/sales/general",
      { values: expect.objectContaining({ app_name: "Shop", country: "US", page_size: 50 }), expected_version: 3 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Saved sales.general")).toBeVisible();
  });

  it("filters fields and shows the no-match message", async () => {
    renderWithProviders(<ConfigurationPage />);
    await screen.findByText("Sales");
    fireEvent.change(screen.getByLabelText("Search configuration"), { target: { value: "app-name" } });
    expect(await screen.findByText(/No groups match/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Search configuration"), { target: { value: "app" } });
    await waitFor(() => expect(screen.getByLabelText(/App name/)).toBeVisible());
  });

  it("shows a retryable error state when configuration cannot load", async () => {
    apiMock.get.mockRejectedValueOnce(new Error("offline"));
    renderWithProviders(<ConfigurationPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(screen.getByRole("button", { name: "Try again" })).toBeVisible();
  });
});

describe("AI copilot page", () => {
  const rows = [
    { id: "cp-1", task: "product_description", product_id: "p-1", state: "pending", suggestion: { title: "Nice" }, provider: "openai", model: "gpt" },
    { id: "cp-2", task: "review_draft", state: "failed", suggestion: null, reason: "provider unavailable" },
    { id: "cp-3", task: "catalog_enrichment", state: "approved", suggestion: { ok: true } },
  ];

  beforeEach(() => {
    apiMock.get.mockResolvedValue(body(rows));
    apiMock.post.mockResolvedValue(body({ id: "cp-4", state: "pending" }));
  });

  it("loads the queue and approves or rejects drafts after confirmation", async () => {
    renderWithProviders(<AiCopilotPage />);
    expect(screen.getByText("Refresh to load the queue.")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Refresh queue" }));
    expect(await screen.findByText("product description")).toBeVisible();
    expect(screen.getByText("provider unavailable")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Approve" }));
    const approve = await screen.findByRole("dialog", { name: "Approve draft" });
    fireEvent.click(within(approve).getByRole("button", { name: "Approve" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/ai/copilot/queue/cp-1/approve", {}));
    fireEvent.click(screen.getByRole("button", { name: "Reject" }));
    const reject = await screen.findByRole("dialog", { name: "Reject draft" });
    fireEvent.click(within(reject).getByRole("button", { name: "Reject" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/ai/copilot/queue/cp-1/reject", {}));
  });

  it("generates a draft with topic input and surfaces provider failures", async () => {
    renderWithProviders(<AiCopilotPage />);
    fireEvent.click(screen.getByRole("button", { name: "Generate draft" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/ai/copilot/tasks", expect.objectContaining({ task: "product_description", max_cost_minor: 50 })));
    fireEvent.change(screen.getByLabelText("Topic or brief"), { target: { value: "Summer sale" } });
    fireEvent.change(screen.getByLabelText("Product ID"), { target: { value: "p-9" } });
    apiMock.post.mockResolvedValueOnce(body({ id: "cp-9", state: "failed", reason: "budget exhausted" }));
    fireEvent.click(screen.getByRole("button", { name: "Generate draft" }));
    expect(await screen.findByText(/Not generated: budget exhausted/)).toBeVisible();
    apiMock.post.mockRejectedValueOnce(new Error("denied"));
    fireEvent.click(screen.getByRole("button", { name: "Generate draft" }));
    expect(await screen.findByText(/Generation request failed/)).toBeVisible();
  });

  it("handles empty and error queue states", async () => {
    apiMock.get.mockResolvedValueOnce(body([]));
    const { unmount } = renderWithProviders(<AiCopilotPage />);
    fireEvent.click(screen.getByRole("button", { name: "Refresh queue" }));
    expect(await screen.findByText("No drafts yet.")).toBeVisible();
    unmount();
    apiMock.get.mockRejectedValueOnce(new Error("down"));
    renderWithProviders(<AiCopilotPage />);
    fireEvent.click(screen.getByRole("button", { name: "Refresh queue" }));
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(screen.getByRole("button", { name: "Retry" })).toBeVisible();
  });
});

describe("admin workbench page", () => {
  const workbench = {
    capabilities: ["*", "orders", "products"],
    roles: ["owner", "admin", "viewer"],
    permission_sets: { owner: ["*"], admin: ["orders"], viewer: [] },
    saved_views: [{ id: "v-1", name: "Needs review", resource: "orders", filters: {}, columns: [] }],
    approval_workflows: [{ id: "w-1", name: "Refund review", resource: "orders", action: "refund", steps: ["owner"] }],
  };

  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/v2/admin/workbench") return body(workbench);
      if (path.startsWith("/api/v2/admin/exports/")) return body({ id: "exp-1", state: "completed" });
      return body({});
    });
    apiMock.post.mockResolvedValue(body({ id: "created" }));
    apiMock.put.mockResolvedValue(body({ ok: true }));
    apiMock.delete.mockResolvedValue({ data: { data: null }, status: 200, headers: new Headers() });
  });

  it("saves and deletes saved views through the workbench contract", async () => {
    renderWithProviders(<AdminWorkbenchPage />);
    expect(await screen.findByText("Admin workbench")).toBeVisible();
    expect(screen.getByText("Needs review")).toBeVisible();
    fireEvent.change(screen.getByLabelText("View name"), { target: { value: "Escalations" } });
    fireEvent.click(screen.getByRole("button", { name: "Save view" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/views",
      { name: "Escalations", resource: "orders", filters: { status: "pending" } },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("View saved")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    const dialog = await screen.findByRole("dialog", { name: "Delete saved view" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/views/v-1",
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("runs bulk order actions, queues exports and refreshes export status", async () => {
    renderWithProviders(<AdminWorkbenchPage />);
    await screen.findByText("Admin workbench");
    fireEvent.change(screen.getByLabelText("Order IDs, comma separated"), { target: { value: "o-1, o-2" } });
    fireEvent.click(screen.getByRole("button", { name: "Run bulk action" }));
    const bulk = await screen.findByRole("dialog", { name: "Run bulk order action" });
    fireEvent.click(within(bulk).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/bulk-orders",
      expect.objectContaining({ ids: ["o-1", "o-2"], to: "cancelled" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Queue CSV export" }));
    const exportDialog = await screen.findByRole("dialog", { name: "Queue CSV export" });
    fireEvent.click(within(exportDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/exports",
      expect.objectContaining({ dataset: "orders", format: "csv" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Export ID"), { target: { value: "exp-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Refresh status" }));
    expect(await screen.findByText(/"state": "completed"/)).toBeVisible();
  });

  it("creates workflows and requests approvals with JSON validation", async () => {
    renderWithProviders(<AdminWorkbenchPage />);
    await screen.findByText("Admin workbench");
    fireEvent.change(screen.getByLabelText("Workflow name"), { target: { value: "Refund review" } });
    fireEvent.click(screen.getByRole("button", { name: "Create owner approval workflow" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/workflows",
      expect.objectContaining({ name: "Refund review", resource: "orders", action: "refund" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Workflow ID"), { target: { value: "w-1" } });
    fireEvent.change(screen.getByLabelText("Resource ID"), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText(/Payload JSON/), { target: { value: "{bad" } });
    fireEvent.click(screen.getByRole("button", { name: "Request approval" }));
    expect(await screen.findByText("Payload must be valid JSON.")).toBeVisible();
    fireEvent.change(screen.getByLabelText(/Payload JSON/), { target: { value: "{\"amount_minor\":1000}" } });
    fireEvent.click(screen.getByRole("button", { name: "Request approval" }));
    const request = await screen.findByRole("dialog", { name: "Request approval" });
    fireEvent.click(within(request).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/approvals",
      expect.objectContaining({ workflow_id: "w-1", resource_id: "o-1", payload: { amount_minor: 1000 } }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Approval ID"), { target: { value: "a-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Submit decision" }));
    const decision = await screen.findByRole("dialog", { name: "Approve approval" });
    fireEvent.click(within(decision).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/approvals/a-1/decision",
      { decision: "approve" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("saves role permissions for the owner and shows retry when loading fails", async () => {
    const { unmount } = renderWithProviders(<AdminWorkbenchPage />);
    await screen.findByText("Admin workbench");
    const capability = screen.getByRole("checkbox", { name: "orders" });
    fireEvent.click(capability);
    fireEvent.click(screen.getByRole("button", { name: "Save permissions" }));
    const permissionsDialog = await screen.findByRole("dialog", { name: "Save role permissions" });
    fireEvent.click(within(permissionsDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/v2/admin/workbench/permissions/viewer",
      { permissions: ["orders"] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    unmount();
    apiMock.get.mockRejectedValueOnce(new Error("down"));
    renderWithProviders(<AdminWorkbenchPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(screen.getByRole("button", { name: "Retry" })).toBeVisible();
  });
});

describe("users directory page", () => {
  const user = { id: 1, name: "Ada Admin", email: "ada@example.test", role: "admin", status: "active", created_at: "2026-09-23T10:00:00Z" };
  const meta = { page: 1, per_page: 10, total: 1, last_page: 1 };

  beforeEach(() => {
    apiMock.get.mockImplementation((path: string) => path === "/api/users" ? Promise.resolve({ data: { data: [user], meta }, status: 200, headers: new Headers() }) : body({}));
    apiMock.post.mockResolvedValue(body(user));
    apiMock.delete.mockResolvedValue({ data: { data: null }, status: 200, headers: new Headers() });
    Object.assign(URL, { createObjectURL: vi.fn(() => "blob:test"), revokeObjectURL: vi.fn() });
  });

  it("renders users and creates a user from the dialog", async () => {
    renderWithProviders(<UsersPage />);
    expect(await screen.findByText("Ada Admin")).toBeVisible();
    expect(screen.getByText("ada@example.test")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: /Create User/ }));
    const dialog = await screen.findByRole("dialog");
    fireEvent.change(within(dialog).getByLabelText(/^Name/, { selector: "input" }), { target: { value: "New Member" } });
    fireEvent.change(within(dialog).getByLabelText(/^Email/, { selector: "input" }), { target: { value: "member@example.test" } });
    fireEvent.change(within(dialog).getByLabelText(/^Password/, { selector: "input" }), { target: { value: "Password123!" } });
    fireEvent.change(within(dialog).getByLabelText(/^Confirm/, { selector: "input" }), { target: { value: "Password123!" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/users",
      expect.objectContaining({ name: "New Member", email: "member@example.test" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("exports the directory and bulk deletes selected users", async () => {
    renderWithProviders(<UsersPage />);
    await screen.findByText("Ada Admin");
    fireEvent.click(screen.getByRole("button", { name: /Export/ }));
    expect(URL.createObjectURL).toHaveBeenCalled();
    fireEvent.click(screen.getByRole("checkbox", { name: "Select row" }));
    fireEvent.click(await screen.findByRole("button", { name: /Delete \(1\)/ }));
    const dialog = await screen.findByRole("alertdialog");
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/users/1", expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("renders the empty directory state", async () => {
    apiMock.get.mockResolvedValueOnce(Promise.resolve({ data: { data: [], meta: { ...meta, total: 0 } }, status: 200, headers: new Headers() }));
    renderWithProviders(<UsersPage />);
    expect(await screen.findByText("No data available")).toBeVisible();
  });
});
