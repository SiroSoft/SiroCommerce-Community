import { describe, expect, it, vi } from "vitest";
import { ApiClientError, normalizeEnvelope, RequestCoordinator } from "./index";
import type { AiSuggestionResult, HeadlessSchemaContract } from "./native-contracts";

const envelope = (success = true, data: unknown = { id: 1 }, meta: Record<string, unknown> = {}) => ({ success, message: success ? "ok" : "bad", ...(success ? { data } : {}), meta });
const response = (status: number, body: unknown, headers: Record<string, string> = {}) => new Response(body === "malformed" ? "{" : JSON.stringify(body), { status, headers });
const transport = (responses: Response[]) => { const calls: Array<{ url: string; init: RequestInit }> = []; const fetch = vi.fn(async (url: string | URL, init?: RequestInit) => { calls.push({ url: String(url), init: init ?? {} }); return responses.shift()!; }); return { fetch: fetch as typeof globalThis.fetch, calls }; };

describe("RequestCoordinator", () => {
  it("keeps native AI and headless contracts provider-neutral", () => {
    const result: AiSuggestionResult = { status: "provider_unavailable", autonomous_mutation: false };
    const schema: HeadlessSchemaContract = { schema: {}, limits: { max_query_bytes: 20000, max_depth: 8, max_complexity: 80, read_only: true } };
    expect(result.autonomous_mutation).toBe(false);
    expect(schema.limits.read_only).toBe(true);
  });
  it("builds method, path, query, JSON body, required headers, scope, auth and propagation headers", async () => {
    const t = transport([response(200, envelope(), { "X-Correlation-Id": "corr-1" })]);
    const client = new RequestCoordinator({ baseUrl: "https://api.test/", version: "v2", defaultHeaders: { "X-Siro-FE": "fe-token" }, fetch: t.fetch, getAccessToken: () => "current" });
    const result = await client.request("/orders", { method: "POST", query: { page: 2, draft: false, skip: null, tag: ["a", "b"] }, body: { sku: "x" }, scope: { store_id: "s", channel_id: "c", locale: "en", currency: "USD" }, idempotencyKey: "idem", cartToken: "cart" });
    const call = t.calls[0];
    expect(call.url).toBe("https://api.test/orders?page=2&draft=false&tag=a&tag=b");
    expect(call.init.method).toBe("POST");
    expect(JSON.parse(String(call.init.body))).toEqual({ sku: "x" });
    expect(call.init.headers).toMatchObject({ Accept: "application/json; version=v2", "X-Siro-FE": "fe-token", Authorization: "Bearer current", "X-Store-Id": "s", "X-Channel-Id": "c", "X-Locale": "en", "X-Currency": "USD", "Idempotency-Key": "idem", "X-Cart-Token": "cart", "Content-Type": "application/json" });
    expect((call.init.headers as Record<string, string>)["X-Correlation-Id"]).toMatch(/^req_/);
    expect(result).toMatchObject({ status: 200, correlationId: "corr-1", body: envelope() });
  });

  it("omits optional auth and body headers, passes AbortSignal, and preserves cancellation", async () => {
    const controller = new AbortController(); controller.abort();
    let receivedSignal: AbortSignal | undefined;
    const cancelledFetch = vi.fn(async (_url: string | URL, init?: RequestInit) => { receivedSignal = init?.signal; throw new DOMException("aborted", "AbortError"); }) as typeof globalThis.fetch;
    const client = new RequestCoordinator({ baseUrl: "https://api.test", fetch: cancelledFetch });
    await expect(client.request("/cancel", { signal: controller.signal })).rejects.toMatchObject({ name: "AbortError" });
    expect(receivedSignal).toBe(controller.signal);
    const ok = transport([response(200, envelope())]);
    await new RequestCoordinator({ baseUrl: "https://api.test", fetch: ok.fetch }).request("/empty");
    expect(ok.calls[0].init.headers).not.toHaveProperty("Authorization");
    expect(ok.calls[0].init.headers).not.toHaveProperty("Content-Type");
  });

  it("normalizes success and maps envelope errors and HTTP errors", async () => {
    expect(normalizeEnvelope({ success: true, data: 3, meta: { trace: "x" } })).toEqual({ ok: true, data: 3, meta: { trace: "x" } });
    expect(normalizeEnvelope({ success: false, message: "Invalid", meta: { error_code: "INVALID", errors: { email: ["Required"] } } })).toEqual({ ok: false, message: "Invalid", errorCode: "INVALID", errors: { email: ["Required"] }, meta: { error_code: "INVALID", errors: { email: ["Required"] } } });
    const t = transport([response(200, { success: false, message: "Invalid", meta: { error_code: "INVALID", errors: { sku: ["Duplicate"] } } }, { "X-Correlation-Id": "c" })]);
    await expect(new RequestCoordinator({ baseUrl: "https://api.test", fetch: t.fetch }).request("/bad")).rejects.toMatchObject({ status: 200, errorCode: "INVALID", errors: { sku: ["Duplicate"] }, correlationId: "c" });
    const malformed = transport([response(502, "malformed")]);
    await expect(new RequestCoordinator({ baseUrl: "https://api.test", fetch: malformed.fetch }).request("/malformed")).rejects.toMatchObject({ status: 502, name: "ApiClientError" });
  });

  it("invokes the global fetch with a window receiver so browsers do not reject the call", async () => {
    const original = globalThis.fetch;
    const strictFetch = function strictFetch(this: unknown) {
      if (this !== globalThis && this !== undefined) throw new TypeError("Failed to execute 'fetch' on 'Window': Illegal invocation");
      return Promise.resolve(response(200, envelope(true, "ok")));
    } as unknown as typeof fetch;
    globalThis.fetch = strictFetch;
    try {
      const client = new RequestCoordinator({ baseUrl: "https://api.test" });
      await expect(client.request("/ok")).resolves.toMatchObject({ status: 200 });
    } finally { globalThis.fetch = original; }
  });

  it("requires CSRF transport for cookie-authenticated writes and ignores legacy top-level errors", async () => {
    const t = transport([response(200, envelope())]);
    const client = new RequestCoordinator({ baseUrl: "https://api.test", fetch: t.fetch, credentials: "include" });
    await expect(client.request("/write", { method: "POST", body: {} })).rejects.toMatchObject({ errorCode: "CSRF_REQUIRED" });
    expect(t.fetch).not.toHaveBeenCalled();
    expect(normalizeEnvelope({ success: false, message: "bad", errors: { sku: ["legacy"] } })).toMatchObject({ ok: false, errors: undefined, meta: {} });
  });

  it("refreshes single-flight for ten callers and replays each request at most once", async () => {
    let refreshes = 0; let calls = 0;
    const t = transport(Array.from({ length: 10 }, () => response(401, envelope(false), {})).concat(Array.from({ length: 10 }, (_, i) => response(200, envelope(true, i)))));
    const client = new RequestCoordinator({ baseUrl: "https://api.test", fetch: t.fetch, getAccessToken: () => "old", onUnauthorized: async () => { refreshes++; await Promise.resolve(); return true; } });
    await Promise.all(Array.from({ length: 10 }, (_, i) => client.request(`/item/${i}`)));
    calls = t.fetch.mock.calls.length;
    expect(refreshes).toBe(1); expect(calls).toBe(20);
  });

  it("does not replay after refresh failure or a second 401", async () => {
    const failed = transport([response(401, envelope(false))]);
    await expect(new RequestCoordinator({ baseUrl: "https://api.test", fetch: failed.fetch, onUnauthorized: async () => false }).request("/fail")).rejects.toMatchObject({ status: 401 });
    expect(failed.fetch).toHaveBeenCalledTimes(1);
    const second = transport([response(401, envelope(false)), response(401, envelope(false))]);
    await expect(new RequestCoordinator({ baseUrl: "https://api.test", fetch: second.fetch, onUnauthorized: async () => true }).request("/again")).rejects.toMatchObject({ status: 401 });
    expect(second.fetch).toHaveBeenCalledTimes(2);
  });

  it("replays the equivalent payload and idempotency headers exactly once", async () => {
    const t = transport([response(401, envelope(false)), response(200, envelope(true, { accepted: true }))]);
    const client = new RequestCoordinator({ baseUrl: "https://api.test", fetch: t.fetch, onUnauthorized: async () => true });
    await client.request("/purchase", { method: "POST", body: { sku: "A", quantity: 2 }, idempotencyKey: "same-key", cartToken: "same-cart" });
    expect(t.calls).toHaveLength(2);
    expect(t.calls[1].init.body).toBe(t.calls[0].init.body);
    expect(t.calls[1].init.headers).toMatchObject({ "Idempotency-Key": "same-key", "X-Cart-Token": "same-cart" });
  });
});
