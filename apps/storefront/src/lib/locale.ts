export const supportedLocales = ["en", "vi", "ja", "de", "zh"] as const;
export type StorefrontLocale = (typeof supportedLocales)[number];

export function normalizeLocale(value: unknown): StorefrontLocale | undefined {
  if (typeof value !== "string") return undefined;
  const base = value.trim().toLowerCase().replace("_", "-").split("-")[0];
  return supportedLocales.includes(base as StorefrontLocale) ? base as StorefrontLocale : undefined;
}

export function negotiateLocale(...values: unknown[]): StorefrontLocale {
  for (const value of values) {
    const direct = normalizeLocale(value);
    if (direct) return direct;
    if (typeof value === "string") {
      for (const candidate of value.split(",")) {
        const locale = normalizeLocale(candidate.split(";")[0]);
        if (locale) return locale;
      }
    }
  }
  return "en";
}

export function storefrontLocale(): StorefrontLocale {
  return negotiateLocale(process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? process.env.STOREFRONT_LOCALE);
}

export function siteUrl(path = ""): string {
  const configured = process.env.NEXT_PUBLIC_STOREFRONT_URL ?? process.env.STOREFRONT_SITE_URL;
  const base = configured && /^https:\/\//.test(configured) ? configured : "https://sirocommerce.test";
  return new URL(path, base).toString();
}
