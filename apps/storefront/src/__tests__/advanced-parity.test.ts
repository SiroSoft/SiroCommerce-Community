import { describe, expect, it } from "vitest";
import { fixtureProduct } from "../fixtures/purchase.js";
import { FixturePurchaseAdapter } from "../purchase/fixture-adapter.js";
import { PurchaseError } from "../purchase/contracts.js";
import { PurchaseService } from "../purchase/service.js";

const address = { name: "Nguyen Van A", line1: "1 Main St", city: "Hanoi", country: "VN", postal_code: "100000" };

describe("advanced storefront purchase parity", () => {
  it("applies an eligible voucher in the cart currency", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    const cart = await service.addProduct(fixtureProduct);
    const voucher = await service.previewVoucher(cart, "save10");
    expect(voucher).toMatchObject({ code: "SAVE10", eligibility: "eligible", discount: { amount_minor: 12500, currency: "USD" } });
    const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", coupon_code: voucher.code });
    expect(quote.total).toEqual({ amount_minor: 150000, currency: "USD" });
  });

  it.each(["EXPIRED", "DUPLICATE"]) ("fails closed for a %s voucher", async (code) => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    const cart = await service.addProduct(fixtureProduct);
    await expect(service.previewVoucher(cart, code)).rejects.toBeInstanceOf(PurchaseError);
  });

  it("rejects a voucher whose discount currency or amount is unsafe", async () => {
    const adapter = new FixturePurchaseAdapter();
    adapter.previewVoucher = async () => ({ code: "BAD", eligibility: "eligible", discount: { amount_minor: 999999, currency: "VND" } });
    const service = new PurchaseService(adapter);
    const cart = await service.addProduct(fixtureProduct);
    await expect(service.previewVoucher(cart, "BAD")).rejects.toMatchObject({ code: "server_error", status: 500 });
  });

  it("tenders a gift card through quote to checkout", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    const cart = await service.addProduct(fixtureProduct);
    const gift = await service.previewGift(cart, "gift50");
    expect(gift).toMatchObject({ code: "GIFT50", usable: { amount_minor: 50000, currency: "USD" }, balance: { amount_minor: 50000, currency: "USD" } });
    await expect(service.previewGift(cart, "NOPE")).rejects.toBeInstanceOf(PurchaseError);
    const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", gift_code: gift.code });
    expect(quote.gift_discount).toEqual({ amount_minor: 50000, currency: "USD" });
    expect(quote.total).toEqual({ amount_minor: 112500, currency: "USD" });
    const checkout = await service.checkout({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", email: "buyer@example.com", gift_code: gift.code });
    expect(checkout.gift_code).toBe("GIFT50");
  });

  it("rejects a gift tender whose usable amount is unsafe", async () => {
    const adapter = new FixturePurchaseAdapter();
    adapter.previewGift = async () => ({ code: "BAD", usable: { amount_minor: 999999, currency: "USD" }, balance: { amount_minor: 999999, currency: "USD" } });
    const service = new PurchaseService(adapter);
    const cart = await service.addProduct(fixtureProduct);
    await expect(service.previewGift(cart, "BAD")).rejects.toMatchObject({ code: "server_error", status: 500 });
  });

  it("stacks two gift codes as a split tender in order", async () => {
    const service = new PurchaseService(new FixturePurchaseAdapter());
    const cart = await service.addProduct(fixtureProduct);
    const first = await service.previewGift(cart, "gift50");
    const second = await service.previewGift(cart, "gift25");
    const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", gift_codes: [first.code, second.code] });
    expect(quote.gift_codes).toEqual(["GIFT50", "GIFT25"]);
    expect(quote.gift_discount).toEqual({ amount_minor: 75000, currency: "USD" });
    expect(quote.gift_tenders).toEqual([
      { code: "GIFT50", covered: { amount_minor: 50000, currency: "USD" } },
      { code: "GIFT25", covered: { amount_minor: 25000, currency: "USD" } },
    ]);
    expect(quote.total).toEqual({ amount_minor: 87500, currency: "USD" });
    const checkout = await service.checkout({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", email: "buyer@example.com", gift_codes: [first.code, second.code] });
    expect(checkout.gift_codes).toEqual(["GIFT50", "GIFT25"]);
  });

  it("quotes store pickup with zero shipping and a ready-by SLA", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    const cart = await service.addProduct(fixtureProduct);
    const points = await adapter.getPickupPoints?.();
    expect(points).toMatchObject([{ code: "HN-01", name: "Hoan Kiem Store", sla_hours: 24 }]);
    const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", fulfillment_method: "pickup", pickup_point: "HN-01" });
    expect(quote.shipping).toEqual({ amount_minor: 0, currency: "USD" });
    expect(quote.pickup?.code).toBe("HN-01");
    expect(typeof quote.pickup?.ready_by).toBe("string");
    await expect(service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", fulfillment_method: "pickup", pickup_point: "NOPE" })).rejects.toBeInstanceOf(PurchaseError);
    const checkout = await service.checkout({ cart_id: cart.id, shipping_address: address, shipping_method: "standard", email: "buyer@example.com", fulfillment_method: "pickup", pickup_point: "HN-01" });
    expect(checkout.fulfillment_method).toBe("pickup");
  });

  it("validates member price and deal totals from the server quote", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    const cart = await service.addProduct(fixtureProduct);
    const base = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" });
    const quoted = {
      ...base,
      subtotal: { amount_minor: 20000, currency: "USD" },
      member_discount: { amount_minor: 5000, currency: "USD" },
      member_deal: { id: "d1", name: "Gold week", percent_off: 10, discount: { amount_minor: 1500, currency: "USD" } },
      // 20000 + base.shipping + base.tax - 5000 member - 1500 deal.
      total: { amount_minor: 20000 + base.shipping.amount_minor + base.tax.amount_minor - 6500, currency: "USD" },
    };
    adapter.createQuote = async () => quoted;
    const quote = await service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" });
    expect(quote.member_deal?.name).toBe("Gold week");
    adapter.createQuote = async () => ({ ...quoted, member_deal: { id: "d1", name: "Bad", percent_off: 99, discount: { amount_minor: 1500, currency: "USD" } } });
    await expect(service.quote({ cart_id: cart.id, shipping_address: address, shipping_method: "standard" })).rejects.toMatchObject({ code: "server_error", status: 500 });
  });
});
