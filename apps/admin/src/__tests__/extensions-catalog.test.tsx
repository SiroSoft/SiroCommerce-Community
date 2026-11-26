import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import ExtensionsPage from "@/app/(dashboard)/extensions/page";
import type { CatalogItem, ExtensionItem, ExtensionLicenseStatus } from "@/services/extensions.service";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

const installedItem: ExtensionItem = {
  id: "siro/demo",
  version: "1.0.0",
  manifest_version: "1.0.0",
  state: "enabled",
  last_error: null,
  capabilities: [],
  permissions: [],
  migrations: [],
  routes: [],
  requires: {},
  admin_ui: null,
};

const licenseStatus: ExtensionLicenseStatus = {
  ok: true,
  edition: "pro",
  license_id: "lic-1",
  extensions: ["siro/affiliate"],
  features: ["affiliate"],
  expires_at: "2027-01-01",
  grace_until: "2027-01-08",
  reason: "active",
  source: "storage",
  stored: true,
};

const freeItem: CatalogItem = {
  id: "siro/product-qa",
  type: "plugin",
  name: "Product Q&A",
  description: "Customer questions and moderated answers.",
  vendor: "Siro",
  edition: "free",
  status: "published",
  latest_version: "1.0.0",
  installed_version: "1.0.0",
  installed_state: "enabled",
  update_available: false,
  compatible: true,
  min_core: "^1.0",
  min_commerce: "^0.1",
  license_required: false,
  license_sku: null,
  license_status: "not_required",
  artifact_url: null,
  sha256: null,
  preview_url: "https://example.test/preview",
  changelog_url: "https://example.test/changelog",
  changelog: "Maintenance release with search fixes.",
  installable: false,
  can_update: false,
  action_reason: null,
  tags: ["pdp", "support"],
};

const proItem: CatalogItem = {
  ...freeItem,
  id: "siro/affiliate-pro",
  type: "theme",
  name: "Affiliate Pro",
  description: "Commercial affiliate toolkit.",
  edition: "pro",
  latest_version: "2.0.0",
  installed_version: "1.0.0",
  installed_state: "available",
  update_available: true,
  license_required: true,
  license_sku: "SIRO-AFF-PRO",
  license_status: "missing",
  action_reason: "Pro license required. Activate a valid SIRO-AFF-PRO license to update.",
  tags: ["affiliate"],
};

const installableItem: CatalogItem = {
  ...freeItem,
  id: "siro/contact-widget",
  name: "Contact widget",
  description: "Contact form widget.",
  installed_version: null,
  installed_state: "available",
  changelog: "<b>New</b> contact channels and safer defaults.",
  installable: true,
  can_update: false,
  action_reason: null,
  tags: ["contact"],
};

const updatableItem: CatalogItem = {
  ...freeItem,
  latest_version: "1.1.0",
  update_available: true,
  changelog: "Fixes moderation and answer ordering.",
  installable: false,
  can_update: true,
  action_reason: null,
};

function catalogBody(items: CatalogItem[]) {
  return body({ schema: "siro.ecosystem-catalog/1", generated_at: "2026-11-24T00:00:00Z", items });
}

function installApi(options: {
  extensions?: ExtensionItem[];
  license?: ExtensionLicenseStatus;
  catalog?: () => Promise<unknown>;
  updates?: () => Promise<unknown>;
  proFeed?: () => Promise<unknown>;
} = {}) {
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/extensions") return body(options.extensions ?? [installedItem]);
    if (path === "/api/admin/extensions/license") return body(options.license ?? licenseStatus);
    if (path === "/api/admin/ecosystem/catalog") return options.catalog ? options.catalog() : catalogBody([]);
    if (path === "/api/admin/ecosystem/updates") return options.updates ? options.updates() : body({ items: [] });
    if (path === "/api/admin/ecosystem/pro-feed") return options.proFeed ? options.proFeed() : body({ count: 0, items: [] });
    return body({});
  });
}

function renderPage() {
  return render(<I18nProvider><ExtensionsPage /></I18nProvider>);
}

const calledPaths = () => apiMock.get.mock.calls.map((call) => call[0]);

beforeEach(() => {
  vi.clearAllMocks();
  apiMock.post.mockResolvedValue({ data: { data: {} }, status: 200, headers: new Headers() });
  apiMock.delete.mockResolvedValue({ data: { data: {} }, status: 200, headers: new Headers() });
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("extensions tabs", () => {
  it("loads updates once for the availability notice while keeping the catalog lazy", async () => {
    installApi({ updates: () => body({ items: [updatableItem, { ...updatableItem, id: "siro/another", name: "Another" }] }) });
    renderPage();

    expect(await screen.findByText("siro/demo")).toBeVisible();
    expect(await screen.findByText(/2 updates available/)).toBeVisible();
    expect(screen.getByRole("heading", { name: "Install package" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Install" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Enable" })).toBeVisible();
    expect(screen.getByLabelText("Extension package zip")).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Installed" })).toHaveAttribute("aria-selected", "true");

    expect(calledPaths().filter((path) => path === "/api/admin/ecosystem/catalog")).toHaveLength(0);
    expect(calledPaths().filter((path) => path === "/api/admin/ecosystem/updates")).toHaveLength(1);

    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/ecosystem/catalog", { params: { type: "all", edition: "all" } }));
    expect(screen.getByRole("tab", { name: "Catalog" })).toHaveAttribute("aria-selected", "true");
    expect(calledPaths().filter((path) => path === "/api/admin/ecosystem/updates")).toHaveLength(1);

    fireEvent.click(screen.getByRole("tab", { name: "Updates" }));
    await waitFor(() => expect(calledPaths().filter((path) => path === "/api/admin/ecosystem/updates")).toHaveLength(2));
  });

  it("shows catalog loading, empty and error states", async () => {
    installApi({ catalog: () => new Promise(() => {}) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));
    expect(await screen.findByText(/Loading catalog/)).toBeVisible();
    cleanup();

    installApi({ catalog: () => catalogBody([]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));
    expect(await screen.findByText(/No catalog items match these filters/)).toBeVisible();
    cleanup();

    installApi({ catalog: () => Promise.reject(new Error("catalog down")) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/Something went wrong/);
    expect(screen.getByRole("button", { name: "Try again" })).toBeVisible();
  });

  it("passes allowlisted type, edition and search filters to the catalog endpoint", async () => {
    installApi({ catalog: () => catalogBody([freeItem, proItem]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenLastCalledWith("/api/admin/ecosystem/catalog", { params: { type: "all", edition: "all" } }));

    fireEvent.change(screen.getByLabelText("Catalog type"), { target: { value: "theme" } });
    await waitFor(() => expect(apiMock.get).toHaveBeenLastCalledWith("/api/admin/ecosystem/catalog", { params: { type: "theme", edition: "all" } }));

    fireEvent.change(screen.getByLabelText("Catalog edition"), { target: { value: "pro" } });
    await waitFor(() => expect(apiMock.get).toHaveBeenLastCalledWith("/api/admin/ecosystem/catalog", { params: { type: "theme", edition: "pro" } }));

    fireEvent.change(screen.getByLabelText("Catalog search"), { target: { value: "qa" } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenLastCalledWith("/api/admin/ecosystem/catalog", { params: { type: "theme", edition: "pro", q: "qa" } }));
  });

  it("renders Free/Pro, update, compatibility and license badges with a Pro license warning", async () => {
    installApi({ catalog: () => catalogBody([freeItem, proItem]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));

    expect(await screen.findByText("Product Q&A")).toBeVisible();
    expect(screen.getByText("Affiliate Pro")).toBeVisible();
    expect(screen.getByText("Free")).toBeVisible();
    expect(screen.getByText("Pro")).toBeVisible();
    expect(screen.getByText("Update available")).toBeVisible();
    expect(screen.getAllByText("License not required").length).toBeGreaterThan(0);
    expect(screen.getAllByText("License missing").length).toBeGreaterThan(0);
    expect(screen.getByText(/latest v2\.0\.0 · installed v1\.0\.0/)).toBeVisible();
    expect(screen.getByRole("alert")).toHaveTextContent(/Pro license required/);
    expect(screen.getAllByRole("link", { name: "Preview" })[0]).toHaveAttribute("href", "https://example.test/preview");
    expect(screen.getAllByRole("link", { name: "Changelog" })[0]).toHaveAttribute("href", "https://example.test/changelog");
    expect(screen.queryByRole("button", { name: /install|enable|upgrade/i })).toBeNull();
  });

  it("lists only update_available items in Updates and shows an empty state", async () => {
    installApi({ updates: () => body({ items: [proItem, freeItem] }) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Updates" }));
    expect(await screen.findByText("Affiliate Pro")).toBeVisible();
    expect(screen.queryByText("Product Q&A")).toBeNull();
    expect(screen.getByText(/v1\.0\.0 → v2\.0\.0/)).toBeVisible();
    expect(screen.getByRole("alert")).toHaveTextContent(/Pro license required/);
    cleanup();

    installApi({ updates: () => body({ items: [] }) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Updates" }));
    expect(await screen.findByText(/All installed extensions and themes are up to date/)).toBeVisible();
  });

  it("shows license edition, expiry, grace, source and SKU status", async () => {
    installApi({ catalog: () => catalogBody([{ ...proItem, license_status: "grace" }]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Licenses" }));

    expect(await screen.findByText("SKU status")).toBeVisible();
    expect(screen.getByText("Expires: 2027-01-01 · Grace until: 2027-01-08")).toBeVisible();
    expect(await screen.findByText("SIRO-AFF-PRO · Pro")).toBeVisible();
    expect(screen.getByText("License in grace")).toBeVisible();
    expect(screen.getByText("Source: storage (stored file) · Stored: yes")).toBeVisible();
    expect(screen.getByRole("button", { name: "Import license" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Remove license" })).toBeEnabled();
    expect(screen.queryByText("Managed by environment")).toBeNull();
    expect(screen.queryByRole("button", { name: /purchase|buy/i })).toBeNull();
  });
});

describe("catalog actions", () => {
  it("installs an installable free item from the catalog endpoint with an idempotency key and refreshes data", async () => {
    let catalogCalls = 0;
    installApi({
      catalog: () => { catalogCalls += 1; return catalogBody([installableItem]); },
      updates: () => body({ items: [updatableItem] }),
    });
    renderPage();
    await screen.findByText("siro/demo");
    expect(await screen.findByText(/1 update available/)).toBeVisible();
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));

    expect(await screen.findByText("Contact widget")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Install" }));

    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/ecosystem/install",
      { id: "siro/contact-widget" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/installed from catalog/)).toBeVisible();

    await waitFor(() => expect(catalogCalls).toBeGreaterThanOrEqual(2));
    await waitFor(() => expect(calledPaths().filter((path) => path === "/api/admin/ecosystem/updates").length).toBeGreaterThanOrEqual(2));
    await waitFor(() => expect(calledPaths().filter((path) => path === "/api/admin/extensions").length).toBeGreaterThanOrEqual(2));
    expect(apiMock.post.mock.calls.some(([path]) => path === "/api/admin/extensions/enable")).toBe(false);
  });

  it("updates an item from the catalog card only when can_update", async () => {
    installApi({ catalog: () => catalogBody([updatableItem, proItem]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));

    expect(await screen.findByText("Product Q&A")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Update" }));

    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/ecosystem/update",
      { id: "siro/product-qa" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/updated to the latest version/)).toBeVisible();
    expect(screen.queryByRole("button", { name: "Install" })).toBeNull();
  });

  it("updates from the Updates card when can_update", async () => {
    installApi({ updates: () => body({ items: [updatableItem, proItem] }) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Updates" }));

    expect(await screen.findByText("Product Q&A")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Update" }));

    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/ecosystem/update",
      { id: "siro/product-qa" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("never offers install or update for Pro items and shows the action reason", async () => {
    const blockedPro: CatalogItem = {
      ...proItem,
      installable: true,
      can_update: true,
      action_reason: "Entitlement check failed: license missing.",
    };
    installApi({ catalog: () => catalogBody([blockedPro]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));

    expect(await screen.findByText("Affiliate Pro")).toBeVisible();
    expect(screen.getByText("Entitlement check failed: license missing.")).toBeVisible();
    expect(screen.queryByRole("button", { name: "Install" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Update" })).toBeNull();
  });

  it("renders changelog and description as plain text, never HTML", async () => {
    const unsafe: CatalogItem = {
      ...freeItem,
      changelog: "<img src=x onerror=alert(1)>Security fixes",
      description: "<script>bad()</script>Safe description",
    };
    installApi({ catalog: () => catalogBody([unsafe]) });
    const { container } = renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));

    expect(await screen.findByText("<img src=x onerror=alert(1)>Security fixes")).toBeVisible();
    expect(screen.getByText("<script>bad()</script>Safe description")).toBeVisible();
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("script")).toBeNull();
  });

  it("surfaces catalog action errors as an alert notice", async () => {
    apiMock.post.mockRejectedValue(new Error("install blocked"));
    installApi({ catalog: () => catalogBody([installableItem]) });
    renderPage();
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Catalog" }));

    fireEvent.click(await screen.findByRole("button", { name: "Install" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("install blocked");
  });
});

describe("license portal", () => {
  const openLicenses = async () => {
    await screen.findByText("siro/demo");
    fireEvent.click(screen.getByRole("tab", { name: "Licenses" }));
  };

  it("shows source none and storage without an environment override", async () => {
    renderPage();
    await openLicenses();
    expect(await screen.findByText("Source: storage (stored file) · Stored: yes")).toBeVisible();
    expect(screen.queryByText("Managed by environment")).toBeNull();
    cleanup();

    installApi({ license: { ...licenseStatus, source: "none", stored: false, edition: "community", reason: "community" } });
    renderPage();
    await openLicenses();
    expect(await screen.findByText("Source: none (none) · Stored: no")).toBeVisible();
    expect(screen.getByRole("button", { name: "Remove license" })).toBeDisabled();
  });

  it("marks an environment-managed license and disables remove", async () => {
    installApi({ license: { ...licenseStatus, source: "env", stored: false } });
    renderPage();
    await openLicenses();

    expect(await screen.findByText("Managed by environment")).toBeVisible();
    expect(screen.getByText(/supplied by EXTENSION_LICENSE_FILE/)).toBeVisible();
    expect(screen.getByText("Source: env (environment) · Stored: no")).toBeVisible();
    expect(screen.getByRole("button", { name: "Remove license" })).toBeDisabled();
  });

  it("parses a valid license, posts the object with an idempotency key, clears the input and refreshes", async () => {
    installApi({ catalog: () => catalogBody([{ ...proItem, license_status: "valid" }]) });
    renderPage();
    await openLicenses();

    const raw = JSON.stringify({ payload: { license_id: "lic-9", edition: "pro" }, signature: "SECRET-SIGNATURE" });
    fireEvent.change(await screen.findByLabelText("License JSON"), { target: { value: raw } });
    fireEvent.click(screen.getByRole("button", { name: "Import license" }));

    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/extensions/license/import",
      { license: { payload: { license_id: "lic-9", edition: "pro" }, signature: "SECRET-SIGNATURE" } },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/License imported/)).toBeVisible();
    await waitFor(() => expect(screen.getByLabelText("License JSON")).toHaveValue(""));
    expect(screen.queryByText(/SECRET-SIGNATURE/)).toBeNull();
    expect(screen.queryByDisplayValue(/SECRET-SIGNATURE/)).toBeNull();
    await waitFor(() => expect(calledPaths().filter((path) => path === "/api/admin/extensions/license").length).toBeGreaterThanOrEqual(2));
    await waitFor(() => expect(calledPaths().filter((path) => path === "/api/admin/ecosystem/catalog").length).toBeGreaterThanOrEqual(2));
  });

  it("rejects invalid JSON on the client without calling the API", async () => {
    installApi();
    renderPage();
    await openLicenses();

    fireEvent.change(await screen.findByLabelText("License JSON"), { target: { value: "{not-json" } });
    fireEvent.click(screen.getByRole("button", { name: "Import license" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/must be valid JSON/);
    expect(apiMock.post.mock.calls.some(([path]) => path === "/api/admin/extensions/license/import")).toBe(false);
  });

  it("shows a friendly error when the server rejects a license with 422", async () => {
    apiMock.post.mockRejectedValue({ status: 422, message: "Invalid license", errors: { license: ["Signature does not verify"] } });
    installApi();
    renderPage();
    await openLicenses();

    fireEvent.change(await screen.findByLabelText("License JSON"), { target: { value: JSON.stringify({ payload: {}, signature: "x" }) } });
    fireEvent.click(screen.getByRole("button", { name: "Import license" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/valid signed license document/);
    expect(screen.getByRole("alert")).toHaveTextContent(/Signature does not verify/);
    expect(screen.getByLabelText("License JSON")).not.toHaveValue("");
  });

  it("confirms removal before deleting the stored license and refreshes", async () => {
    installApi();
    renderPage();
    await openLicenses();

    fireEvent.click(await screen.findByRole("button", { name: "Remove license" }));
    expect(await screen.findByRole("dialog")).toBeVisible();
    expect(screen.getByText(/Remove the stored license/)).toBeVisible();
    expect(apiMock.delete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith(
      "/api/admin/extensions/license",
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("License removed.")).toBeVisible();
    await waitFor(() => expect(calledPaths().filter((path) => path === "/api/admin/extensions/license").length).toBeGreaterThanOrEqual(2));
  });

  it("shows a 403 Pro feed message and a metadata-only feed when licensed", async () => {
    installApi({ proFeed: () => Promise.reject({ status: 403, message: "Forbidden" }) });
    renderPage();
    await openLicenses();
    expect(await screen.findByText("Pro feed requires a valid license.")).toBeVisible();
    cleanup();

    installApi({
      proFeed: () => body({
        schema: "siro.pro-feed/1",
        generated_at: "2026-11-24T00:00:00Z",
        count: 2,
        items: [
          { id: "siro/affiliate", name: "Affiliate", artifact_url: "https://private.test/affiliate.zip" },
          { id: "siro/analytics", name: "Analytics", artifact_url: "https://private.test/analytics.zip" },
        ],
      }),
    });
    renderPage();
    await openLicenses();
    expect(await screen.findByText(/Pro feed: 2 items/)).toBeVisible();
    expect(screen.getByText("Schema: siro.pro-feed/1")).toBeVisible();
    expect(screen.getByText(/generated 2026-11-24T00:00:00Z/)).toBeVisible();
    expect(screen.queryByText(/affiliate\.zip|analytics\.zip|private\.test/)).toBeNull();
  });
});
