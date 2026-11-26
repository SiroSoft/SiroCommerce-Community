import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearRedirectCache, middleware } from "@/middleware";

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

describe("redirect middleware boundary behaviour", () => {
  it("passes through on a backend 5xx and never follows a self-referential target", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => json({ success: false, message: "boom" }, 500)));
    const failed = await middleware(new NextRequest("https://shop.example.test/old"));
    expect(failed.status).toBe(200);
    expect(failed.headers.get("location")).toBeNull();

    clearRedirectCache();
    vi.stubGlobal("fetch", vi.fn(async () => json({ success: true, data: { target: "/old", code: 301 } })));
    const self = await middleware(new NextRequest("https://shop.example.test/old"));
    expect(self.status).toBe(200);
    expect(self.headers.get("location")).toBeNull();

    clearRedirectCache();
    vi.stubGlobal("fetch", vi.fn(async () => json({ success: true, data: { target: "//evil.example", code: 302 } })));
    const open = await middleware(new NextRequest("https://shop.example.test/old"));
    expect(open.status).toBe(200);
    expect(open.headers.get("location")).toBeNull();
  });
});
