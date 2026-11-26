/** Shared write-safety helpers for admin mutations. Pure and unit-tested. */

/** Browser confirmation for destructive writes. Returns false outside a browser. */
export function confirmAction(message: string): boolean {
  if (typeof window === "undefined" || typeof window.confirm !== "function") return false;
  return window.confirm(message);
}

/** Parses a raw input as a positive integer (> 0). Returns null when invalid. */
export function parsePositiveInt(raw: string): number | null {
  const trimmed = raw.trim();
  if (!/^[1-9]\d*$/.test(trimmed)) return null;
  const value = Number(trimmed);
  return Number.isSafeInteger(value) ? value : null;
}

/** Parses a raw input as a non-negative integer (>= 0). Returns null when invalid. */
export function parseNonNegativeInt(raw: string): number | null {
  const trimmed = raw.trim();
  if (!/^\d+$/.test(trimmed)) return null;
  const value = Number(trimmed);
  return Number.isSafeInteger(value) ? value : null;
}

export type MinorAmountResult = { ok: true; value: number } | { ok: false; error: string };

/** Validates a minor-unit money input: required, whole number, within 0..max. */
export function validateMinorAmount(raw: string, label: string, max = 1_000_000_000_000): MinorAmountResult {
  const trimmed = raw.trim();
  if (!trimmed) return { ok: false, error: `${label} is required.` };
  if (!/^\d+$/.test(trimmed)) return { ok: false, error: `${label} must be a whole number in minor units.` };
  const value = Number(trimmed);
  if (!Number.isSafeInteger(value) || value <= 0) return { ok: false, error: `${label} must be greater than zero.` };
  if (value > max) return { ok: false, error: `${label} exceeds the maximum of ${max}.` };
  return { ok: true, value };
}

export type RequiredIdResult = { ok: true; value: string } | { ok: false; error: string };

/** Validates a required identifier input (order, variant, company, ...). */
export function validateRequiredId(raw: string, label: string): RequiredIdResult {
  const value = raw.trim();
  if (!value) return { ok: false, error: `${label} is required.` };
  return { ok: true, value };
}
