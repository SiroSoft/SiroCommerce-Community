import { describe, expect, it } from "vitest";
import { createCommerceScope, ScopeCoordinator, scopeHeaders } from "./index";

const one = { store_id: "store", channel_id: "web", locale: "en-US", currency: "USD" };
describe("CommerceScope", () => {
  it("validates and freezes complete immutable snapshots", () => {
    const scope = createCommerceScope(one); expect(Object.isFrozen(scope)).toBe(true); expect(scope).toEqual(one);
    expect(() => createCommerceScope({ ...one, currency: "" })).toThrow("currency is required");
    expect(() => createCommerceScope({ ...one, store_id: "" })).toThrow();
  });
  it("maps all scope headers and increments generations, rejecting stale responses", () => {
    const coordinator = new ScopeCoordinator(); expect(coordinator.version).toBe(0);
    const first = coordinator.set(one); const captured = coordinator.capture();
    expect(captured.scope).not.toBe(coordinator.value); expect(captured.scope).toEqual(one); expect(scopeHeaders(captured.scope)).toEqual({ "X-Store-Id": "store", "X-Channel-Id": "web", "X-Locale": "en-US", "X-Currency": "USD" });
    coordinator.set({ ...one, store_id: "other" }); expect(coordinator.version).toBe(2); expect(coordinator.isCurrent(first)).toBe(false); expect(coordinator.isCurrent(captured.version)).toBe(false); expect(captured.scope?.store_id).toBe("store");
    expect(coordinator.clear()).toBe(3); expect(coordinator.value).toBeUndefined(); expect(scopeHeaders()).toEqual({});
  });
});
