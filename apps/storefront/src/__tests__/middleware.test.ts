import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearRedirectCache, middleware, safeRedirectTarget, shouldSkipRedirectLookup } from "@/middleware";

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

beforeEach(() => {
  clearRedirectCache();
  vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("storefront redirect middleware", () => {
  it("redirects a resolved legacy path with 308 and caches the lookup", async () => {
    const transport = vi.fn(async () => json({ success: true, message: "Redirect resolved", data: { target: "/new", code: 301 } }));
    vi.stubGlobal("fetch", transport);
    const first = await middleware(new NextRequest("https://shop.example.test/old"));
    expect(first.status).toBe(308);
    expect(first.headers.get("location")).toBe("https://shop.example.test/new");
    const second = await middleware(new NextRequest("https://shop.example.test/old"));
    expect(second.status).toBe(308);
    expect(transport).toHaveBeenCalledOnce();
  });

  it("skips framework, API and asset paths without calling the backend", async () => {
    const transport = vi.fn(async () => json({ success: true, data: { target: "/new" } }));
    vi.stubGlobal("fetch", transport);
    expect((await middleware(new NextRequest("https://shop.example.test/_next/webpack-hmr"))).status).toBe(200);
    expect((await middleware(new NextRequest("https://shop.example.test/api/products"))).status).toBe(200);
    expect((await middleware(new NextRequest("https://shop.example.test/catalog/hero.svg"))).status).toBe(200);
    expect(transport).not.toHaveBeenCalled();
  });

  it("continues the request when the backend is unavailable or the target is unknown", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => { throw new Error("offline"); }));
    expect((await middleware(new NextRequest("https://shop.example.test/broken"))).status).toBe(200);
    clearRedirectCache();
    vi.stubGlobal("fetch", vi.fn(async () => json({ success: true, data: { target: null } })));
    expect((await middleware(new NextRequest("https://shop.example.test/unknown"))).status).toBe(200);
  });

  it("never follows open or self-referential redirect targets", () => {
    expect(safeRedirectTarget("https://evil.example", "/old")).toBeUndefined();
    expect(safeRedirectTarget("//evil.example", "/old")).toBeUndefined();
    expect(safeRedirectTarget("/old", "/old")).toBeUndefined();
    expect(safeRedirectTarget("/new?ref=nav", "/old")).toBe("/new?ref=nav");
    expect(shouldSkipRedirectLookup("/_next")).toBe(true);
  });
});
