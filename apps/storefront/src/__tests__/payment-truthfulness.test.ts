import { describe, expect, it, vi } from "vitest";
import { ApiPurchaseAdapter } from "../purchase/api-adapter.js";

const envelope = (data: unknown) => new Response(JSON.stringify({ success: true, message: "ok", data, meta: {} }), { status: 200, headers: { "Content-Type": "application/json" } });

describe("truthful payment adapter", () => {
  it.each([
    ["pending", "pending"],
    ["processing", "pending"],
    ["authorized", "authorized"],
    ["captured", "captured"],
    ["declined", "failed"],
    ["unknown", "unknown"],
    ["provider_said_something_new", "unknown"],
  ] as const)("maps provider state %s as %s instead of guessing success", async (state, expected) => {
    const fetch = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => envelope({ id: "payment-1", state }));
    const adapter = new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch });
    await expect(adapter.getPaymentStatus("payment-1")).resolves.toMatchObject({ id: "payment-1", status: expected });
  });

  it("does not fabricate an authorized payment before the backend place request", async () => {
    const fetch = vi.fn();
    const adapter = new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch: fetch as unknown as typeof globalThis.fetch });
    (adapter as unknown as { checkout?: unknown }).checkout = { id: "checkout:cart-1", cart_id: "cart-1", quote_id: "quote-1", email: "buyer@example.com" };
    const payment = await adapter.authorizePayment("checkout:cart-1", "card", "payment-key");
    expect(payment.status).toBe("pending");
    expect(fetch).not.toHaveBeenCalled();
  });

  it("refuses to reconcile without a session or guest order credential", async () => {
    const fetch = vi.fn();
    const adapter = new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch: fetch as unknown as typeof globalThis.fetch });
    await expect(adapter.reconcileOrder("order-key")).rejects.toMatchObject({ code: "unauthorized", status: 401 });
    expect(fetch).not.toHaveBeenCalled();
  });

  it("uses the session token when reconciling an authenticated order", async () => {
    const fetch = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => envelope([]));
    const adapter = new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch, getAccessToken: () => "session-token" });
    await expect(adapter.reconcileOrder("order-key")).resolves.toBeNull();
    expect(new Headers((fetch.mock.calls[0]?.[1] as RequestInit | undefined)?.headers).get("Authorization")).toBe("Bearer session-token");
  });
});
