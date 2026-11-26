import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { setAnalyticsConsent, trackWebVitals } from "@/services/storefront-events";

describe("web vitals beacon", () => {
  const observers: Array<{ callback: (list: { getEntries: () => unknown[] }) => void; options: unknown }> = [];
  let fetchCalls: Array<{ url: string; init: RequestInit }> = [];

  beforeEach(() => {
    observers.length = 0;
    fetchCalls = [];
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "default");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "en");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "VND");
    setAnalyticsConsent(true);
    vi.stubGlobal("PerformanceObserver", class {
      callback: (list: { getEntries: () => unknown[] }) => void;
      constructor(callback: (list: { getEntries: () => unknown[] }) => void) {
        this.callback = callback;
        observers.push({ callback, options: undefined });
      }
      observe() { /* capture only */ }
    });
    vi.stubGlobal("fetch", vi.fn(async (url: string, init: RequestInit) => {
      fetchCalls.push({ url, init });
      return new Response("{}", { status: 200 });
    }));
    Object.defineProperty(window.navigator, "sendBeacon", { value: undefined, configurable: true });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
    window.localStorage.removeItem("sirocommerce:analytics-consent");
  });

  it("reports LCP once on pagehide with rating", () => {
    trackWebVitals();
    expect(observers.length).toBeGreaterThan(0);
    observers[0]?.callback({ getEntries: () => [{ renderTime: 1800 }] });
    window.dispatchEvent(new Event("pagehide"));
    window.dispatchEvent(new Event("pagehide"));
    const bodies = fetchCalls.map((call) => JSON.parse(String(call.init.body)));
    const vitals = bodies.filter((body) => body.event === "web_vitals");
    expect(vitals).toHaveLength(1);
    expect(vitals[0].properties).toMatchObject({ metric: "lcp", value_ms: 1800, rating: "good" });
  });

  it("stays silent without analytics consent", () => {
    setAnalyticsConsent(false);
    trackWebVitals();
    observers[0]?.callback({ getEntries: () => [{ renderTime: 1800 }] });
    window.dispatchEvent(new Event("pagehide"));
    expect(fetchCalls).toHaveLength(0);
  });

  it("stays silent without PerformanceObserver", () => {
    vi.stubGlobal("PerformanceObserver", undefined);
    expect(() => trackWebVitals()).not.toThrow();
    expect(fetchCalls).toHaveLength(0);
  });
});
