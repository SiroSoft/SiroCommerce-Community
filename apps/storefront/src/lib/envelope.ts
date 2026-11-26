import type { ApiResponse, PaginationMeta } from "../types/index.js";

/** Unwrap Siro envelope data (spec 03§1). Throws on `success: false`. */
export function unwrapData<T>(res: ApiResponse<T>): T {
  if (!res.success) throw new Error(res.message || "Request failed");
  return res.data;
}

export function unwrapList<T>(res: ApiResponse<T[]>): { items: T[]; meta: PaginationMeta } {
  if (!res.success) throw new Error(res.message || "Request failed");
  if (!res.meta) throw new Error("Missing pagination meta");
  return { items: res.data, meta: res.meta };
}

/** Extract field errors from `{ success:false, errors:{field:[msg]} }`. */
export function getFieldErrors(res: { errors?: Record<string, string[]> }): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(res.errors ?? {})) out[k] = v[0] ?? "";
  return out;
}
