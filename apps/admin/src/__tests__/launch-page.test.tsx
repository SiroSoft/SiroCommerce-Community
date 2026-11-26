import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import LaunchPage from "@/app/(dashboard)/launch/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const status = {
  ready: false,
  score: 38,
  checks: [
    { key: "store_config", state: "fail", hint: "Publish the storefront theme.", href: "/storefront/theme", weight: 0, detail: "no storefront configuration" },
    { key: "catalog", state: "fail", hint: "Add at least one active product.", href: "/products", weight: 0, detail: "active products: 0" },
    { key: "payments", state: "warn", hint: "Set the credentials for stripe.", href: "/settings", weight: 0.5, detail: "credentials: missing" },
    { key: "shipping", state: "ok", hint: "Native shipping rates are configured.", href: "/settings", weight: 1, detail: "provider: native" },
    { key: "mail", state: "warn", hint: "Set MAIL_* for transactional email.", href: "/settings", weight: 0.5, detail: "silent" },
    { key: "search", state: "ok", hint: "Native SQL search is active.", href: "/search-seo", weight: 1, detail: "provider: native" },
    { key: "legal_pages", state: "fail", hint: "Publish shipping/returns/contact pages.", href: "/pages", weight: 0, detail: "0/3" },
    { key: "domain_tls", state: "fail", hint: "Set APP_URL.", href: "/settings", weight: 0, detail: "missing" },
  ],
} as const;

const providers = {
  payment: { provider: "stripe", mode: "sandbox", configured: false },
  shipping: { provider: "native", mode: "sandbox", configured: true },
  mail: { provider: "smtp", configured: false },
  search: { provider: "native", url_configured: false, configured: true },
  ai: { provider: "unavailable", budget_configured: false, configured: false },
};

const seedResult = {
  created: { theme: 3, categories: 3, products: 6, variants: 6, inventory: 6, prices: 6, pages: 3, menus: 1, menu_items: 4 },
  skipped: { theme: 0, categories: 0, products: 0, variants: 0, inventory: 0, prices: 0, pages: 0, menus: 0, menu_items: 0 },
};

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/launch/status") return ok(status);
    if (path === "/api/admin/providers/status") return ok(providers);
    return ok({});
  });
  apiMock.post.mockResolvedValue(ok(seedResult));
});

afterEach(() => {
  cleanup();
});

describe("launch checklist page", () => {
  it("renders the score, checklist states and provider status", async () => {
    render(<LaunchPage />);

    expect(await screen.findByRole("progressbar", { name: "Launch readiness score" })).toHaveAttribute("aria-valuenow", "38");
    expect(screen.getByText("Add at least one active product.")).toBeVisible();
    expect(await screen.findByText("Providers")).toBeVisible();
    expect(screen.getByText("stripe · sandbox")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/launch/status");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/providers/status");
  });

  it("asks for confirmation and posts demo seed with an idempotency key", async () => {
    render(<LaunchPage />);

    fireEvent.click(await screen.findByRole("button", { name: "Seed demo data" }));
    const dialog = await screen.findByRole("dialog", { name: "Seed demo data" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Seed demo data" }));

    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/launch/seed-demo",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText(/created 38 launch demo records/i)).toBeVisible();
  });

  it("re-runs checks without seeding", async () => {
    render(<LaunchPage />);
    await screen.findByRole("progressbar", { name: "Launch readiness score" });

    fireEvent.click(screen.getByRole("button", { name: "Re-run checks" }));

    await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(4));
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("hides the seed action when the catalog is healthy", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path === "/api/admin/launch/status") return ok({ ...status, score: 100, ready: true, checks: status.checks.map((check) => check.key === "catalog" ? { ...check, state: "ok" } : check) });
      if (path === "/api/admin/providers/status") return ok(providers);
      return ok({});
    });

    render(<LaunchPage />);
    await screen.findByRole("progressbar", { name: "Launch readiness score" });
    expect(screen.queryByRole("button", { name: "Seed demo data" })).not.toBeInTheDocument();
  });
});
