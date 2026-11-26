import { describe, it, expect, afterEach } from "vitest";
import api, { session } from "@/services/api";
import { authStorage } from "@/services/auth-storage";

describe("api X-Siro-FE header", () => {
  const originalToken = process.env.NEXT_PUBLIC_FE_TOKEN;

  afterEach(() => {
    if (originalToken === undefined) {
      delete process.env.NEXT_PUBLIC_FE_TOKEN;
    } else {
      process.env.NEXT_PUBLIC_FE_TOKEN = originalToken;
    }
  });

  async function captureHeaders(): Promise<Headers> {
    let captured: Headers | undefined;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (_input, init) => {
      captured = new Headers(init?.headers);
      return new Response(JSON.stringify({ success: true, message: "ok", data: {}, meta: {} }), { status: 200 });
    }) as typeof fetch;
    await api.get("/__header_probe__");
    globalThis.fetch = originalFetch;
    if (!captured) throw new Error("fetch did not capture headers");
    return captured;
  }

  it("sets X-Siro-FE when NEXT_PUBLIC_FE_TOKEN is set", async () => {
    process.env.NEXT_PUBLIC_FE_TOKEN = "test-fe-token";
    const headers = await captureHeaders();
    expect(headers.get("X-Siro-FE")).toBe("test-fe-token");
  });

  it("omits X-Siro-FE when NEXT_PUBLIC_FE_TOKEN is not set", async () => {
    delete process.env.NEXT_PUBLIC_FE_TOKEN;
    const headers = await captureHeaders();
    expect(headers.get("X-Siro-FE")).toBeNull();
  });

  it("never puts the access token in a request URL", async () => {
    const token = `eyJhbGciOiJub25lIn0.${btoa(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 3600 })).replace(/=+$/, "")}.signature`;
    session.setTokens({ token, refresh_token: "refresh-only-test" });
    let requestUrl = "";
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input, init) => {
      requestUrl = String(input);
      expect(new Headers(init?.headers).get("Authorization")).toBe(`Bearer ${token}`);
      return new Response(JSON.stringify({ success: true, message: "ok", data: {}, meta: {} }), { status: 200 });
    }) as typeof fetch;
    await api.get("/__token_probe__");
    globalThis.fetch = originalFetch;
    expect(requestUrl).not.toContain(token);
    authStorage.clear();
  });
});
