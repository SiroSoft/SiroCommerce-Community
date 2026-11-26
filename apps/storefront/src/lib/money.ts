/** Money is INT minor units on the wire (spec 02§3.2). Format only at display. */
export const supportedCurrencies = ["BHD", "CLP", "DJF", "EUR", "GBP", "HKD", "INR", "JPY", "KRW", "KWD", "MGA", "MRO", "MUR", "OMR", "PHP", "PKR", "QAR", "RUB", "SAR", "TND", "USD", "VND", "XAF", "XOF", "XPF"] as const;
export type SupportedCurrency = (typeof supportedCurrencies)[number];

export function isSupportedCurrency(value: unknown): value is SupportedCurrency {
  return typeof value === "string" && supportedCurrencies.includes(value as SupportedCurrency);
}

export function minorUnit(currency: string): number {
  if (!isSupportedCurrency(currency)) throw new Error(`Unsupported currency: ${currency}`);
  return new Intl.NumberFormat("en", { style: "currency", currency }).resolvedOptions().maximumFractionDigits ?? 2;
}

/** Convert a user-entered major-unit amount (e.g. "12.50") to integer minor units using the currency's exponent. */
export function toMinorUnits(value: number | string, currency: string): number {
  const amount = typeof value === "number" ? value : Number(value);
  if (!Number.isFinite(amount)) return Number.NaN;
  return Math.round(amount * (10 ** minorUnit(currency)));
}

/** Convert integer minor units back to a major-unit number for display/editing. */
export function fromMinorUnits(minor: number, currency: string): number {
  if (!Number.isSafeInteger(minor)) throw new Error("minor must be a safe integer");
  return minor / (10 ** minorUnit(currency));
}

export function formatMinor(minor: number | undefined, currency = "VND", locale = "en"): string {
  if (minor === undefined) return "";
  if (!Number.isInteger(minor) || minor < 0) throw new Error("minor must be a non-negative integer");
  const units = minorUnit(currency);
  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency,
    minimumFractionDigits: units,
    maximumFractionDigits: units,
  }).format(minor / (10 ** units));
}
