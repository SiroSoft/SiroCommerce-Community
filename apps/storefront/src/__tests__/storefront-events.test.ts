import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setAnalyticsConsent, trackStorefrontEvent } from "@/services/storefront-events";

describe("storefront analytics boundary", () => {
  beforeEach(() => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "store-1");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "en");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "USD");
    setAnalyticsConsent(true);
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    window.localStorage.removeItem("sirocommerce:analytics-consent");
  });

  it("sends privacy-safe events with the complete analytics contract", async () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");

    trackStorefrontEvent("product_view", { product_id: "p-1", category: "apparel" });

    expect(beacon).toHaveBeenCalledOnce();
    const [url, body] = beacon.mock.calls[0] as unknown as [string, Blob];
    expect(url).toBe("https://api.example.test/api/analytics/track");
    expect(body).toBeInstanceOf(Blob);
    await expect(body.text()).resolves.toEqual(expect.stringContaining('"store_id":"store-1"'));
    await expect(body.text()).resolves.toEqual(expect.stringContaining('"channel_id":"web"'));
    await expect(body.text()).resolves.toEqual(expect.stringContaining('"locale":"en"'));
    await expect(body.text()).resolves.toEqual(expect.stringContaining('"currency":"USD"'));
  });

  it("does not send a malformed event when scope is unavailable", () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "");

    trackStorefrontEvent("product_view", { product_id: "p-1" });

    expect(beacon).not.toHaveBeenCalled();
  });

  it("is a no-op when no analytics API is configured", () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "");
    vi.stubEnv("NEXT_PUBLIC_API_URL", "");

    trackStorefrontEvent("checkout_started", { cart_id: "cart-1" });

    expect(beacon).not.toHaveBeenCalled();
  });

  it("allows recommendation events through the same privacy-safe boundary", () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");

    trackStorefrontEvent("recommendation_click", { recommendation_id: "product-detail-p-1", product_id: "p-2", position: 1, source: "catalog-fallback" });

    expect(beacon).toHaveBeenCalledOnce();
  });

  it("tracks assistant outcomes without sending the question text", async () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");

    trackStorefrontEvent("buyer_assistant_submit", { query_length: 12, result_count: 1, outcome: "matches" });

    const [, body] = beacon.mock.calls[0] as unknown as [string, Blob];
    expect(await body.text()).not.toContain("linen");
    expect(await body.text()).toContain("query_length");
  });

  it("does not emit before the visitor makes a consent decision", () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    window.localStorage.removeItem("sirocommerce:analytics-consent");

    trackStorefrontEvent("product_view", { product_id: "p-1" });

    expect(beacon).not.toHaveBeenCalled();
  });

  it("does not emit after the visitor opts out", () => {
    const beacon = vi.fn(() => true);
    Object.defineProperty(navigator, "sendBeacon", { configurable: true, value: beacon });
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    setAnalyticsConsent(false);

    trackStorefrontEvent("product_view", { product_id: "p-1" });

    expect(beacon).not.toHaveBeenCalled();
    window.localStorage.removeItem("sirocommerce:analytics-consent");
  });

  it("deletes bounded local personalization history on opt-out", () => {
    window.localStorage.setItem("sirocommerce:recently-viewed", JSON.stringify([{ id: "p-1", viewedAt: Date.now() }]));
    setAnalyticsConsent(false);
    expect(window.localStorage.getItem("sirocommerce:recently-viewed")).toBeNull();
    window.localStorage.removeItem("sirocommerce:analytics-consent");
  });
});
