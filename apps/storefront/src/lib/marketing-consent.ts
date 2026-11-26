const marketingConsentKey = "sirocommerce:marketing-consent";

/** Local UX state only. The authenticated account preference is authoritative. */
export function setMarketingConsent(granted: boolean): void {
  if (typeof window !== "undefined") window.localStorage.setItem(marketingConsentKey, granted ? "granted" : "denied");
}

export function marketingConsent(): boolean {
  return typeof window !== "undefined" && window.localStorage.getItem(marketingConsentKey) === "granted";
}
