import { describe, expect, it, vi } from "vitest";
import { createApiCustomer } from "@/services/customer";

function reply(data: unknown, status = 200) {
  return new Response(JSON.stringify({ success: status < 400, message: status < 400 ? "ok" : "failed", data, meta: {} }), { status, headers: { "Content-Type": "application/json" } });
}

describe("customer API adapter", () => {
  it("implements wishlist reads and writes with contract payloads", async () => {
    const fetcher = vi.fn(async () => reply([{ product_id: "p-1" }]));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await expect(adapter.getWishlist()).resolves.toEqual([{ product_id: "p-1" }]);
    await adapter.addWishlist("p-2", "v-1");
    await adapter.removeWishlist("p-2");
    const calls = fetcher.mock.calls as unknown as [RequestInfo | URL, RequestInit | undefined][];
    expect(calls.map(([url, init]) => [String(url), init?.method, init?.body])).toEqual([
      ["https://api.example.test/api/wishlist", "GET", undefined],
      ["https://api.example.test/api/wishlist", "POST", JSON.stringify({ product_id: "p-2", variant_id: "v-1" })],
      ["https://api.example.test/api/wishlist/p-2", "DELETE", undefined],
    ]);
  });

  it("loads reviews and submits the required review fields", async () => {
    const fetcher = vi.fn(async () => reply([{ product_id: "p-1", rating: 5, body: "Excellent" }]));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await adapter.getReviews("p-1");
    await adapter.submitReview("p-1", { order_id: "o-1", order_line_id: "line-1", rating: 5, body: "Excellent" });
    const calls = fetcher.mock.calls as unknown as [RequestInfo | URL, RequestInit | undefined][];
    expect(String(calls[0]?.[0])).toContain("/api/products/p-1/reviews");
    expect(calls[1]?.[1]?.body).toBe(JSON.stringify({ order_id: "o-1", order_line_id: "line-1", rating: 5, body: "Excellent" }));
  });

  it("uses idempotency keys for loyalty writes and exposes account reads", async () => {
    const fetcher = vi.fn(async (url: URL | RequestInfo, init?: RequestInit) => {
      if (String(url).endsWith("/loyalty")) return reply({ balance_points: 240 });
      return reply({ balance_points: 140 });
    });
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await expect(adapter.getLoyalty()).resolves.toEqual({ balance_points: 240 });
    await adapter.redeemLoyalty(100);
    const calls = fetcher.mock.calls as unknown as [RequestInfo | URL, RequestInit | undefined][];
    expect(new Headers(calls[1]?.[1]?.headers).get("Idempotency-Key")).toMatch(/^storefront-/);
  });

  it("maps returns, addresses, and notification preference endpoints", async () => {
    const fetcher = vi.fn(async (url: URL | RequestInfo) => {
      const path = String(url);
      if (path.endsWith("/returns")) return reply([]);
      if (path.includes("/addresses")) return reply([]);
      return reply({ transactional_email: true, marketing_email: false });
    });
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await adapter.getReturns();
    await adapter.getReturn("r-1");
    await adapter.getAddresses();
    await adapter.getNotificationPreferences();
    await adapter.updateNotificationPreferences({ marketing_email: true });
    expect(fetcher.mock.calls.map(([url]) => String(url))).toEqual([
      "https://api.example.test/api/account/returns",
      "https://api.example.test/api/account/returns/r-1",
      "https://api.example.test/api/account/addresses",
      "https://api.example.test/api/account/notification-preferences",
      "https://api.example.test/api/account/notification-preferences",
    ]);
  });

  it("keeps financial document reads on the authenticated account order route", async () => {
    const fetcher = vi.fn(async (_url: URL | RequestInfo) => reply({ order_id: "o-1", currency: "VND", totals: { grand_total_minor: 1000, refunded_minor: 0 }, invoices: [], receipts: [], credit_memos: [], refund_timeline: [], available: false }));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await adapter.getFinancialDocuments("o-1");
    expect(String(fetcher.mock.calls[0]?.[0])).toBe("https://api.example.test/api/account/orders/o-1/financial-documents");
  });

  it("submits a customer-scoped return with an idempotency key", async () => {
    const fetcher = vi.fn(async () => reply({ id: "r-1", state: "requested" }));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await expect(adapter.requestReturn("o-1", { fulfillment_id: "f-1", lines: [{ order_line_id: "l-1", qty: 1 }], reason: "defective" })).resolves.toMatchObject({ id: "r-1" });
    const [url, init] = (fetcher.mock.calls as unknown as [RequestInfo | URL, RequestInit | undefined][])[0] ?? [];
    expect(String(url)).toBe("https://api.example.test/api/account/orders/o-1/returns");
    expect(init?.method).toBe("POST");
    expect(new Headers(init?.headers).get("Idempotency-Key")).toMatch(/^storefront-/);
  });

  it("preserves server pagination and does not expose delivery diagnostics", async () => {
    const fetcher = vi.fn(async (_url: URL | RequestInfo) => reply([{ event: "order_placed", status: "sent", sent_at: null }], 200));
    (fetcher as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(new Response(JSON.stringify({ success: true, message: "ok", data: [{ event: "order_placed", status: "sent", sent_at: null }], meta: { page: 2, last_page: 3 } }), { status: 200, headers: { "Content-Type": "application/json" } }));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher });
    await expect(adapter.getMessageHistory(2)).resolves.toEqual({ items: [{ event: "order_placed", status: "sent", sent_at: null }], page: 2, last_page: 3 });
    expect(String(fetcher.mock.calls[0]?.[0])).toContain("/api/account/message-history?page=2");
  });
});
