import { describe, expect, it, vi } from "vitest";
import { ApiPurchaseAdapter } from "../purchase/api-adapter.js";
import { PurchaseService } from "../purchase/service.js";
import { apiBoundary } from "./support/api-boundary.js";

const product = { product_id: "variant-1", slug: "siro-dao", name: "Siro dao", unit_price: { amount_minor: 125000, currency: "VND" }, quantity: 2 };
const envelope = (data: unknown) => new Response(JSON.stringify({ success: true, message: "ok", data, meta: {} }), { status: 200, headers: { "Content-Type": "application/json", "X-Correlation-Id": "corr-1" } });
const errorResponse = (status: number) => new Response(JSON.stringify({ success: false, message: "failed", meta: { errors: { email: ["Invalid"] } } }), { status, headers: { "Content-Type": "application/json" } });

function adapter(fetch: typeof globalThis.fetch) {
  return new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch });
}

describe("generated API purchase adapter contract", () => {
  it("updates the authenticated customer profile through the customer route", async () => {
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      expect(new URL(String(input)).pathname).toBe("/api/auth/me");
      expect(init?.method).toBe("PUT");
      expect(new Headers(init?.headers).get("Authorization")).toBe("Bearer session-token");
      return envelope({ id: "customer-1", email: "buyer@example.com", name: "Updated buyer" });
    });
    const account = await adapter(fetch as typeof globalThis.fetch).updateAccount("session-token", { name: "Updated buyer" });
    expect(account).toEqual({ id: "customer-1", email: "buyer@example.com", name: "Updated buyer" });
  });

  it("revokes the server session on logout with the access token", async () => {
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      expect(new URL(String(input)).pathname).toBe("/api/auth/logout");
      expect(init?.method).toBe("POST");
      expect(new Headers(init?.headers).get("Authorization")).toBe("Bearer session-token");
      return envelope(null);
    });
    await adapter(fetch as typeof globalThis.fetch).logout("session-token");
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("forwards the Turnstile token on login and register-then-login", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), init: init ?? {} });
      if (calls.length === 1) return envelope({ id: "u-1" });
      return envelope({ access_token: "tok", refresh_token: "ref", id: "u-1" });
    });
    const purchase = new PurchaseService(adapter(fetch as typeof globalThis.fetch));
    await purchase.login("buyer@example.com", "secret123", "ts-token");
    expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ email: "buyer@example.com", password: "secret123", "cf-turnstile-response": "ts-token" });
    await purchase.register({ name: "Buyer", email: "new@example.com", password: "secret123", turnstileToken: "ts-reg" });
    expect(JSON.parse(String(calls[1]?.init.body))).toEqual({ name: "Buyer", email: "new@example.com", password: "secret123", "cf-turnstile-response": "ts-reg" });
    expect(JSON.parse(String(calls[2]?.init.body))).toEqual({ email: "new@example.com", password: "secret123", "cf-turnstile-response": "ts-reg" });
  });

  it("drives the password recovery, verification, and password change endpoints", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), init: init ?? {} });
      return envelope(null);
    });
    const api = adapter(fetch as typeof globalThis.fetch);
    await api.requestPasswordReset("buyer@example.com", "ts-token");
    await api.resetPassword("reset-token", "newsecret1");
    await api.verifyEmail("verify-token");
    await api.resendVerification("session-token");
    await api.changePassword("session-token", "oldsecret1", "newsecret1");
    expect(calls.map((call) => `${call.init.method} ${new URL(call.url).pathname}`)).toEqual([
      "POST /api/auth/forgot-password",
      "POST /api/auth/reset-password",
      "POST /api/auth/verify-email",
      "POST /api/auth/verify-email/resend",
      "PUT /api/profile/password",
    ]);
    expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ email: "buyer@example.com", "cf-turnstile-response": "ts-token" });
    expect(JSON.parse(String(calls[1]?.init.body))).toEqual({ token: "reset-token", password: "newsecret1", password_confirmation: "newsecret1" });
    expect(new Headers(calls[3]?.init.headers).get("Authorization")).toBe("Bearer session-token");
    expect(JSON.parse(String(calls[4]?.init.body))).toEqual({ current_password: "oldsecret1", new_password: "newsecret1" });
  });

  it("omits the Turnstile field when no token is available", async () => {
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      expect(JSON.parse(String(init?.body))).toEqual({ email: "buyer@example.com", password: "secret123" });
      return envelope({ access_token: "tok", id: "u-1" });
    });
    await adapter(fetch as typeof globalThis.fetch).login("buyer@example.com", "secret123");
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("uses generated cart, quote, and place routes with normalized minor-unit data", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), init: init ?? {} });
      if (calls.length === 1) return envelope({ id: "cart-1", token: "cart-token" });
      if (calls.length === 2) return envelope({ id: "line-1", line_total: { amount_minor: 250000, currency: "VND" }, subtotal_minor: 250000 });
      if (calls.length === 3) return envelope({ subtotal_minor: 250000, shipping_minor: 25000, tax_minor: 0, total_minor: 275000, expires_at: "2099-01-01T00:00:00Z" });
       return envelope({ id: "order-1", number: "SO-1", state: "placed", grand_total_minor: 275000, currency: "VND" });
    });
    const purchase = new PurchaseService(adapter(fetch as typeof globalThis.fetch));
    const cart = await purchase.addProduct(product);
    const quote = await purchase.quote({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard" });
    const checkout = await purchase.checkout({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard", email: "buyer@example.com" });
    await purchase.pay(checkout.id, "card", "payment-key");
    const order = await purchase.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "order-key" });
    const replay = await purchase.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "order-key" });

    expect(quote.total).toEqual({ amount_minor: 275000, currency: "VND" });
    expect(order.total).toEqual({ amount_minor: 275000, currency: "VND" });
    expect(replay.id).toBe(order.id);
    expect(calls.map((call) => new URL(call.url).pathname)).toEqual(["/api/carts", "/api/carts/cart-1/lines", "/api/carts/cart-1/quote", "/api/checkout/place", "/api/checkout/place"]);
    expect(calls[1]?.init.headers).toMatchObject({ "X-Cart-Token": "cart-token", "Content-Type": "application/json" });
    expect(JSON.parse(String(calls[1]?.init.body))).toEqual({ variant_id: "variant-1", qty: 2 });
    expect(calls[3]?.init.headers).toMatchObject({ "X-Cart-Token": "cart-token", "Idempotency-Key": "order-key" });
    expect(calls[4]?.init.headers).toMatchObject({ "X-Cart-Token": "cart-token", "Idempotency-Key": "order-key" });
    expect(JSON.parse(String(calls[3]?.init.body))).toEqual({ cart_id: "cart-1", guest_email: "buyer@example.com", payment_method: "card" });
  });

  it("keeps booking slots and flash markers without collapsing distinct lines", async () => {
    const slotA = "2026-10-05 10:00:00";
    const slotB = "2026-10-05 11:00:00";
    const fetch = vi.fn(async (input: RequestInfo | URL) => {
      if (new URL(String(input)).pathname === "/api/carts/cart-9") {
        return envelope({
          id: "cart-9", subtotal_minor: 30000,
          lines: [
            { id: "l1", variant_id: "v1", product_id: "p1", name: "Yoga", qty: 1, unit_price_minor: 10000, line_total: 10000, slot_start: slotA, booking_slot_id: "s1" },
            { id: "l2", variant_id: "v1", product_id: "p1", name: "Yoga", qty: 1, unit_price_minor: 10000, line_total: 10000, slot_start: slotB, booking_slot_id: "s2" },
            { id: "l3", variant_id: "v2", product_id: "p2", name: "Serum", qty: 1, unit_price_minor: 10000, line_total: 10000, flash_sale_id: "sale-1" },
          ],
        });
      }
      return envelope({ id: "cart-1", token: "cart-token" });
    });
    const fresh = adapter(fetch as typeof globalThis.fetch);
    (fresh as unknown as { cartId: string; cartToken?: string }).cartId = "cart-9";
    (fresh as unknown as { cartToken?: string }).cartToken = "t";
    await fresh.restoreCart();
    const lines = (fresh as unknown as { cart: { lines: { line_id?: string; slot_start?: string; flash_sale_id?: string }[] } }).cart.lines;
    expect(lines).toHaveLength(3);
    expect(lines[0]).toMatchObject({ line_id: "l1", slot_start: slotA });
    expect(lines[2]).toMatchObject({ line_id: "l3", flash_sale_id: "sale-1" });
  });

  it("sends slot_start when adding a booking line", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), init: init ?? {} });
      if (calls.length === 1) return envelope({ id: "cart-1", token: "cart-token" });
      return envelope({ id: "line-9", line_total: { amount_minor: 10000, currency: "VND" }, subtotal_minor: 10000 });
    });
    const purchase = new PurchaseService(adapter(fetch as typeof globalThis.fetch));
    await purchase.addProduct({ ...product, slot_start: "2026-10-05 10:00:00" });
    expect(JSON.parse(String(calls[1]?.init.body))).toEqual({ variant_id: "variant-1", qty: 2, slot_start: "2026-10-05 10:00:00" });
  });

  it("reconciles an ambiguous place result before allowing the caller to retry", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), init: init ?? {} });
      if (calls.length === 1) return envelope({ id: "cart-1", token: "cart-token" });
      if (calls.length === 2) return envelope({ id: "line-1", line_total: { amount_minor: 125000, currency: "VND" }, subtotal_minor: 125000 });
       if (calls.length === 3) return envelope({ subtotal_minor: 125000, shipping_minor: 0, tax_minor: 0, total_minor: 125000 });
       if (calls.length === 4) throw new TypeError("network timeout");
       return envelope([{ id: "order-1", number: "SO-1", grand_total_minor: 125000, currency: "VND", idempotency_key: "same-key" }]);
    });
    const purchase = new PurchaseService(new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch, getAccessToken: () => "session-token" }));
    const cart = await purchase.addProduct(product);
    await purchase.quote({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard" });
    const checkout = await purchase.checkout({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard", email: "buyer@example.com" });
    await purchase.pay(checkout.id, "card", "payment-key");
    const order = await purchase.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "same-key" });
    expect(order.id).toBe("order-1");
    expect(new URL(calls[4]!.url).pathname).toBe("/api/account/orders");
    expect(new Headers(calls[4]?.init.headers).get("Authorization")).toBe("Bearer session-token");
  });

  it.each([401, 403, 404, 409, 422, 429, 500])("normalizes HTTP %s as an explicit PurchaseError", async (status) => {
    const fetch = vi.fn(async () => errorResponse(status));
    const purchase = new PurchaseService(adapter(fetch as typeof globalThis.fetch));
    await expect(purchase.addProduct(product)).rejects.toMatchObject({ status, code: status === 401 ? "unauthorized" : status === 403 ? "forbidden" : status === 404 ? "not_found" : status === 409 ? "conflict" : status === 422 ? "validation" : status === 429 ? "rate_limited" : "server_error" });
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("does not turn an ambiguous mutation into a second place request", async () => {
    const fetch = vi.fn(async () => { throw new TypeError("offline"); });
    const purchase = new PurchaseService(adapter(fetch as typeof globalThis.fetch));
    await expect(purchase.addProduct(product)).rejects.toMatchObject({ code: "unknown_outcome" });
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("updates and removes a priced line without touching the other lines", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(input), init: init ?? {} });
      if (calls.length === 2) return envelope({ id: "line-1", line_total: 250000, subtotal_minor: 250000 });
      const qty = calls.length === 3 ? 3 : 1;
      const body = { id: "cart-1", lines: [{ id: "line-1", product_id: "variant-1", slug: "siro-dao", name: "Siro dao", unit_price: 125000, quantity: qty, line_total: 125000 * qty }], subtotal_minor: 125000 * qty };
      return envelope(body);
    });
    const api = adapter(fetch as typeof globalThis.fetch);
    const service = new PurchaseService(api);
    await service.addProduct(product);
    const afterUpdate = await api.setLineQuantity("cart-1", "line-1", 3);
    expect(new URL(calls[2]!.url).pathname).toBe("/api/carts/cart-1/lines/line-1");
    expect(calls[2]!.init.method).toBe("PATCH");
    expect(JSON.parse(String(calls[2]!.init.body))).toEqual({ qty: 3 });
    expect(afterUpdate.lines).toHaveLength(1);
    const afterRemove = await api.removeLine("cart-1", "line-1");
    expect(new URL(calls[3]!.url).pathname).toBe("/api/carts/cart-1/lines/line-1");
    expect(calls[3]!.init.method).toBe("DELETE");
    expect(afterRemove.lines).toHaveLength(1);
  });

  it("refuses line edits without a loaded bag", async () => {
    const api = adapter(vi.fn(async () => envelope({})) as typeof globalThis.fetch);
    await expect(api.setLineQuantity("cart-9", "line-1", 2)).rejects.toMatchObject({ code: "conflict" });
    await expect(api.removeLine("cart-9", "line-1")).rejects.toMatchObject({ code: "conflict" });
  });

  it("sends commerce scope and rejects malformed minor-unit money", async () => {
    const boundary = apiBoundary([
      { data: { id: "cart-1", token: "cart-token" } },
      { data: { line_total: { amount_minor: 125000 }, subtotal_minor: 125000 } },
      { data: { subtotal_minor: 125000, shipping_minor: 10000, tax_minor: 0, total_minor: 135000 } },
    ]);
    const purchase = new PurchaseService(new ApiPurchaseAdapter({
      baseUrl: "https://commerce.test",
      fetch: boundary.transport,
      scope: { store_id: "store-1", channel_id: "web", locale: "en", currency: "VND" },
    }));
    const cart = await purchase.addProduct(product);
    await purchase.quote({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard" });
    expect(boundary.calls[0]?.init.headers).toMatchObject({ "X-Store-Id": "store-1", "X-Channel-Id": "web" });

    const malformed = apiBoundary([{ data: { id: "cart-1" } }, { data: { line_total: { amount_minor: 1.5 }, subtotal_minor: 1.5 } }]);
    await expect(new PurchaseService(malformed.adapter()).addProduct(product)).rejects.toMatchObject({ code: "server_error" });
  });

  it.each([
    { subtotal_minor: 100, shipping_minor: 10, tax_minor: 5, total_minor: 114 },
    { subtotal_minor: 100, shipping_minor: 10, tax_minor: 5, total: { amount_minor: 115, currency: "USD" } },
  ])("rejects inconsistent quote totals", async (quote) => {
    const boundary = apiBoundary([
      { data: { id: "cart-1", token: "cart-token" } },
      { data: { line_total: { amount_minor: 125000 }, subtotal_minor: 125000 } },
      { data: { ...quote } },
    ]);
    const purchase = new PurchaseService(new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch: boundary.transport }));
    const cart = await purchase.addProduct(product);
    await expect(purchase.quote({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard" })).rejects.toMatchObject({ code: "server_error", status: 500 });
  });

  it("keeps payment idempotency stable and rejects changed payment details", async () => {
    const boundary = apiBoundary([
      { data: { id: "cart-1", token: "cart-token" } },
      { data: { line_total: { amount_minor: 250000 }, subtotal_minor: 250000 } },
      { data: { subtotal_minor: 250000, shipping_minor: 0, tax_minor: 0, total_minor: 250000 } },
    ]);
    const purchase = new PurchaseService(boundary.adapter());
    const cart = await purchase.addProduct(product);
    await purchase.quote({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard" });
    const checkout = await purchase.checkout({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard", email: "buyer@example.com" });
    const first = await purchase.pay(checkout.id, "card", "payment-key");
    expect(await purchase.pay(checkout.id, "card", "payment-key")).toEqual(first);
    await expect(purchase.pay(checkout.id, "bank_transfer", "payment-key")).rejects.toMatchObject({ code: "conflict", status: 409 });
    expect(boundary.calls).toHaveLength(3);
  });

  it.each([
    ["pending", "pending"],
    ["processing", "pending"],
    ["declined", "failed"],
    ["unknown", "unknown"],
  ] as const)("normalizes payment provider state %s as %s", async (state, expected) => {
    const boundary = apiBoundary([{ data: { id: "payment-1", state } }]);
    const adapter = new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch: boundary.transport });
    await expect(adapter.getPaymentStatus("payment-1")).resolves.toMatchObject({ id: "payment-1", status: expected });
    expect(new URL(boundary.calls[0]!.url).pathname).toBe("/api/v2/payments/payment-1/status");
  });

  it("maps generated order totals and cart line minor-unit fields", async () => {
    const boundary = apiBoundary([
      { data: { id: "cart-1", access_token: "a".repeat(64) } },
      { data: { variant_id: "variant-1", name: "Siro", qty: 2, unit_price_minor: 125000, amount_minor: 250000 } },
      { data: { subtotal_minor: 250000, discount_minor: 1000, shipping_minor: 25000, tax_minor: 0, total_minor: 274000 } },
    ]);
    const purchase = new PurchaseService(new ApiPurchaseAdapter({ baseUrl: "https://commerce.test", fetch: boundary.transport }));
    const cart = await purchase.addProduct(product);
    expect(cart.token).toBe("a".repeat(64));
    const quote = await purchase.quote({ cart_id: cart.id, shipping_address: { name: "A", line1: "1", city: "Hanoi", country: "VN", postal_code: "100000" }, shipping_method: "standard" });
    expect(quote.discount).toEqual({ amount_minor: 1000, currency: "VND" });
    expect(new URL(boundary.calls[2]!.url).pathname).toBe("/api/carts/cart-1/quote");
    expect(boundary.calls[2]!.init.method).toBe("GET");
  });
});
