/** Keep JSON-LD inside its script element even when catalog text contains markup. */
export function jsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/</g, "\\u003c").replace(/>/g, "\\u003e").replace(/&/g, "\\u0026");
}

export function priceForSchema(minor: number, currency: string): number {
  if (!Number.isSafeInteger(minor) || minor < 0) throw new Error("minor must be a non-negative integer");
  const units = new Intl.NumberFormat("en", { style: "currency", currency }).resolvedOptions().maximumFractionDigits ?? 2;
  return Number((minor / (10 ** units)).toFixed(units));
}
