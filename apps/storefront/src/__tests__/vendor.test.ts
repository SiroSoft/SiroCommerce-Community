import { afterEach, describe, expect, it, vi } from "vitest";
import { createApiCatalog } from "@/services/catalog";

function response(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

describe("marketplace lite storefront adapter", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("forwards the vendor slug to the catalog filter", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      expect(String(input)).toContain("/api/products?");
      expect(String(input)).toContain("vendor=north-loom");
      return response({
        success: true,
        message: "ok",
        data: [{
          id: 1,
          name: "Linen scarf",
          slug: "linen-scarf",
          status: "active",
          category: "apparel",
          price: "25.00",
          currency: "USD",
          tags: [],
          vendor: { id: "v-1", name: "North Loom", slug: "north-loom" },
        }],
        meta: { page: 1, per_page: 100, total: 1, last_page: 1 },
      });
    });
    const source = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "store", channel_id: "web", locale: "en", currency: "USD" } });
    const products = await source.listProducts({ vendor: "north-loom" });
    expect(products).toHaveLength(1);
    expect(products[0]?.vendor).toEqual({ id: "v-1", name: "North Loom", slug: "north-loom" });
  });

  it("loads a vendor profile and treats an unknown vendor as missing", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      if (String(input).includes("/api/vendors/north-loom")) {
        return response({ success: true, message: "ok", data: { id: "v-1", name: "North Loom", slug: "north-loom", status: "active", suspended: false, description: "Independent studio", contact_email: "team@acme.test", product_count: 3 }, meta: {} });
      }
      return response({ success: false, message: "Vendor not found", data: null, meta: {} }, 404);
    });
    const source = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "store", channel_id: "web", locale: "en", currency: "USD" } });

    await expect(source.getVendor?.("north-loom")).resolves.toMatchObject({ name: "North Loom", suspended: false, product_count: 3 });
    await expect(source.getVendor?.("missing")).resolves.toBeUndefined();
  });
});
