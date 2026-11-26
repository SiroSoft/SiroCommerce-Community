import { normalizeLocale, type StorefrontLocale } from "@/lib/locale";

export const LOCALE_COOKIE = "siro-locale";
export const CURRENCY_COOKIE = "siro-currency";
export const SUPPORTED_CURRENCIES = ["USD", "VND", "EUR", "JPY", "CNY"] as const;
export type StorefrontCurrency = (typeof SUPPORTED_CURRENCIES)[number];

export function readScopeCookie(): { locale?: StorefrontLocale; currency?: StorefrontCurrency } {
  try {
    if (typeof document === "undefined") return {};
    const parts = document.cookie.split(";").map((part) => part.trim().split("="));
    const jar: Record<string, string> = {};
    for (const [key, ...rest] of parts) if (key) jar[key] = decodeURIComponent(rest.join("="));
    const locale = normalizeLocale(jar[LOCALE_COOKIE]);
    const rawCurrency = (jar[CURRENCY_COOKIE] ?? "").toUpperCase();
    const currency = (SUPPORTED_CURRENCIES as readonly string[]).includes(rawCurrency) ? rawCurrency as StorefrontCurrency : undefined;
    return { ...(locale ? { locale } : {}), ...(currency ? { currency } : {}) };
  } catch {
    return {};
  }
}

export function writeScopeCookie(locale: StorefrontLocale, currency: StorefrontCurrency): void {
  const attributes = "Path=/; Max-Age=31536000; SameSite=Lax";
  document.cookie = `${LOCALE_COOKIE}=${locale}; ${attributes}`;
  document.cookie = `${CURRENCY_COOKIE}=${currency}; ${attributes}`;
  try {
    window.localStorage.setItem("sirocommerce.customer-preferences", JSON.stringify({ locale, currency }));
  } catch { /* best effort */ }
}
