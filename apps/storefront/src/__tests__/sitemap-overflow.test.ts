import { afterEach, describe, expect, it, vi } from "vitest";
import { buildSitemap } from "@/app/sitemap";

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function batch(page: number, size: number) {
  return Array.from({ length: size }, (_, index) => ({
    id: `${page}-${index}`,
    name: `Product ${page}/${index}`,
    slug: `product-${page}-${index}`,
    status: "active",
    category: "home",
    price: "12.00",
    currency: "USD",
  }));
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("sitemap overflow", () => {
  it("pages through the catalog, de-duplicates slugs and stops at the product cap", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_URL", "https://shop.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "api");
    vi.stubEnv("STOREFRONT_CMS_SLUGS", "");
    const productFetches: number[] = [];
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      const url = new URL(String(input));
      if (url.pathname === "/api/products") {
        const page = Number(url.searchParams.get("page") ?? "1");
        productFetches.push(page);
        // Page 1 and 2 repeat the same 100 slugs; pages 3..50 are unique.
        return json({
          success: true,
          message: "ok",
          data: batch(page <= 2 ? 1 : page, 100),
          meta: { page, per_page: 100, total: 5100, last_page: 51 },
        });
      }
      return json({ success: false, message: "not found", data: null }, 404);
    });
    vi.stubGlobal("fetch", transport);

    const entries = await buildSitemap();
    const productUrls = entries.map((entry) => entry.url).filter((url) => url.includes("/product/"));
    const uniqueUrls = new Set(productUrls);

    expect(productFetches).toHaveLength(50);
    expect(productFetches[0]).toBe(1);
    expect(productFetches.at(-1)).toBe(50);
    expect(productUrls).toHaveLength(uniqueUrls.size);
    expect(productUrls).toHaveLength(4900);
  });
});
