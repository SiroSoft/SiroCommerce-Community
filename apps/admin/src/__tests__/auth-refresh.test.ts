import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fetchWithAuth, session } from "@/services/api";

function jwt(expSeconds: number): string {
  const encode = (value: unknown) => Buffer.from(JSON.stringify(value)).toString("base64url");
  return `${encode({ alg: "none", typ: "JWT" })}.${encode({ exp: expSeconds })}.signature`;
}

function envelope(data: unknown, status = 200): Response {
  return new Response(JSON.stringify({ success: true, message: "ok", data, meta: {} }), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  localStorage.clear();
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  localStorage.clear();
});

describe("admin 401 refresh", () => {
  it("refreshes once and replays the original request with the new access token", async () => {
    const now = Math.floor(Date.now() / 1000);
    const oldToken = jwt(now + 3600);
    const newToken = jwt(now + 7200);
    session.setTokens({ token: oldToken, refresh_token: "refresh-1" });

    const protectedCalls: Array<string | null> = [];
    const transport = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes("/api/auth/refresh")) {
        return envelope({ token: newToken, refresh_token: "refresh-2", token_type: "bearer" });
      }
      protectedCalls.push(new Headers(init?.headers).get("Authorization"));
      return protectedCalls.length === 1
        ? new Response(JSON.stringify({ success: false, message: "expired", meta: {} }), { status: 401 })
        : envelope({ ok: true });
    });
    vi.stubGlobal("fetch", transport);

    const response = await fetchWithAuth("/api/admin/me");

    expect(response.status).toBe(200);
    expect(transport).toHaveBeenCalledTimes(3);
    expect(protectedCalls).toEqual([
      `Bearer ${oldToken}`,
      `Bearer ${newToken}`,
    ]);
    expect(localStorage.getItem("siro_refresh_token")).toBe("refresh-2");
  });
});
