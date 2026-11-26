import { afterEach, describe, expect, it, vi } from "vitest";
import { CatalogRequestError, catalog, createApiCatalog, products } from "@/services/catalog";

function response(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

describe("generated catalog adapter", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });

  it("uses the API adapter in production mode and does not expose fixture products", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "api");
    const source = catalog();
    expect(source).not.toBeUndefined();
    expect(source).not.toBe((await import("@/services/catalog")).fixtureCatalog);
    expect(products.some((product) => product.name === "Siro dao")).toBe(false);
  });
  it("uses public production env values for the API URL and complete scope", async () => {
    vi.stubEnv("STOREFRONT_CATALOG_SOURCE", "api");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://production-api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "production-store");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "production-web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "vi-VN");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "VND");
    vi.stubEnv("STOREFRONT_API_BASE_URL", "");
    vi.stubEnv("STOREFRONT_STORE_ID", "");
    vi.stubEnv("STOREFRONT_CHANNEL_ID", "");
    vi.stubEnv("STOREFRONT_LOCALE", "");
    vi.stubEnv("STOREFRONT_CURRENCY", "");
    const transport = vi.fn(async (input: URL | RequestInfo, init?: RequestInit) => {
      expect(String(input)).toBe("https://production-api.example.test/api/products?page=1&per_page=100&locale=vi-VN&currency=VND");
      const headers = new Headers(init?.headers);
      expect(headers.get("X-Store-Id")).toBe("production-store");
      expect(headers.get("X-Channel-Id")).toBe("production-web");
      expect(headers.get("X-Locale")).toBe("vi-VN");
      expect(headers.get("X-Currency")).toBe("VND");
      return response({ success: true, message: "ok", data: [], meta: {} });
    });
    vi.stubGlobal("fetch", transport);

    await expect(catalog().listProducts()).resolves.toEqual([]);
    expect(transport).toHaveBeenCalledOnce();
  });
  it("uses the shared client boundary and maps API products", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo, init?: RequestInit) => {
       expect(String(input)).toContain("/api/products?page=1&per_page=100&locale=en&currency=USD");
      expect(new Headers(init?.headers).get("X-Store-Id")).toBe("store");
      return response({ success: true, message: "ok", data: [{ id: 7, name: "API mug", slug: "api-mug", status: "active", category: "home", price: "12.5" }], meta: {} });
    });
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "store", channel_id: "web", locale: "en", currency: "USD" } });

    await expect(catalog.listProducts({ search: "mug" })).resolves.toMatchObject([{ id: "7", name: "API mug", price: 12.5 }]);
    expect(transport).toHaveBeenCalledTimes(1);
  });

  it("maps the preorder flag when the backend sets it", async () => {
    const transport = vi.fn(async () => response({
      success: true,
      message: "ok",
      data: [{ id: 10, name: "Preorder Chair", slug: "preorder-chair", status: "out_of_stock", price: "100.00", currency: "USD", is_preorder: true, tags: [] }],
      meta: { page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProducts()).resolves.toMatchObject([{ id: "10", is_preorder: true }]);
  });

  it("maps the generated catalog response fields without losing metadata", async () => {
    const transport = vi.fn(async () => response({
      success: true,
      message: "ok",
      data: [{
        id: 9,
        name: "Wool overshirt",
        slug: "wool-overshirt",
       status: "out_of_stock",
        category: "apparel",
        category_name: "Apparel",
        description: "A warm layer.",
        price: "149.95",
        compare_at_price: "179.95",
        currency: "USD",
       image: "https://cdn.example.test/wool.jpg",
        image_alt: "Wool overshirt",
        rating: 4.4,
        review_count: 31,
        tags: ["wool", "layer"],
      }],
      meta: { page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });

    await expect(catalog.listProducts()).resolves.toEqual([{
      id: "9",
      name: "Wool overshirt",
      slug: "wool-overshirt",
       status: "out_of_stock",
       productType: "simple",
       capability: "available",
      category: "apparel",
      categoryName: "Apparel",
      description: "A warm layer.",
      price: 149.95,
      compareAtPrice: 179.95,
      currency: "USD",
       image: "/placeholder-product.svg",
      imageAlt: "Wool overshirt",
      rating: 4.4,
      reviewCount: 31,
       tags: ["wool", "layer"],
       allowBackorder: false,
       attributes: undefined,
       options: undefined,
       stock: undefined,
     }]);
  });

  it("maps derivative variants and LQIP placeholders from image resources", async () => {
    const variants = [
      { w: 960, url: "/storage/products/p-1/images/hash_960.webp", format: "webp" },
      { w: 320, url: "/storage/products/p-1/images/hash_320.webp", format: "webp" },
      { w: 640, url: "/storage/products/p-1/images/hash_640.webp", format: "webp" },
    ];
    const transport = vi.fn(async () => response({
      success: true,
      message: "ok",
      data: [{
        id: 11,
        name: "Uploaded photo",
        slug: "uploaded-photo",
        status: "active",
        category: "home",
        price: 10,
        currency: "USD",
        image: "/storage/products/p-1/images/hash.jpg",
        image_alt: "Uploaded photo",
        images: [{
          url: "/storage/products/p-1/images/hash.jpg",
          alt: "Uploaded photo",
          variants,
          placeholder: "data:image/webp;base64,abc",
        }],
      }],
      meta: { page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });

    const result = await catalog.listProducts();
    expect(result[0]?.image).toBe("/storage/products/p-1/images/hash.jpg");
    expect(result[0]?.images?.[0]).toMatchObject({
      url: "/storage/products/p-1/images/hash.jpg",
      alt: "Uploaded photo",
      variants: [
        { w: 320, url: "/storage/products/p-1/images/hash_320.webp", format: "webp" },
        { w: 640, url: "/storage/products/p-1/images/hash_640.webp", format: "webp" },
        { w: 960, url: "/storage/products/p-1/images/hash_960.webp", format: "webp" },
      ],
      placeholder: "data:image/webp;base64,abc",
    });
  });

  it("sends locale and every commerce scope header through the generated client", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo, init?: RequestInit) => {
      const headers = new Headers(init?.headers);
       expect(String(input)).toContain("locale=vi-VN&currency=VND");
      expect(headers.get("X-Store-Id")).toBe("vn-store");
      expect(headers.get("X-Channel-Id")).toBe("web-vn");
      expect(headers.get("X-Locale")).toBe("vi-VN");
      expect(headers.get("X-Currency")).toBe("VND");
      return response({ success: true, message: "ok", data: [], meta: {} });
    });
    const catalog = createApiCatalog({
      baseUrl: "https://api.example.test",
      fetch: transport,
      scope: { store_id: "vn-store", channel_id: "web-vn", locale: "vi-VN", currency: "VND" },
    });

    await catalog.listProducts();
    expect(transport).toHaveBeenCalledOnce();
  });

  it("filters unsupported category/search queries after the contract list call", async () => {
    const transport = vi.fn(async () => response({ success: true, message: "ok", data: [{ id: 1, name: "Linen", slug: "linen", category: "apparel", price: 20 }, { id: 2, name: "Mug", slug: "mug", category: "home", price: 10 }], meta: {} }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProducts({ category: "home", search: "mug" })).resolves.toHaveLength(1);
  });

  it("preserves API failure statuses for route state handling", async () => {
    const transport = vi.fn(async () => response({ success: false, message: "Rate limited", meta: {} }, 429));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProducts()).rejects.toMatchObject({ status: 429 } satisfies Partial<CatalogRequestError>);
  });

  it("fails closed for malformed catalog envelopes and product records", async () => {
    const invalidEnvelope = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: { products: [] }, meta: {} })) });
    await expect(invalidEnvelope.listProducts()).rejects.toMatchObject({ status: 502 });

    const invalidProduct = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bad", slug: "bad", name: "Bad", price: "not-a-number" }], meta: {} })) });
    await expect(invalidProduct.listProducts()).rejects.toMatchObject({ status: 502 });
  });

  it.each([null, "", "not-a-number", -1])("rejects unsafe catalog price %s", async (price) => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bad-price", slug: "bad-price", name: "Bad", price }], meta: {} })) });
    await expect(catalog.listProducts()).rejects.toMatchObject({ status: 502 });
  });

  it("rejects malformed compare-at prices and removes duplicate products", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [
      { id: "same", slug: "same", name: "First", price: 10 },
      { id: "same", slug: "same-copy", name: "Duplicate", price: 11 },
    ], meta: {} })) });
    await expect(catalog.listProducts()).resolves.toHaveLength(1);

    const malformed = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bad-compare", slug: "bad-compare", name: "Bad", price: 10, compare_at_price: "" }], meta: {} })) });
    await expect(malformed.listProducts()).rejects.toMatchObject({ status: 502 });
  });

  it("rejects malformed stock, ratings, and tags instead of rendering unsafe data", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bad", slug: "bad", name: "Bad", price: 10, stock: -1, tags: ["ok", 3] }], meta: {} })) });
    await expect(catalog.listProducts()).rejects.toMatchObject({ status: 502 });
  });

  it("marks richer product types available when the catalog supports their presentation", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bundle", slug: "bundle", name: "Bundle", type: "bundle", price: 10, stock: 2, options: [{ name: "Size", values: ["One"] }] }], meta: {} })) });
    await expect(catalog.listProducts()).resolves.toMatchObject([{ productType: "bundle", capability: "available", options: [{ name: "Size" }] }]);
  });

  it("marks unsupported product types unavailable without inventing a transport", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "unknown", slug: "unknown", name: "Unknown", type: "marketplace", price: 10, stock: 2 }], meta: {} })) });
    await expect(catalog.listProducts()).resolves.toMatchObject([{ productType: "unsupported", capability: "unavailable" }]);
  });

  it("marks booking product types available when the catalog supports their presentation", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "booking", slug: "booking", name: "Booking", type: "booking", price: 10, stock: 2, booking_slots: [{ id: "s1", variant_id: "v1", starts_at: "2026-10-05T10:00:00Z", ends_at: "2026-10-05T11:00:00Z", capacity: 5 }] }], meta: {} })) });
    await expect(catalog.listProducts()).resolves.toMatchObject([{ productType: "booking", capability: "available" }]);
  });

  it("represents an allowed zero-stock backorder explicitly", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "later", slug: "later", name: "Later", price: 10, stock: 0, allow_backorder: true }], meta: {} })) });
    await expect(catalog.listProducts()).resolves.toMatchObject([{ status: "backorder", allowBackorder: true }]);
  });

  it.each([401, 403, 500])("maps catalog HTTP %s through the shared boundary", async (status) => {
    const transport = vi.fn(async () => response({ success: false, message: `status ${status}`, meta: {} }, status));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProducts()).rejects.toMatchObject({ status } satisfies Partial<CatalogRequestError>);
  });

  it("maps a transport outage to deterministic 503 unavailable state", async () => {
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => { throw new Error("offline"); }) });
    await expect(catalog.listProducts()).rejects.toMatchObject({ status: 503, message: "The catalog service is unavailable." });
  });

  it("uses the supported bounded first-page result limit", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      expect(String(input)).toContain("page=1&per_page=100");
      return response({ success: true, message: "ok", data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } });
    });
    await createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport }).listProducts();
    expect(transport).toHaveBeenCalledOnce();
  });

  it("turns a contract 404 detail response into an absent product", async () => {
    const transport = vi.fn(async () => response({ success: false, message: "Not found", meta: {} }, 404));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.getProduct("missing")).resolves.toBeUndefined();
  });

  it("encodes detail slugs and preserves the detail cancellation signal", async () => {
    const signal = new AbortController().signal;
    const transport = vi.fn(async (input: URL | RequestInfo, init?: RequestInit) => {
      expect(String(input)).toContain("/api/products/limited%2Fedition");
      expect(String(input)).toContain("locale=en");
      expect(String(input)).toContain("currency=USD");
      expect(init?.signal).toBe(signal);
      return response({ success: true, message: "ok", data: { slug: "limited/edition", name: "Limited edition" }, meta: {} });
    });
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });

    await expect(catalog.getProduct("limited/edition", signal)).resolves.toMatchObject({ slug: "limited/edition", name: "Limited edition" });
  });

  it("passes query and detail cancellation signals through the API boundary", async () => {
    const listSignal = new AbortController();
    const detailSignal = new AbortController();
    const signals: AbortSignal[] = [];
    const transport = vi.fn(async (_input: URL | RequestInfo, init?: RequestInit) => {
      if (init?.signal) signals.push(init.signal);
      return response({ success: true, message: "ok", data: [], meta: {} });
    });
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await catalog.listProducts({ signal: listSignal.signal });
    await catalog.getProduct("missing", detailSignal.signal);
    expect(signals).toEqual([listSignal.signal, detailSignal.signal]);
  });

  it("forwards bounded pagination and discovery filters while caching by query", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      expect(String(input)).toContain("page=2");
      expect(String(input)).toContain("per_page=100");
      expect(String(input)).toContain("category=home");
      expect(String(input)).toContain("search=mug");
      expect(String(input)).toContain("sort=price-desc");
      return response({ success: true, message: "ok", data: [{ id: 1, slug: "mug", name: "Mug", category: "home", price: 10 }], meta: {} });
    });
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "store-a", channel_id: "web", locale: "en", currency: "USD" } });

    const query = { page: 2, perPage: 999, category: "home", search: "mug", sort: "price-desc" as const };
    await catalog.listProducts(query);
    await catalog.listProducts(query);
    expect(transport).toHaveBeenCalledOnce();
    catalog.invalidateCache?.();
    await catalog.listProducts(query);
    expect(transport).toHaveBeenCalledTimes(2);
  });

  it("expires cached list results at the configured TTL", async () => {
    vi.useFakeTimers();
    try {
      const transport = vi.fn(async () => response({ success: true, message: "ok", data: [], meta: {} }));
      const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, cacheTtlMs: 1_000 });
      await catalog.listProducts();
      await catalog.listProducts();
      expect(transport).toHaveBeenCalledOnce();
      vi.advanceTimersByTime(1_001);
      await catalog.listProducts();
      expect(transport).toHaveBeenCalledTimes(2);
    } finally {
      vi.useRealTimers();
    }
  });

  it("does not reuse a response across commerce scopes", async () => {
    const transport = vi.fn(async () => response({ success: true, message: "ok", data: [], meta: {} }));
    const first = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "one", channel_id: "web", locale: "en", currency: "USD" } });
    const second = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "two", channel_id: "web", locale: "en", currency: "USD" } });
    await first.listProducts();
    await second.listProducts();
    expect(transport).toHaveBeenCalledTimes(2);
  });

  it("does not reuse a response across locale or currency scopes", async () => {
    const transport = vi.fn(async () => response({ success: true, message: "ok", data: [], meta: {} }));
    const english = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "one", channel_id: "web", locale: "en", currency: "USD" } });
    const vietnamese = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport, scope: { store_id: "one", channel_id: "web", locale: "vi-VN", currency: "VND" } });
    await english.listProducts();
    await vietnamese.listProducts();
    expect(transport).toHaveBeenCalledTimes(2);
  });

  it("exposes server facet counts and uses the product route for bounded autocomplete", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo) => response({ success: true, message: "ok", data: [{ id: 1, slug: "linen", name: "Linen shirt", price: 10 }], meta: { total: 4, facets: [{ name: "color", values: [{ value: "natural", label: "Natural", count: 3 }] }] } }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProductsResult?.({ search: "linen" })).resolves.toMatchObject({ total: 4, facets: [{ name: "color", values: [{ value: "natural", count: 3 }] }] });
    await expect(catalog.autocomplete?.("lin")).resolves.toHaveLength(1);
    expect(String(transport.mock.calls[1]?.[0])).toContain("search=lin");
  });

  it("normalizes object facet metadata and forwards facet intersections", async () => {
    const transport = vi.fn(async (input: URL | RequestInfo) => {
      expect(String(input)).toContain("facet_category=apparel%2Chome");
      expect(String(input)).toContain("facet_tag=linen");
      return response({ success: true, message: "ok", data: [{ id: 1, slug: "linen", name: "Linen", category: "apparel", price: 10 }], meta: { facets: { category: [{ value: "apparel", count: 1 }], tag: [{ value: "linen", label: "Linen", count: 1 }] }, total: 1 } });
    });
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProductsResult?.({ facets: { category: ["apparel", "home"], tag: ["linen"] } })).resolves.toMatchObject({ facets: [{ name: "category", values: [{ value: "apparel", count: 1 }] }, { name: "tag", values: [{ label: "Linen" }] }] });
  });

  it("fails closed for malformed option and quantity pricing metadata", async () => {
    const malformed = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bad-pricing", slug: "bad-pricing", name: "Bad", price: 10, option_pricing: [{ name: "Size", value: "Large", surcharge: "nope" }] }], meta: {} })) });
    await expect(malformed.listProducts()).rejects.toMatchObject({ status: 502 });
    const malformedQuantity = createApiCatalog({ baseUrl: "https://api.example.test", fetch: vi.fn(async () => response({ success: true, message: "ok", data: [{ id: "bad-tier", slug: "bad-tier", name: "Bad", price: 10, quantity_pricing: [{ minimum: 0, price: 9 }] }], meta: {} })) });
    await expect(malformedQuantity.listProducts()).rejects.toMatchObject({ status: 502 });
  });
});
