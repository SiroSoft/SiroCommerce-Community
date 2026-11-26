import { afterEach, describe, expect, it, vi } from "vitest";
import { buildSitemap } from "@/app/sitemap";

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("storefront sitemap", () => {
  it("includes home, categories, products and published CMS pages", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_URL", "https://shop.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "store");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "api");
    vi.stubEnv("STOREFRONT_CMS_SLUGS", "about");
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      const url = new URL(String(input));
      if (url.pathname === "/api/products") {
        return json({
          success: true,
          message: "ok",
          data: [{ id: 7, name: "API mug", slug: "api-mug", status: "active", category: "home", price: "12.00", currency: "USD" }],
          meta: { page: 1, per_page: 100, total: 1, last_page: 1 },
        });
      }
      if (url.pathname === "/api/pages/about") {
        return json({ success: true, message: "Page", data: { slug: "about", title: "About", status: "published", updated_at: "2026-11-01T00:00:00Z", blocks: [] } });
      }
      if (url.pathname === "/api/menus/primary") {
        return json({ success: true, message: "Menu", data: { items: [] } });
      }
      return json({ success: false, message: "not found", data: null }, 404);
    });
    vi.stubGlobal("fetch", transport);

    const entries = await buildSitemap();
    const urls = entries.map((entry) => entry.url);
    expect(urls).toContain("https://shop.example.test/");
    expect(urls).toContain("https://shop.example.test/category/apparel");
    expect(urls).toContain("https://shop.example.test/product/api-mug");
    expect(urls).toContain("https://shop.example.test/pages/about");
    expect(entries.find((entry) => entry.url.endsWith("/pages/about"))?.lastModified).toBeInstanceOf(Date);
  });

  it("falls back to home and static category pages when the catalog API fails", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_URL", "https://shop.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "api");
    vi.stubEnv("STOREFRONT_CMS_SLUGS", "about");
    vi.stubGlobal("fetch", vi.fn(async () => { throw new Error("network down"); }));

    const entries = await buildSitemap();
    const urls = entries.map((entry) => entry.url);
    expect(urls).toContain("https://shop.example.test/");
    expect(urls).toContain("https://shop.example.test/search");
    expect(urls).toContain("https://shop.example.test/category/apparel");
    expect(urls.some((url) => url.includes("/product/"))).toBe(false);
  });
});
