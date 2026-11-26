import type { Product } from "@/types";

export const recentlyViewedKey = "sirocommerce:recently-viewed";
export const recentlyViewedLimit = 8;
export const recentlyViewedMaxAgeMs = 30 * 24 * 60 * 60 * 1000;

const safeProductIdPattern = /^[A-Za-z0-9][A-Za-z0-9._~-]{0,127}$/;

export function isSafeProductId(value: unknown): value is string {
  return typeof value === "string" && safeProductIdPattern.test(value);
}

export function readRecentlyViewed(storage: Storage | undefined = typeof window === "undefined" ? undefined : window.localStorage): string[] {
  if (!storage) return [];
  try {
    const value: unknown = JSON.parse(storage.getItem(recentlyViewedKey) ?? "null");
    if (!Array.isArray(value)) return [];
    const now = Date.now();
    const ids = value.flatMap((entry) => {
      if (typeof entry === "string") return isSafeProductId(entry) ? [entry] : [];
      if (!entry || typeof entry !== "object") return [];
      const item = entry as { id?: unknown; viewedAt?: unknown };
      const viewedAt = typeof item.viewedAt === "number" ? item.viewedAt : 0;
      return isSafeProductId(item.id) && viewedAt > 0 && now - viewedAt <= recentlyViewedMaxAgeMs ? [item.id] : [];
    });
    return [...new Set(ids)].slice(0, recentlyViewedLimit);
  } catch {
    return [];
  }
}

export function rememberRecentlyViewed(productId: string, storage: Storage | undefined = typeof window === "undefined" ? undefined : window.localStorage): string[] {
  if (!storage || !isSafeProductId(productId)) return readRecentlyViewed(storage);
  const next = [productId, ...readRecentlyViewed(storage).filter((value) => value !== productId)].slice(0, recentlyViewedLimit);
  try { storage.setItem(recentlyViewedKey, JSON.stringify(next.map((id) => ({ id, viewedAt: Date.now() })))); } catch { /* Storage is optional and may be full or blocked. */ }
  return next;
}

export function clearRecentlyViewed(storage: Storage | undefined = typeof window === "undefined" ? undefined : window.localStorage): void {
  try { storage?.removeItem(recentlyViewedKey); } catch { /* Storage is optional and may be blocked. */ }
}

export function productsForRecentlyViewed(ids: string[], catalog: Product[], excludeId?: string): Product[] {
  const byId = new Map(catalog.filter((product) => isSafeProductId(product.id)).map((product) => [product.id, product]));
  return ids.flatMap((id) => {
    const product = byId.get(id);
    return product && product.id !== excludeId ? [product] : [];
  });
}
