import { describe, expect, it } from "vitest";
import { financialUnavailable, validateCommerceContext } from "../purchase/b2b.js";
import { FixturePurchaseAdapter } from "../purchase/fixture-adapter.js";
import { PurchaseService } from "../purchase/service.js";

describe("B2B storefront context", () => {
  it("exposes organization pricing, tiers, policy, ownership, and fail-closed financial capabilities", async () => {
    const context = await new FixturePurchaseAdapter().getCommerceContext();
    expect(context).toMatchObject({ organization: { id: "org-fixture-1" }, pricing: { customer: { source: "customer" } }, orderPolicy: { minimumQuantity: 1 }, marketplace: { ownerType: "vendor" } });
    expect(context.credit.available).toBe(false);
    expect(context.payment.available).toBe(false);
  });

  it("supports quote request and approval status without enabling payment", async () => {
    const adapter = new FixturePurchaseAdapter();
    const service = new PurchaseService(adapter);
    const requested = await service.requestQuote("cart-fixture-1");
    expect(requested.status).toBe("submitted");
    expect((await service.approveQuote(requested.id)).status).toBe("approved");
  });

  it("rejects an enabled financial capability instead of failing open", () => {
    expect(() => validateCommerceContext({
      organization: { id: "org", name: "Org" }, customer: { id: "customer" }, store: { id: "store", name: "Store", currency: "VND" },
      pricing: {}, credit: { available: true, reason: "bad" } as never, payout: financialUnavailable("off"), payment: financialUnavailable("off"),
    })).toThrow(/fail closed/);
  });
});
