import { isSupportedCurrency, type SupportedCurrency } from "@/lib/money";
import { normalizeLocale, type StorefrontLocale } from "@/lib/locale";

export const preferenceStorageKey = "sirocommerce.customer-preferences";
export type CustomerPreferences = { locale: StorefrontLocale; currency: SupportedCurrency };

export function parseCustomerPreferences(value: unknown): CustomerPreferences | undefined {
  if (!value || typeof value !== "object") return undefined;
  const item = value as Record<string, unknown>;
  const locale = normalizeLocale(item.locale);
  const currency = typeof item.currency === "string" && isSupportedCurrency(item.currency) ? item.currency : undefined;
  return locale && currency ? { locale, currency } : undefined;
}

export function readCustomerPreferences(storage?: Storage): CustomerPreferences | undefined {
  try {
    const raw = (storage ?? (typeof window !== "undefined" ? window.localStorage : undefined))?.getItem(preferenceStorageKey);
    return raw ? parseCustomerPreferences(JSON.parse(raw)) : undefined;
  } catch { return undefined; }
}

export function writeCustomerPreferences(value: CustomerPreferences, storage?: Storage): boolean {
  const valid = parseCustomerPreferences(value);
  if (!valid) return false;
  try {
    (storage ?? (typeof window !== "undefined" ? window.localStorage : undefined))?.setItem(preferenceStorageKey, JSON.stringify(valid));
    return true;
  } catch { return false; }
}
