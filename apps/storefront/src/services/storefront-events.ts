import { clearRecentlyViewed } from "./recently-viewed";
import { storefrontSessionId } from "@/lib/storefront-session";

export type StorefrontEventName = "product_view" | "product_click" | "wishlist_add" | "wishlist_remove" | "search_submit" | "search_click" | "checkout_started" | "recommendation_impression" | "recommendation_click" | "experiment_exposure" | "buyer_assistant_submit" | "buyer_assistant_click" | "web_vitals";

type VitalRating = "good" | "needs-improvement" | "poor";

function vitalRating(metric: "lcp" | "inp" | "cls", value: number): VitalRating {
  if (metric === "lcp") return value <= 2500 ? "good" : value <= 4000 ? "needs-improvement" : "poor";
  if (metric === "inp") return value <= 200 ? "good" : value <= 500 ? "needs-improvement" : "poor";
  return value <= 0.1 ? "good" : value <= 0.25 ? "needs-improvement" : "poor";
}

/**
 * Real-user Core Web Vitals via PerformanceObserver, reported once per page
 * on pagehide. Best effort: unsupported browsers simply send nothing.
 * Respects the same analytics consent gate as every other beacon.
 */
export function trackWebVitals(): void {
  if (typeof window === "undefined" || typeof PerformanceObserver === "undefined") return;
  let lcp = 0;
  let cls = 0;
  let inp = 0;
  let sent = false;
  const flush = () => {
    if (sent) return;
    // Core Web Vitals are analytics too: stay silent without explicit consent.
    if (!analyticsConsent()) return;
    sent = true;
    const page = window.location.pathname.slice(0, 128) || "/";
    if (lcp > 0) trackStorefrontEvent("web_vitals", { metric: "lcp", value_ms: Math.round(lcp), rating: vitalRating("lcp", lcp), page });
    if (cls > 0) trackStorefrontEvent("web_vitals", { metric: "cls", value_ms: Math.round(cls * 1000), rating: vitalRating("cls", cls), page });
    if (inp > 0) trackStorefrontEvent("web_vitals", { metric: "inp", value_ms: Math.round(inp), rating: vitalRating("inp", inp), page });
  };
  try {
    const lcpObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        const value = (entry as PerformanceEntry & { renderTime?: number; loadTime?: number }).renderTime ?? (entry as PerformanceEntry & { loadTime?: number }).loadTime ?? 0;
        if (value > lcp) lcp = value;
      }
    });
    lcpObserver.observe({ type: "largest-contentful-paint", buffered: true });
  } catch { /* LCP unsupported; CLS/INP still report. */ }
  try {
    const clsObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        const shift = entry as PerformanceEntry & { hadRecentInput?: boolean; value?: number };
        if (!shift.hadRecentInput && typeof shift.value === "number") cls += shift.value;
      }
    });
    clsObserver.observe({ type: "layout-shift", buffered: true });
  } catch { /* CLS unsupported. */ }
  try {
    const inpObserver = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        const duration = (entry as PerformanceEntry & { duration?: number }).duration ?? 0;
        if (duration > inp) inp = duration;
      }
    });
    inpObserver.observe({ type: "event", buffered: true, durationThreshold: 40 } as PerformanceObserverInit);
  } catch { /* INP unsupported. */ }
  window.addEventListener("pagehide", flush, { once: true });
}

type EventPayload = Record<string, string | number | boolean | undefined>;
const consentKey = "sirocommerce:analytics-consent";

export function setAnalyticsConsent(granted: boolean): void {
  if (typeof window !== "undefined") window.localStorage.setItem(consentKey, granted ? "granted" : "denied");
  if (!granted) clearRecentlyViewed();
}

export function analyticsConsent(): boolean {
  if (typeof window === "undefined") return false;
  return window.localStorage.getItem(consentKey) === "granted";
}

export function trackStorefrontEvent(event: StorefrontEventName, payload: EventPayload = {}): void {
  if (typeof window === "undefined") return;
  // No analytics before an explicit opt-in. Necessary-only = no tracking.
  if (!analyticsConsent()) return;
  const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL || process.env.NEXT_PUBLIC_API_URL;
  if (!baseUrl) return;
  const root = document.documentElement.dataset;
  const scope = {
    store_id: root.storeId || process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID,
    channel_id: root.channelId || process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID,
    locale: root.locale || process.env.NEXT_PUBLIC_STOREFRONT_LOCALE,
    currency: root.currency || process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY,
  };
  const scoped = Object.values(scope).every((value) => typeof value === "string" && value.trim().length > 0);
  if (!scoped) return;
  const body = JSON.stringify({ event, event_id: crypto.randomUUID(), session_id: storefrontSessionId(), properties: payload, analytics_consent: true, ...scope });
  const url = `${baseUrl.replace(/\/$/, "")}/api/analytics/track`;
  try {
    if (typeof navigator.sendBeacon === "function") {
      navigator.sendBeacon(url, new Blob([body], { type: "application/json" }));
      return;
    }
    void fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body, keepalive: true }).catch(() => undefined);
  } catch {
    // Analytics must never block catalog or purchase UX.
  }
}
