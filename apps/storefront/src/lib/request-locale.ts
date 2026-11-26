import { cookies } from "next/headers";
import type { CommerceScope } from "@sirocommerce/scope";
import { normalizeLocale, storefrontLocale, type StorefrontLocale } from "@/lib/locale";
import { LOCALE_COOKIE, CURRENCY_COOKIE, SUPPORTED_CURRENCIES, type StorefrontCurrency } from "@/lib/scope-cookie";

function normalizeCurrency(value: unknown): StorefrontCurrency | undefined {
  if (typeof value !== "string") return undefined;
  const code = value.trim().toUpperCase();
  return (SUPPORTED_CURRENCIES as readonly string[]).includes(code) ? code as StorefrontCurrency : undefined;
}

/** Server-only: resolve the request locale from cookie, falling back to build default. */
export async function requestLocale(): Promise<StorefrontLocale> {
  try {
    const store = await cookies();
    return normalizeLocale(store.get(LOCALE_COOKIE)?.value) ?? storefrontLocale();
  } catch {
    return storefrontLocale();
  }
}

/** Server-only: resolve the request currency from cookie, falling back to build default. */
export async function requestCurrency(): Promise<StorefrontCurrency> {
  try {
    const store = await cookies();
    const fromCookie = normalizeCurrency(store.get(CURRENCY_COOKIE)?.value);
    if (fromCookie) return fromCookie;
  } catch { /* fall through to env default */ }
  const env = (process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? process.env.STOREFRONT_CURRENCY ?? "USD").toUpperCase();
  return normalizeCurrency(env) ?? "USD";
}

/**
 * Server-only: full commerce scope for catalog reads.
 * The catalog locale follows the merchant data language (English catalogs
 * exist per currency); the UI locale is independent and only drives copy.
 */
export async function requestScope(): Promise<CommerceScope> {
  const currency = await requestCurrency();
  return {
    store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? process.env.STOREFRONT_STORE_ID ?? "default",
    channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? process.env.STOREFRONT_CHANNEL_ID ?? "web",
    locale: "en",
    currency,
  };
}
