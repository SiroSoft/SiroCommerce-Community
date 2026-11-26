"use client";

import { useState } from "react";
import { supportedLocales, type StorefrontLocale } from "@/lib/locale";
import { readScopeCookie, writeScopeCookie, type StorefrontCurrency } from "@/lib/scope-cookie";
import { getCopy } from "@/lib/i18n";

const LANGUAGES: Record<StorefrontLocale, string> = { en: "English", vi: "Tiếng Việt", ja: "日本語", de: "Deutsch", zh: "中文" };
const CURRENCIES: StorefrontCurrency[] = ["USD", "VND"];

/**
 * Enterprise market selector. Language switches the translated storefront
 * chrome; currency switches the priced catalog scope. Both persist to a
 * cookie so server rendering and checkout stay in the same scope.
 */
export function RegionSelector({ locale, currency }: { locale: StorefrontLocale; currency: StorefrontCurrency }) {
  const copy = getCopy(locale);
  const [pending, setPending] = useState(false);
  function update(nextLocale: StorefrontLocale, nextCurrency: StorefrontCurrency) {
    if (pending || (nextLocale === locale && nextCurrency === currency)) return;
    setPending(true);
    writeScopeCookie(nextLocale, nextCurrency);
    window.location.reload();
  }
  return <div className="region-selector" aria-label={copy.header.region}>
    <label htmlFor="storefront-language"><span className="sr-only">{copy.header.language}</span><select id="storefront-language" aria-label={copy.header.language} value={locale} disabled={pending} onChange={(event) => update(event.target.value as StorefrontLocale, readScopeCookie().currency ?? currency)}>{supportedLocales.map((value) => <option key={value} value={value}>{LANGUAGES[value]}</option>)}</select></label>
    <label htmlFor="storefront-currency"><span className="sr-only">{copy.header.currency}</span><select id="storefront-currency" aria-label={copy.header.currency} value={currency} disabled={pending} onChange={(event) => update(readScopeCookie().locale ?? locale, event.target.value as StorefrontCurrency)}>{CURRENCIES.map((value) => <option key={value} value={value}>{value === "USD" ? "USD $" : "VND ₫"}</option>)}</select></label>
  </div>;
}
