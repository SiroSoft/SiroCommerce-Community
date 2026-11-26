import { describe, expect, it } from "vitest";
import { apiErrorStatus } from "@/modules/merchant/api-state";
import { merchantFixtures } from "@/modules/merchant/fixtures";
import { canOperateMerchant } from "@/modules/merchant/permissions";

describe("merchant vertical slice adapters", () => {
  it("reads HTTP status without inventing an API response shape", () => {
    expect(apiErrorStatus({ response: { status: 409 } })).toBe(409);
    expect(apiErrorStatus(new Error("unknown"))).toBeUndefined();
  });

  it("keeps fixture previews distinct from live contract capabilities", () => {
    expect(merchantFixtures.smartAction.available).toBe(false);
    expect(merchantFixtures.fulfillment.available).toBe(true);
    expect(merchantFixtures.inventory[0].onHand - merchantFixtures.inventory[0].reserved).toBe(21);
  });

  it("uses only backend capabilities and keeps unsupported roles read-only", () => {
    expect(canOperateMerchant({ role: "admin" }, "orders")).toBe(true);
    expect(canOperateMerchant({ role: "warehouse" }, "inventory")).toBe(true);
    expect(canOperateMerchant({ role: "editor" }, "orders")).toBe(false);
    expect(canOperateMerchant({ role: "viewer" }, "orders")).toBe(false);
    expect(canOperateMerchant({ role: "editor", permissions: ["orders"] }, "orders")).toBe(false);
  });
});
