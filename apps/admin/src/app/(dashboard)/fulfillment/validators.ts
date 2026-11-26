/** Pure payload validators for the orders & fulfillment area. No React, no I/O. */

export function isPositiveInteger(value: unknown): value is number {
  return Number.isInteger(value) && (value as number) > 0;
}

export function isValidRefundAmount(raw: string): boolean {
  return /^[1-9]\d*$/.test(raw.trim());
}

export function validateAllocateLines(lines: unknown): { ok: true } | { ok: false; error: string } {
  if (!Array.isArray(lines) || lines.length === 0) {
    return { ok: false, error: "Provide at least one line with variant_id and qty." };
  }
  for (const line of lines) {
    const row = line as Record<string, unknown>;
    if (typeof row?.variant_id !== "string" || !row.variant_id.trim() || !isPositiveInteger(row.qty)) {
      return { ok: false, error: "Each line needs a variant_id string and a positive integer qty." };
    }
  }
  return { ok: true };
}

export function validateReturnLines(lines: unknown): { ok: true } | { ok: false; error: string } {
  if (!Array.isArray(lines) || lines.length === 0) {
    return { ok: false, error: "Provide at least one return line with line_id and qty." };
  }
  for (const line of lines) {
    const row = line as Record<string, unknown>;
    if (typeof row?.line_id !== "string" || !row.line_id.trim() || !isPositiveInteger(row.qty)) {
      return { ok: false, error: "Each return line needs a line_id string and a positive integer qty." };
    }
  }
  return { ok: true };
}

export function parseJsonObjectSafe(raw: string, label: string): { ok: true; value: Record<string, unknown> } | { ok: false; error: string } {
  const trimmed = raw.trim();
  if (!trimmed) return { ok: true, value: {} };
  try {
    const parsed: unknown = JSON.parse(trimmed);
    if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) {
      return { ok: false, error: `${label} must be a JSON object.` };
    }
    return { ok: true, value: parsed as Record<string, unknown> };
  } catch {
    return { ok: false, error: `${label} must be valid JSON.` };
  }
}

export function parseJsonArraySafe(raw: string, label: string): { ok: true; value: unknown[] } | { ok: false; error: string } {
  try {
    const parsed: unknown = JSON.parse(raw.trim() || "[]");
    if (!Array.isArray(parsed)) return { ok: false, error: `${label} must be a JSON array.` };
    return { ok: true, value: parsed };
  } catch {
    return { ok: false, error: `${label} must be valid JSON.` };
  }
}
