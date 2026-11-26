import { describe, expect, it } from "vitest";
import { marketingConsent, setMarketingConsent } from "@/lib/marketing-consent";

describe("marketing consent boundary", () => {
  it("fails closed and honors unsubscribe", () => {
    window.localStorage.removeItem("sirocommerce:marketing-consent");
    expect(marketingConsent()).toBe(false);
    setMarketingConsent(true);
    expect(marketingConsent()).toBe(true);
    setMarketingConsent(false);
    expect(marketingConsent()).toBe(false);
  });
});
