import { describe, expect, it, vi } from "vitest";
import { createApiCustomer, type CustomerSubscription } from "@/services/customer";

const reply = (data: unknown, status = 200) => new Response(JSON.stringify({ success: status < 400, message: "ok", data, meta: { page: 1, last_page: 1 } }), { status, headers: { "Content-Type": "application/json" } });
const subscription: CustomerSubscription = { id: "sub-1", customer_id: "customer-1", plan_id: "plan-monthly", status: "active", interval: "month", interval_count: 1, next_charge_at: "2099-01-01", price: { amount_minor: 1200, currency: "USD" } };

describe("customer subscription API boundary", () => {
  it("keeps reads and idempotent mutations customer-scoped", async () => {
    const fetcher = vi.fn(async () => reply([subscription]));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher, accessToken: "customer-token" });
    await adapter.getSubscriptions();
    await adapter.getSubscription("sub-1");
    await adapter.startSubscription("plan-monthly", true);
    await adapter.transitionSubscription("sub-1", "pause", true);
    await adapter.renewSubscription("sub-1");
    const calls = fetcher.mock.calls as unknown as [RequestInfo | URL, RequestInit | undefined][];
    expect(calls.map(([url, init]) => [new URL(String(url)).pathname, init?.method, new Headers(init?.headers).get("Authorization"), new Headers(init?.headers).get("Idempotency-Key")])).toEqual([
      ["/api/account/subscriptions", "GET", "Bearer customer-token", null],
      ["/api/account/subscriptions/sub-1", "GET", "Bearer customer-token", null],
      ["/api/account/subscriptions", "POST", "Bearer customer-token", expect.stringMatching(/^storefront-/)],
      ["/api/account/subscriptions/sub-1/transition", "POST", "Bearer customer-token", expect.stringMatching(/^storefront-/)],
      ["/api/account/subscriptions/sub-1/renew", "POST", "Bearer customer-token", expect.stringMatching(/^storefront-/)],
    ]);
  });

  it("requires explicit consent before any subscription mutation", async () => {
    const fetcher = vi.fn(async () => reply([]));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher, accessToken: "customer-token" });
    await expect(adapter.startSubscription("plan-monthly", false)).rejects.toMatchObject({ status: 422 });
    await expect(adapter.transitionSubscription("sub-1", "cancel", false)).rejects.toMatchObject({ status: 422 });
    expect(fetcher).not.toHaveBeenCalled();
  });
});
