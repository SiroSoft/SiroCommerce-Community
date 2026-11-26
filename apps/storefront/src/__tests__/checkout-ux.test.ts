import { describe, expect, it } from "vitest";
import { checkoutSteps, promoFeedback, quoteBreakdown, validateCheckoutFields, validateQuantity } from "../purchase/checkout-ux";

describe("checkout conversion UX helpers", () => {
  it("accepts whole-number quantities and explains invalid input", () => {
    expect(validateQuantity("2")).toEqual({ valid: true, value: 2 });
    expect(validateQuantity("1.5")).toMatchObject({ valid: false, message: expect.stringContaining("whole") });
    expect(validateQuantity(0)).toMatchObject({ valid: false, message: expect.stringContaining("at least") });
  });

  it("keeps a complete price breakdown when a quote is available", () => {
    const quote = { subtotal: { amount_minor: 200, currency: "VND" }, discount: { amount_minor: 20, currency: "VND" }, shipping: { amount_minor: 10, currency: "VND" }, tax: { amount_minor: 19, currency: "VND" }, total: { amount_minor: 209, currency: "VND" } } as never;
    expect(quoteBreakdown(quote, { subtotal: 0 })).toEqual({ subtotal: 200, discount: 20, giftDiscount: 0, memberDiscount: 0, memberDealDiscount: 0, memberDealName: undefined, shipping: 10, tax: 19, total: 209 });
    const gifted = { subtotal: { amount_minor: 200, currency: "VND" }, gift_discount: { amount_minor: 50, currency: "VND" }, shipping: { amount_minor: 10, currency: "VND" }, tax: { amount_minor: 19, currency: "VND" }, total: { amount_minor: 179, currency: "VND" } } as never;
    expect(quoteBreakdown(gifted, { subtotal: 0 })).toEqual({ subtotal: 200, discount: 0, giftDiscount: 50, memberDiscount: 0, memberDealDiscount: 0, memberDealName: undefined, shipping: 10, tax: 19, total: 179 });
    const membered = { subtotal: { amount_minor: 200, currency: "VND" }, member_discount: { amount_minor: 30, currency: "VND" }, member_deal: { id: "d1", name: "Gold week", percent_off: 10, discount: { amount_minor: 17, currency: "VND" } }, shipping: { amount_minor: 10, currency: "VND" }, tax: { amount_minor: 19, currency: "VND" }, total: { amount_minor: 182, currency: "VND" } } as never;
    expect(quoteBreakdown(membered, { subtotal: 0 })).toEqual({ subtotal: 200, discount: 0, giftDiscount: 0, memberDiscount: 30, memberDealDiscount: 17, memberDealName: "Gold week", shipping: 10, tax: 19, total: 182 });
    expect(checkoutSteps).toEqual(["cart", "quote", "checkout", "order-result"]);
  });

  it("validates the guest checkout fields without contacting a provider", () => {
    expect(validateCheckoutFields({ name: "", line1: "", city: "", country: "", postal_code: "" }, "bad")).toMatchObject({
      email: [expect.any(String)], name: [expect.any(String)], postal_code: [expect.any(String)],
    });
    expect(validateCheckoutFields({ name: "A", line1: "1 Main", city: "Hanoi", country: "VN", postal_code: "100000" }, "a@example.com")).toEqual({});
  });

  it("does not invent delivery totals before the server quote", () => {
    expect(quoteBreakdown(undefined, { subtotal: 200 })).toEqual({ subtotal: 200, discount: 0, giftDiscount: 0, memberDiscount: 0, memberDealDiscount: 0, memberDealName: undefined, shipping: undefined, tax: undefined, total: undefined });
  });

  it("gives explicit promotion feedback", () => {
    expect(promoFeedback("SAVE10", 100)).toContain("SAVE10 applied");
  });
});
