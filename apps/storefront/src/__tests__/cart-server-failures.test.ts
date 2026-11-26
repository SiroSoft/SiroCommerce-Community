import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const store = vi.hoisted(() => {
  const values = new Map<string, string>();
  return {
    values,
    reset: () => values.clear(),
    cookieJar: {
      get: (name: string) => values.has(name) ? { name, value: values.get(name)! } : undefined,
      set: (name: string, value: string) => { values.set(name, value); },
    },
  };
});

vi.mock("next/headers", () => ({
  cookies: async () => store.cookieJar,
  headers: async () => new Headers({ host: "localhost" }),
}));

const response = (status: number, data: unknown) => new Response(JSON.stringify({ success: status < 400, message: status < 400 ? "ok" : "failed", data }), { status, headers: { "Content-Type": "application/json" } });

describe("server cart failure handling", () => {
  beforeEach(() => {
    store.reset();
    vi.stubEnv("STOREFRONT_API_BASE_URL", "https://commerce.test");
  });
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  it("does not create a replacement cart when adding a line fails with 5xx", async () => {
    store.values.set("siro_cart_id", "cart-12345678");
    const fetch = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => response(500, {}));
    vi.stubGlobal("fetch", fetch);
    const { addVariantToCart } = await import("../lib/cart-server");
    await expect(addVariantToCart("variant-1", 1)).rejects.toMatchObject({ status: 500 });
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(new URL(String(fetch.mock.calls[0]?.[0])).pathname).toBe("/api/carts/cart-12345678/lines");
  });

  it("creates a new cart only when the old cart is genuinely missing", async () => {
    store.values.set("siro_cart_id", "cart-12345678");
    const fetch = vi.fn(async (input: RequestInfo | URL) => {
      const path = new URL(String(input)).pathname;
      if (path === "/api/carts/cart-12345678/lines") return response(404, {});
      if (path === "/api/carts") return response(201, { id: "cart-99999999", access_token: "token-99999999" });
      if (path === "/api/carts/cart-99999999/lines") return response(200, { id: "line-1" });
      return response(200, { id: "cart-99999999", lines: [], subtotal_minor: 0 });
    });
    vi.stubGlobal("fetch", fetch);
    const { addVariantToCart } = await import("../lib/cart-server");
    await expect(addVariantToCart("variant-1", 1)).resolves.toBe("cart-99999999");
    const paths = fetch.mock.calls.map((call) => new URL(String(call[0])).pathname);
    expect(paths).toContain("/api/carts");
    expect(store.values.get("siro_cart_id")).toBe("cart-99999999");
  });

  it("surfaces a 5xx cart read instead of silently returning an empty bag", async () => {
    store.values.set("siro_cart_id", "cart-12345678");
    const fetch = vi.fn(async () => response(503, {}));
    vi.stubGlobal("fetch", fetch);
    const { loadCartSnapshot } = await import("../lib/cart-server");
    await expect(loadCartSnapshot()).rejects.toMatchObject({ status: 503 });
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("treats a missing cart as an empty bag", async () => {
    store.values.set("siro_cart_id", "cart-12345678");
    const fetch = vi.fn(async () => response(404, {}));
    vi.stubGlobal("fetch", fetch);
    const { loadCartSnapshot } = await import("../lib/cart-server");
    await expect(loadCartSnapshot()).resolves.toBeUndefined();
  });
});
