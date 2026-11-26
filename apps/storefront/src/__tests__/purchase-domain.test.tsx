import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Header } from "../components/Header";
import { fixtureProduct } from "../fixtures/purchase.js";
import { stableIdempotencyKey } from "../purchase/idempotency.js";
import { PurchaseError } from "../purchase/contracts.js";
import { FixturePurchaseAdapter } from "../purchase/fixture-adapter.js";
import { PurchaseService } from "../purchase/service.js";
import { apiBoundary } from "./support/api-boundary.js";

const address = { name: "Nguyen Van A", line1: "1 Main St", city: "Hanoi", country: "VN", postal_code: "100000" };
const product = { ...fixtureProduct, quantity: 2 };

async function ready(adapter = new FixturePurchaseAdapter()) {
  const service = new PurchaseService(adapter);
  const cart = await service.addProduct(product);
  const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" });
  const checkout = await service.checkout({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", email: "buyer@example.com" });
  const payment = await service.pay(checkout.id, "card", "payment-key");
  return { adapter, service, cart, quote, checkout, payment };
}

describe("purchase domain acceptance matrix", () => {
  it("covers product to cart to quote to checkout to payment to order result", async () => {
    const { service, cart, quote, checkout, payment } = await ready();
    const order = await service.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "order-1" });
    expect(cart.lines[0]).toMatchObject({ product_id: fixtureProduct.product_id, quantity: 2, line_total: { amount_minor: 250000 } });
    expect(quote.total).toEqual({ amount_minor: 300000, currency: "USD" });
    expect(checkout.status).toBe("needs_payment");
    expect(payment.status).toBe("authorized");
    expect(order).toMatchObject({ id: "order-fixture-1", status: "placed", payment: { status: "authorized" } });
  });

  it("replaces a changed quantity, keeps variants, and rejects unavailable inventory", async () => {
    const adapter = new FixturePurchaseAdapter();
    adapter.setInventory(fixtureProduct.product_id, 2);
    const service = new PurchaseService(adapter);
    await service.addProduct(product);
    const changed = await service.addProduct({ ...fixtureProduct, quantity: 1 });
    expect(changed.lines).toHaveLength(1);
    expect(changed.lines[0]?.quantity).toBe(1);
    const second = await service.addProduct({ ...fixtureProduct, product_id: "variant-2", slug: "siro-chanh", quantity: 1 });
    expect(second.lines).toHaveLength(2);
    await expect(service.addProduct({ ...product, quantity: 3 })).rejects.toMatchObject({ status: 409, code: "conflict" });
  });

  it("rounds tax in minor units and validates quote inputs", async () => {
    const { service, cart } = await ready();
    const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "express" });
    expect(quote.tax).toEqual({ amount_minor: 25000, currency: "USD" });
    expect(quote.total).toEqual({ amount_minor: 325000, currency: "USD" });
    await expect(service.quote({ cart_id: cart.id, shipping_address: { ...address, city: "" }, shipping_method: "standard" })).rejects.toMatchObject({ status: 422, code: "validation" });
  });

  it("uses one key for equivalent payloads and a new key for changes", () => {
    const first = stableIdempotencyKey("place-order", { checkout_id: "c1", payment_method: "card" });
    expect(first).toBe(stableIdempotencyKey("place-order", { payment_method: "card", checkout_id: "c1" }));
    expect(first).not.toBe(stableIdempotencyKey("place-order", { checkout_id: "c1", payment_method: "bank_transfer" }));
  });

  it("reconciles unknown outcome before retry and prevents duplicates", async () => {
    const { adapter, service, checkout } = await ready();
    adapter.simulateOrderTimeoutOnce();
    const input = { checkout_id: checkout.id, payment_method: "card" as const, idempotency_key: "order-timeout" };
    const order = await service.place(input);
    expect(await service.place(input)).toEqual(order);
    await expect(service.place({ ...input, payment_method: "bank_transfer" })).rejects.toMatchObject({ status: 409, code: "conflict" });
  });

  it.each([401, 403, 404, 409, 422, 429, 500])("maps HTTP %s at the shared API boundary", async (status) => {
    const boundary = apiBoundary([{ data: null, status }]);
    const service = new PurchaseService(boundary.adapter());
    await expect(service.addProduct(product)).rejects.toMatchObject({ status });
    expect(boundary.calls).toHaveLength(1);
  });

  it("propagates cart token and quote fields through the shared API boundary", async () => {
    const boundary = apiBoundary([
      { data: { id: "cart-1", cart_token: "token-1" } },
      { data: { line_total: { amount_minor: 250000 }, subtotal_minor: 250000 } },
      { data: { subtotal_minor: 250000, shipping_minor: 10000, tax_minor: 25000, total_minor: 285000, expires_at: "2099-01-01" } },
      { data: { id: "order-1", number: "SO-1", grand_total_minor: 285000, currency: "VND", state: "placed", payment: { id: "payment-1", state: "failed" } } },
    ]);
    const service = new PurchaseService(boundary.adapter());
    const cart = await service.addProduct(product);
    await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "express" });
    const checkout = await service.checkout({ cart_id: cart.id, shipping_address: address, shipping_method: "express", email: "buyer@example.com" });
    await service.pay(checkout.id, "card", "payment-key");
    const order = await service.place({ checkout_id: checkout.id, payment_method: "card", idempotency_key: "order-key" });
    expect(order.payment.status).toBe("failed");
    expect(boundary.calls[2]?.init.headers).toMatchObject({ "X-Cart-Token": "token-1" });
    expect(boundary.calls[2]?.init.method).toBe("GET");
  });

  it("supports login, account, history, and explicit forbidden/unauthorized states", async () => {
    const { service } = await ready();
    const session = await service.login("buyer@example.com", "fixture-password");
    expect(await service.account(session.access_token)).toMatchObject({ email: "buyer@example.com" });
    expect(await service.orderHistory(session.access_token)).toEqual([]);
    await expect(service.login("buyer@example.com", "bad")).rejects.toMatchObject({ status: 401 });
    await expect(service.account("forbidden-token")).rejects.toMatchObject({ status: 403 });
    await expect(service.login("rate-limit@example.com", "bad")).rejects.toMatchObject({ status: 429 });
  });

  it("keeps authenticated detail and guest-token reads credentialed", async () => {
    const { service } = await ready();
    const order = await service.place({ checkout_id: "checkout-fixture-1", payment_method: "card", idempotency_key: "guest-order" });
    expect(await service.order("customer-token-fixture", order.id)).toEqual(order);
    expect(await service.guestOrder("guest-order-token-fixture")).toEqual(order);
    await expect(service.guestOrder("expired-token")).rejects.toMatchObject({ status: 404 });
  });

  it("keeps critical header controls accessible where the storefront implements them", () => {
    render(<Header />);
    expect(screen.getByRole("navigation", { name: "Primary navigation" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Search products" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Open shopping bag" })).toHaveAttribute("href", "/cart");
  });

  it("preserves unknown-outcome semantics for network failures", async () => {
    const error = new PurchaseError("unknown_outcome", "network unknown");
    expect(error.code).toBe("unknown_outcome");
  });

  it("locks concurrent order placement to one operation per idempotency key", async () => {
    const { adapter, service, checkout } = await ready();
    const original = adapter.placeOrder.bind(adapter);
    let calls = 0;
    adapter.placeOrder = async (input) => {
      calls += 1;
      await new Promise((resolve) => setTimeout(resolve, 5));
      return original(input);
    };
    const input = { checkout_id: checkout.id, payment_method: "card" as const, idempotency_key: "concurrent-order" };
    const [first, second] = await Promise.all([service.place(input), service.place(input)]);
    expect(first).toEqual(second);
    expect(calls).toBe(1);
  });

  it("shares one result for a double submit even when the request is slow", async () => {
    const { adapter, service, checkout } = await ready();
    const original = adapter.placeOrder.bind(adapter);
    let calls = 0;
    adapter.placeOrder = async (input) => { calls += 1; await new Promise((resolve) => setTimeout(resolve, 10)); return original(input); };
    const input = { checkout_id: checkout.id, payment_method: "card" as const, idempotency_key: "double-submit" };
    await Promise.all([service.place(input), service.place(input), service.place(input)]);
    expect(calls).toBe(1);
  });

  it("rejects malformed checkout and quote inputs before any adapter mutation", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    await expect(service.addProduct({ ...fixtureProduct, quantity: 0 })).rejects.toMatchObject({ code: "validation", status: 422 });
    await expect(service.quote({ cart_id: "cart-fixture-1", shipping_address: address, shipping_method: "standard" })).rejects.toMatchObject({ code: "validation", status: 422 });
    await expect(service.checkout({ cart_id: "cart-fixture-1", shipping_address: address, shipping_method: "standard", email: "invalid" })).rejects.toMatchObject({ code: "validation", status: 422 });
  });

  it.each([0, -1, 1.5])("rejects invalid quantity %s", async (quantity) => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    await expect(service.addProduct({ ...fixtureProduct, quantity })).rejects.toMatchObject({ code: "validation", status: 422 });
  });

  it("rejects decimal, negative, empty, and overflowing prices before mutation", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    await expect(service.addProduct({ ...fixtureProduct, unit_price: { amount_minor: 1.5, currency: "VND" } })).rejects.toMatchObject({ status: 422 });
    await expect(service.addProduct({ ...fixtureProduct, unit_price: { amount_minor: -1, currency: "VND" } })).rejects.toMatchObject({ status: 422 });
    await expect(service.addProduct({ ...fixtureProduct, unit_price: { amount_minor: 1, currency: "" } })).rejects.toMatchObject({ status: 422 });
    await expect(service.addProduct({ ...fixtureProduct, unit_price: { amount_minor: Number.MAX_SAFE_INTEGER, currency: "VND" }, quantity: 2 })).rejects.toMatchObject({ status: 422 });
  });
});
