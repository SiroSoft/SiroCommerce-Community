import { describe, expect, it, vi } from "vitest";
import { normalizeEnvelope, RequestCoordinator } from "@sirocommerce/api-client";
import { AuthSessionCoordinator, type AuthStorage } from "@sirocommerce/auth";
import { ScopeCoordinator, scopeHeaders } from "@sirocommerce/scope";

const response = (status: number, body: unknown, headers: Record<string, string> = {}) => new Response(JSON.stringify(body), { status, headers });

describe("platform foundation", () => {
  it("normalizes backend meta errors", () => {
    expect(normalizeEnvelope({ success: false, message: "Invalid", meta: { error_code: "INVALID", errors: { email: ["Required"] } } })).toEqual({ ok: false, message: "Invalid", errorCode: "INVALID", errors: { email: ["Required"] }, meta: { error_code: "INVALID", errors: { email: ["Required"] } } });
  });

  it("rejects an error envelope even when HTTP status is successful", async () => {
    const client = new RequestCoordinator({ baseUrl: "https://api.example.test", fetch: (async () => response(200, { success: false, message: "Invalid", meta: { error_code: "INVALID", errors: { sku: ["Duplicate"] } } })) as typeof fetch });
    await expect(client.request("/probe")).rejects.toMatchObject({ status: 200, errorCode: "INVALID", errors: { sku: ["Duplicate"] } });
  });

  it("sets platform headers and captures correlation id", async () => {
    let request: RequestInit | undefined;
    const client = new RequestCoordinator({ baseUrl: "https://api.example.test", fetch: (async (_url, init) => { request = init; return response(200, { success: true, message: "ok", data: {}, meta: {} }, { "X-Correlation-Id": "corr-1" }); }) as typeof fetch });
    const result = await client.request("/probe", { method: "POST", accessToken: "access", idempotencyKey: "idem", cartToken: "cart", scope: { store_id: "s", channel_id: "c", locale: "en", currency: "USD" }, body: {} });
    expect((request?.headers as Record<string, string>).Authorization).toBe("Bearer access");
    expect((request?.headers as Record<string, string>)["Idempotency-Key"]).toBe("idem");
    expect((request?.headers as Record<string, string>)["X-Cart-Token"]).toBe("cart");
    expect(result.correlationId).toBe("corr-1");
  });

  it("refreshes once and safely replays one request", async () => {
    let calls = 0;
    let refreshes = 0;
    const client = new RequestCoordinator({ baseUrl: "https://api.example.test", getAccessToken: () => "old", onUnauthorized: async () => { refreshes++; await Promise.resolve(); return true; }, fetch: (async () => { const current = ++calls; return response(current <= 2 ? 401 : 200, current <= 2 ? { success: false, message: "expired", meta: {} } : { success: true, message: "ok", data: 1, meta: {} }); }) as typeof fetch });
    await Promise.all([client.request("/a"), client.request("/b")]);
    expect(refreshes).toBe(1);
    expect(calls).toBe(4);
  });

  it("does not replay a request after refresh failure or a second 401", async () => {
    let calls = 0;
    const failed = new RequestCoordinator({ baseUrl: "https://api.example.test", onUnauthorized: async () => false, fetch: (async () => { calls += 1; return response(401, { success: false, message: "expired", meta: {} }); }) as typeof fetch });
    await expect(failed.request("/failed")).rejects.toMatchObject({ status: 401 });
    expect(calls).toBe(1);
    const stillUnauthorized = new RequestCoordinator({ baseUrl: "https://api.example.test", onUnauthorized: async () => true, fetch: (async () => { calls += 1; return response(401, { success: false, message: "expired", meta: {} }); }) as typeof fetch });
    await expect(stillUnauthorized.request("/still-failed")).rejects.toMatchObject({ status: 401 });
    expect(calls).toBe(3);
  });

  it("shares refresh and invalidates stale scopes", async () => {
    const values: Record<string, string | null> = { access: "a", refresh: "r" };
    const storage: AuthStorage = { getAccessToken: () => values.access, setAccessToken: token => { values.access = token; }, getRefreshToken: () => values.refresh, setTokens: tokens => { values.access = tokens.token; values.refresh = tokens.refresh_token; }, clear: () => { values.access = null; values.refresh = null; } };
    const refresh = vi.fn(async () => ({ token: "new", refresh_token: "new-r" }));
    const auth = new AuthSessionCoordinator(storage, { refresh });
    await Promise.all([auth.refresh(), auth.refresh()]);
    expect(refresh).toHaveBeenCalledTimes(1);
    const scopes = new ScopeCoordinator();
    const first = scopes.set({ store_id: "s", channel_id: "c", locale: "en", currency: "USD" });
    const captured = scopes.capture();
    scopes.set({ store_id: "s2", channel_id: "c", locale: "en", currency: "USD" });
    expect(scopes.isCurrent(first)).toBe(false);
    expect(captured.scope?.store_id).toBe("s");
    expect(scopes.isCurrent(captured.version)).toBe(false);
    expect(scopeHeaders(scopes.value)).toMatchObject({ "X-Store-Id": "s2" });
  });
});
