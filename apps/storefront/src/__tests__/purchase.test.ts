import { describe, expect, it } from "vitest";
import { fixtureProduct } from "../fixtures/purchase.js";
import { PurchaseError } from "../purchase/contracts.js";
import { FixturePurchaseAdapter } from "../purchase/fixture-adapter.js";
import { PurchaseService } from "../purchase/service.js";

const address = { name: "Nguyen Van A", line1: "1 Main St", city: "Hanoi", country: "VN", postal_code: "100000" };

async function prepared() {
  const adapter = new FixturePurchaseAdapter();
  const service = new PurchaseService(adapter);
  const cart = await service.addProduct(fixtureProduct);
  const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" });
  const checkout = await service.checkout({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", email: "buyer@example.com" });
  await service.pay(checkout.id, "card", "payment-key-1");
  return { adapter, service, cart, quote, checkout };
}

describe("purchase vertical slice", () => {
  it("calculates cart and quote totals from API minor-unit money", async () => {
    const { cart, quote } = await prepared();
    expect(cart.subtotal).toEqual({ amount_minor: 125000, currency: "USD" });
    expect(quote.total).toEqual({ amount_minor: 162500, currency: "USD" });
  });

  it("returns typed 422 validation errors for quote and checkout", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    await service.addProduct(fixtureProduct);
    await expect(service.quote({ cart_id: "cart-fixture-1", shipping_address: { ...address, city: "" }, shipping_method: "standard" })).rejects.toMatchObject({ status: 422, code: "validation" });
    await expect(service.checkout({ cart_id: "cart-fixture-1", shipping_address: address, shipping_method: "standard", email: "bad" })).rejects.toMatchObject({ status: 422, fieldErrors: { email: ["Enter a valid email"] } });
  });

  it("reconciles a timed-out place mutation instead of placing twice", async () => {
    const { adapter, service, checkout } = await prepared();
    adapter.simulateOrderTimeoutOnce();
    const input = { checkout_id: checkout.id, payment_method: "card" as const, idempotency_key: "order-key-1" };
    const order = await service.place(input);
    const replay = await service.place(input);
    expect(order.id).toBe("order-fixture-1");
    expect(replay.id).toBe(order.id);
  });

  it("polls reconciliation a bounded number of times and permits manual retry", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    const input = { checkout_id: "checkout-fixture-1", payment_method: "card" as const, idempotency_key: "not-yet-created" };
    await expect(service.reconcile(input, { attempts: 2 })).resolves.toBeNull();
    const preparedOrder = await prepared();
    preparedOrder.adapter.simulateOrderTimeoutOnce();
    const orderInput = { checkout_id: preparedOrder.checkout.id, payment_method: "card" as const, idempotency_key: "manual-retry" };
    await expect(preparedOrder.service.place(orderInput)).resolves.toMatchObject({ id: "order-fixture-1" });
    await expect(preparedOrder.service.reconcile(orderInput, { attempts: 1 })).resolves.toMatchObject({ id: "order-fixture-1" });
  });

  it("rejects idempotency-key reuse for a different payload", async () => {
    const { service, checkout } = await prepared();
    const key = "order-key-2";
    await service.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: key });
    await expect(service.place({ checkout_id: checkout.id, payment_method: "bank_transfer", idempotency_key: key })).rejects.toMatchObject({ status: 409, code: "conflict" });
  });

  it("keeps explicit auth and rate-limit states available to callers", () => {
    expect(new PurchaseError("unauthorized", "Login required", { status: 401 }).status).toBe(401);
    expect(new PurchaseError("forbidden", "Not allowed", { status: 403 }).status).toBe(403);
    expect(new PurchaseError("rate_limited", "Try later", { status: 429 }).status).toBe(429);
    expect(new PurchaseError("server_error", "Unavailable", { status: 500 }).status).toBe(500);
  });

  it("fails closed when payment is declined and allows a later authorization", async () => {
    const { adapter, service, checkout } = await prepared();
    adapter.simulatePaymentFailureOnce();
    const failed = await service.pay(checkout.id, "card", "payment-decline");
    expect(failed).toMatchObject({ status: "failed", failure_reason: "Fixture payment was declined" });
    await expect(service.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "declined-order" })).rejects.toMatchObject({ code: "conflict", status: 409 });
    expect((await service.pay(checkout.id, "card", "payment-retry")).status).toBe("authorized");
    await expect(service.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "declined-order" })).resolves.toMatchObject({ id: "order-fixture-1" });
  });

  it("fails closed when payment outcome is unknown and never places the order", async () => {
    const { adapter, service, checkout } = await prepared();
    adapter.simulatePaymentUnknownOnce();
    const payment = await service.pay(checkout.id, "card", "payment-unknown");

    expect(payment.status).toBe("unknown");
    await expect(service.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "unknown-order" })).rejects.toMatchObject({ code: "conflict", status: 409 });
  });

  it("invalidates a quote and payment when the cart scope changes", async () => {
    const { adapter, service } = await prepared();
    await service.addProduct({ ...fixtureProduct, product_id: "siro-chanh", slug: "siro-chanh", quantity: 1 });
    await expect(service.checkout({ cart_id: "cart-fixture-1", shipping_address: address, shipping_method: "standard", email: "buyer@example.com" })).rejects.toMatchObject({ code: "conflict", status: 409 });
    expect(adapter).toBeDefined();
  });

  it("fails closed for an empty cart and an expired quote", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    await expect(service.quote({ cart_id: "cart-fixture-1", shipping_address: address, shipping_method: "standard" })).rejects.toMatchObject({ code: "validation", status: 422 });
    const cart = await service.addProduct(fixtureProduct);
    await expect(service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" })).resolves.toMatchObject({ total: { amount_minor: 162500 } });
    adapter.setQuoteExpiry("2000-01-01T00:00:00.000Z");
    await expect(service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" })).rejects.toMatchObject({ code: "conflict", status: 409 });
  });

  it("does not allow a currency switch within one cart", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    await service.addProduct(fixtureProduct);
    await expect(service.addProduct({ ...fixtureProduct, unit_price: { amount_minor: 1250, currency: "VND" } })).rejects.toMatchObject({ code: "conflict", status: 409 });
  });

  it("does not duplicate concurrent payment authorization", async () => {
    const { adapter, service, checkout } = await prepared();
    const original = adapter.authorizePayment.bind(adapter);
    let calls = 0;
    adapter.authorizePayment = async (...args) => { calls += 1; await new Promise((resolve) => setTimeout(resolve, 5)); return original(...args); };
    const [first, second] = await Promise.all([
      service.pay(checkout.id, "card", "same-payment-key"),
      service.pay(checkout.id, "card", "same-payment-key"),
    ]);
    expect(first).toEqual(second);
    expect(calls).toBe(1);
  });

  it("rejects malformed address and contact values without throwing", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    await service.addProduct(fixtureProduct);
    await expect(service.quote({ cart_id: "cart-fixture-1", shipping_address: { ...address, city: undefined as unknown as string }, shipping_method: "standard" })).rejects.toMatchObject({ code: "validation", status: 422 });
    await expect(service.checkout({ cart_id: "cart-fixture-1", shipping_address: address, shipping_method: "standard", email: undefined as unknown as string })).rejects.toMatchObject({ code: "validation", status: 422 });
  });

  it("supports customer login, account, and order history after checkout", async () => {
    const { service } = await prepared();
    const session = await service.login("buyer@example.com", "fixture-password");
    expect(await service.account(session.access_token)).toMatchObject({ email: "buyer@example.com" });
    expect(await service.orderHistory(session.access_token)).toHaveLength(0);
    await expect(service.login("buyer@example.com", "wrong")).rejects.toMatchObject({ status: 401 });
    await expect(service.login("rate-limit@example.com", "wrong")).rejects.toMatchObject({ status: 429 });
    await expect(service.account("forbidden-token")).rejects.toMatchObject({ status: 403 });
  });
});
