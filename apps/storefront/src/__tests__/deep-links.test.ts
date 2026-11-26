import { describe, expect, it } from "vitest";
import { createDeepLink, parseDeepLink, scopedStorageKey } from "../lib/deep-links";
const scope = { store_id: "store-a", channel_id: "mobile", locale: "vi", currency: "VND" } as const;
describe("channel-aware deep links", () => {
  it("round trips references without price or state", () => {
    const url = createDeepLink({ target: "order", scope, orderId: "order_1", guestToken: "opaque-token", quantity: 2 });
    expect(url).toContain("store=store-a"); expect(url).not.toContain("price"); expect(url).not.toContain("state");
    expect(parseDeepLink(url, scope).link).toMatchObject({ orderId: "order_1", guestToken: "opaque-token", scope });
  });
  it("rejects tampered identifiers and scope changes", () => {
    expect(parseDeepLink("/product?store=store-a&channel=mobile&locale=vi&currency=VND&product=../secret", scope).error).toBe("invalid");
    expect(parseDeepLink("/product?store=store-a&channel=mobile&locale=vi&currency=VND", scope).error).toBe("invalid");
    expect(parseDeepLink("/cart?store=other&channel=mobile&locale=vi&currency=VND", scope).error).toBe("scope_mismatch");
  });
  it("requires a guest capability for an unauthenticated order handoff", () => {
    expect(parseDeepLink("/order-result?store=store-a&channel=mobile&locale=vi&currency=VND&order=order_1", scope).error).toBe("missing_capability");
    expect(scopedStorageKey(scope, "order")).toContain("mobile");
  });
});
