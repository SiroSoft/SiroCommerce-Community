import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import ThemeCatalogPage from "@/app/(dashboard)/storefront/themes/page";
import { Sidebar } from "@/layouts/sidebar";
import { MobileSidebar } from "@/layouts/mobile-sidebar";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import {
  PRO_THEME_LOCK_REASON,
  storefrontThemeService,
  themeCatalogAvailability,
  type EcosystemCatalogItem,
  type ThemeCatalogEdition,
} from "@/services/storefront-theme.service";

const { api } = vi.hoisted(() => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/services/api", () => ({
  default: api,
  fetchWithAuth: vi.fn(),
}));

const authState = vi.hoisted(() => ({
  user: { role: "admin" } as { role?: string; permissions?: string[] } | null,
  logout: vi.fn(),
}));

vi.mock("@/hooks/use-auth", () => ({
  useAuth: () => ({ user: authState.user, logout: authState.logout }),
}));

vi.mock("@/services/extensions.service", () => ({
  extensionsService: { views: vi.fn().mockResolvedValue([]) },
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/",
}));

vi.mock("next/link", () => ({
  default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a>,
}));

type CatalogItem = EcosystemCatalogItem;

const catalogResponse = (items: CatalogItem[]) => ({
  data: { data: { schema: "siro.ecosystem-catalog/1", generated_at: "2026-11-24T00:00:00Z", items } },
});

const updatesResponse = (items: CatalogItem[]) => ({ data: { data: { items } } });

function item(overrides: Partial<CatalogItem> = {}): CatalogItem {
  return {
    id: "siro/aurora",
    type: "theme",
    name: "Aurora",
    description: "Editorial theme for mixed catalogs.",
    vendor: "Siro",
    edition: "free",
    status: "published",
    latest_version: "1.0.0",
    installed_version: null,
    installed_state: "available",
    update_available: false,
    compatible: true,
    min_core: "^1.0",
    min_commerce: "^0.1",
    license_required: false,
    license_sku: null,
    license_status: "not_required",
    artifact_url: null,
    sha256: null,
    preview_url: "/themes/aurora/preview",
    changelog_url: "https://example.com/changelog",
    changelog: "Initial theme release.",
    tags: ["editorial"],
    ...overrides,
  };
}

function renderPage() {
  return render(<I18nProvider><ThemeCatalogPage /></I18nProvider>);
}

beforeEach(() => {
  api.get.mockReset();
  authState.user = { role: "admin" };
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true, isLoading: false });
});

afterEach(() => {
  cleanup();
  useAuthStore.setState({ user: null, isAuthenticated: false, isLoading: false });
});

describe("storefront theme catalog service", () => {
  it("sends an allowlisted theme query and unwraps the catalog envelope", async () => {
    api.get.mockResolvedValue(catalogResponse([item()]));
    const result = await storefrontThemeService.catalog({ edition: "pro", q: "  aurora  " });
    expect(api.get).toHaveBeenCalledWith("/api/admin/ecosystem/catalog", {
      params: { type: "theme", edition: "pro", q: "aurora" },
    });
    expect(result.items).toHaveLength(1);
  });

  it("defaults invalid editions to all and drops blank search terms", async () => {
    api.get.mockResolvedValue(catalogResponse([]));
    await storefrontThemeService.catalog({ edition: "invalid" as ThemeCatalogEdition, q: "   " });
    expect(api.get).toHaveBeenCalledWith("/api/admin/ecosystem/catalog", {
      params: { type: "theme", edition: "all" },
    });
  });

  it("reads the updates envelope", async () => {
    api.get.mockResolvedValue(updatesResponse([item({ update_available: true })]));
    const result = await storefrontThemeService.updates();
    expect(api.get).toHaveBeenCalledWith("/api/admin/ecosystem/updates");
    expect(result.items[0].update_available).toBe(true);
  });

  it("only opens published free themes and locks Pro or planned packages", () => {
    expect(themeCatalogAvailability({ edition: "free", status: "published" })).toEqual({ openable: true, reason: null });
    expect(themeCatalogAvailability({ edition: "pro", status: "published" })).toEqual({ openable: false, reason: PRO_THEME_LOCK_REASON });
    expect(themeCatalogAvailability({ edition: "pro", status: "planned" })).toEqual({ openable: false, reason: PRO_THEME_LOCK_REASON });
    expect(themeCatalogAvailability({ edition: "free", status: "planned" })).toEqual({ openable: false, reason: PRO_THEME_LOCK_REASON });
    expect(themeCatalogAvailability({ edition: "free", status: "deprecated" }).openable).toBe(false);
  });

  it("opens published Pro themes only with a valid or in-grace license", () => {
    const base = { edition: "pro" as const, status: "published", license_required: true, license_sku: "siro-theme-atelier-noir" };
    expect(themeCatalogAvailability({ ...base, license_status: "valid" })).toEqual({ openable: true, reason: null });
    expect(themeCatalogAvailability({ ...base, license_status: "grace" })).toEqual({ openable: true, reason: null });
    expect(themeCatalogAvailability({ ...base, license_status: "missing" })).toEqual({
      openable: false,
      reason: "Pro license required. Activate a valid siro-theme-atelier-noir license to import or publish this theme.",
    });
    expect(themeCatalogAvailability({ ...base, license_status: "expired" }).openable).toBe(false);
    expect(themeCatalogAvailability({ ...base, status: "planned", license_status: "valid" }).openable).toBe(false);
  });
});

describe("theme catalog page", () => {
  it("shows a loading state while the catalog request is pending", () => {
    api.get.mockReturnValue(new Promise(() => undefined));
    renderPage();
    expect(screen.getByText("Loading theme catalog...")).toBeInTheDocument();
  });

  it("shows an empty state when no themes match", async () => {
    api.get.mockResolvedValue(catalogResponse([]));
    renderPage();
    expect(await screen.findByText("No theme packages")).toBeInTheDocument();
  });

  it("shows a retryable error state when the catalog request fails", async () => {
    api.get.mockRejectedValue(new Error("Catalog unavailable"));
    renderPage();
    expect(await screen.findByRole("alert")).toHaveTextContent("Unable to load theme catalog");
    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
  });

  it("renders Free/Pro, license, update, version, compatibility, and safe links without HTML", async () => {
    api.get.mockResolvedValue(catalogResponse([
      item({ id: "siro/aurora", name: "Aurora", edition: "free", license_status: "not_required" }),
      item({
        id: "siro/noir",
        name: "Noir",
        edition: "pro",
        latest_version: "2.0.0",
        installed_version: "1.0.0",
        update_available: true,
        compatible: false,
        license_status: "missing",
        preview_url: "javascript:alert(1)",
        description: "<script>alert(1)</script>Noir description",
      }),
    ]));
    const { container } = renderPage();

    expect(await screen.findByText("Aurora")).toBeInTheDocument();
    expect(screen.getByText("Noir")).toBeInTheDocument();
    expect(screen.getAllByText("Free").length).toBeGreaterThan(1);
    expect(screen.getAllByText("Pro").length).toBeGreaterThan(1);
    expect(screen.getByText("License not required")).toBeInTheDocument();
    expect(screen.getByText("License missing")).toBeInTheDocument();
    expect(screen.getByText("Update available")).toBeInTheDocument();
    expect(screen.getByText("Incompatible")).toBeInTheDocument();
    expect(screen.getByText("Latest v2.0.0")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /open theme builder/i })[0]).toHaveAttribute("href", "/storefront/theme");

    const links = screen.getAllByRole("link");
    expect(links.some((link) => link.getAttribute("href")?.startsWith("javascript:"))).toBe(false);
    const previewLinks = screen.getAllByRole("link", { name: /^preview/i });
    expect(previewLinks).toHaveLength(1);
    expect(previewLinks[0]).toHaveAttribute("href", "/themes/aurora/preview");
    expect(screen.getByText("<script>alert(1)</script>Noir description")).toBeInTheDocument();
    expect(container.querySelector("script")).toBeNull();
  });

  it("locks the two premium planned themes with license reasons and changelog but no import link", async () => {
    const atelierChangelog = "Planned Pro fashion theme; signed artifact and license delivery pending.";
    const apothecaryChangelog = "Planned Pro pharmacy theme; signed artifact and license delivery pending.";
    api.get.mockResolvedValue(catalogResponse([
      item({
        id: "siro/atelier-noir",
        name: "Atelier Noir",
        edition: "pro",
        status: "planned",
        license_required: true,
        license_sku: "siro-theme-atelier-noir",
        license_status: "missing",
        preview_url: "/media/theme-previews/fashion.jpg",
        changelog_url: "javascript:alert(1)",
        changelog: atelierChangelog,
      }),
      item({
        id: "siro/apothecary-calm",
        name: "Apothecary Calm",
        edition: "pro",
        status: "planned",
        license_required: true,
        license_sku: "siro-theme-apothecary-calm",
        license_status: "missing",
        preview_url: "/media/theme-previews/pharmacy.jpg",
        changelog_url: "https://example.com/apothecary-changelog",
        changelog: apothecaryChangelog,
      }),
    ]));
    const { container } = renderPage();

    expect(await screen.findByText("Atelier Noir")).toBeInTheDocument();
    expect(screen.getByText("Apothecary Calm")).toBeInTheDocument();
    expect(screen.getAllByText("Pro").length).toBeGreaterThanOrEqual(3);
    expect(screen.getAllByText("Planned")).toHaveLength(2);
    expect(screen.getAllByText("License missing")).toHaveLength(2);
    expect(screen.getByText(atelierChangelog)).toBeInTheDocument();
    expect(screen.getByText(apothecaryChangelog)).toBeInTheDocument();
    expect(screen.getAllByText(PRO_THEME_LOCK_REASON)).toHaveLength(2);

    expect(screen.queryByRole("link", { name: /open theme builder/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /^import/i })).not.toBeInTheDocument();
    const importButtons = screen.getAllByRole("button", { name: /^import$/i });
    expect(importButtons).toHaveLength(2);
    for (const button of importButtons) expect(button).toBeDisabled();

    const previewLinks = screen.getAllByRole("link", { name: /^preview/i });
    expect(previewLinks).toHaveLength(2);
    expect(previewLinks[0]).toHaveAttribute("href", "/media/theme-previews/fashion.jpg");
    expect(previewLinks[1]).toHaveAttribute("href", "/media/theme-previews/pharmacy.jpg");
    expect(screen.getAllByRole("link", { name: /^changelog/i })).toHaveLength(1);
    const links = Array.from(container.querySelectorAll("a"));
    expect(links.some((link) => link.getAttribute("href")?.startsWith("javascript:"))).toBe(false);
    expect(container.querySelector("script")).toBeNull();
  });

  it("enables Pro import only when the license is valid and shows the SKU when locked", async () => {
    const valid = item({
      id: "siro/atelier-noir",
      name: "Atelier Noir",
      edition: "pro",
      status: "published",
      license_required: true,
      license_sku: "siro-theme-atelier-noir",
      license_status: "valid",
    });
    const locked = item({
      id: "siro/apothecary-calm",
      name: "Apothecary Calm",
      edition: "pro",
      status: "published",
      license_required: true,
      license_sku: "siro-theme-apothecary-calm",
      license_status: "expired",
    });
    api.get.mockResolvedValue(catalogResponse([valid, locked]));
    renderPage();

    expect(await screen.findByText("Atelier Noir")).toBeInTheDocument();
    expect(screen.getByText("Apothecary Calm")).toBeInTheDocument();
    expect(screen.getAllByText("License required")).toHaveLength(2);
    expect(screen.getByText(/License SKU: siro-theme-atelier-noir/)).toBeInTheDocument();
    expect(screen.getByText(/License SKU: siro-theme-apothecary-calm/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /open theme builder/i })).toHaveAttribute("href", "/storefront/theme");
    const importButtons = screen.getAllByRole("button", { name: /^import$/i });
    expect(importButtons).toHaveLength(1);
    expect(importButtons[0]).toBeDisabled();
    expect(screen.getByText(/Pro license required. Activate a valid siro-theme-apothecary-calm license/i)).toBeInTheDocument();
  });

  it("keeps published free themes openable without a Pro lock reason", async () => {
    api.get.mockResolvedValue(catalogResponse([
      item({ id: "siro/fashion", name: "Fashion", edition: "free", status: "published" }),
    ]));
    renderPage();

    expect(await screen.findByText("Fashion")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /open theme builder/i })).toHaveAttribute("href", "/storefront/theme");
    expect(screen.queryByText(PRO_THEME_LOCK_REASON)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^import$/i })).not.toBeInTheDocument();
  });

  it("fetches with the selected edition and trimmed search term", async () => {
    api.get.mockResolvedValue(catalogResponse([item()]));
    renderPage();
    await screen.findByText("Aurora");

    fireEvent.click(screen.getByRole("button", { name: "Free" }));
    await waitFor(() => expect(api.get).toHaveBeenLastCalledWith("/api/admin/ecosystem/catalog", {
      params: { type: "theme", edition: "free" },
    }));

    fireEvent.change(screen.getByRole("textbox", { name: "Search themes" }), { target: { value: "  aurora  " } });
    await waitFor(() => expect(api.get).toHaveBeenLastCalledWith("/api/admin/ecosystem/catalog", {
      params: { type: "theme", edition: "free", q: "aurora" },
    }), { timeout: 2000 });
  });

  it("loads updates only when the Updates tab becomes active and filters to themes", async () => {
    api.get.mockImplementation((path: string) => {
      if (path === "/api/admin/ecosystem/updates") {
        return Promise.resolve(updatesResponse([
          item({ id: "siro/noir", name: "Noir", type: "theme", update_available: true, latest_version: "2.0.0", installed_version: "1.0.0", changelog_url: "https://example.com/noir" }),
          item({ id: "siro/plugin", name: "Plugin update", type: "plugin", update_available: true }),
        ]));
      }
      return Promise.resolve(catalogResponse([]));
    });
    renderPage();
    await screen.findByText("No theme packages");

    expect(api.get.mock.calls.some(([path]) => path === "/api/admin/ecosystem/updates")).toBe(false);
    fireEvent.click(screen.getByRole("tab", { name: "Updates" }));

    const panel = await screen.findByRole("tabpanel");
    expect(within(panel).getByText("Noir")).toBeInTheDocument();
    expect(within(panel).queryByText("Plugin update")).not.toBeInTheDocument();
    expect(within(panel).getByText("Latest v2.0.0")).toBeInTheDocument();
    expect(within(panel).getByRole("link", { name: /changelog/i })).toHaveAttribute("href", "https://example.com/noir");
    expect(screen.getByRole("tab", { name: "Updates" })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("tab", { name: "Catalog" })).toHaveAttribute("aria-selected", "false");
  });

  it("shows an updates empty state when only non-theme updates exist", async () => {
    api.get.mockImplementation((path: string) => {
      if (path === "/api/admin/ecosystem/updates") {
        return Promise.resolve(updatesResponse([item({ id: "siro/plugin", name: "Plugin update", type: "plugin", update_available: true })]));
      }
      return Promise.resolve(catalogResponse([]));
    });
    renderPage();
    await screen.findByText("No theme packages");
    fireEvent.click(screen.getByRole("tab", { name: "Updates" }));
    expect(await screen.findByText("No theme updates")).toBeInTheDocument();
  });
});

describe("theme catalog sidebar entries", () => {
  it("links the desktop sidebar from cms-capable roles and hides it from viewers", () => {
    authState.user = { role: "marketer" };
    const first = render(<I18nProvider><Sidebar collapsed={false} onToggle={() => undefined} /></I18nProvider>);
    expect(screen.getByRole("link", { name: "Theme catalog" })).toHaveAttribute("href", "/storefront/themes");
    first.unmount();

    authState.user = { role: "viewer" };
    render(<I18nProvider><Sidebar collapsed={false} onToggle={() => undefined} /></I18nProvider>);
    expect(screen.queryByRole("link", { name: "Theme catalog" })).not.toBeInTheDocument();
  });

  it("links the mobile sidebar to the theme catalog", () => {
    authState.user = { role: "admin" };
    render(<I18nProvider><MobileSidebar open onClose={() => undefined} /></I18nProvider>);
    expect(screen.getByRole("link", { name: "Theme catalog" })).toHaveAttribute("href", "/storefront/themes");
  });
});
