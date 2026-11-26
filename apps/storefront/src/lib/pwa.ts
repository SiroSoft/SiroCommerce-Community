export const recoveryKey = "siro.purchase.cart-line";
export const recoveryTtlMs = 24 * 60 * 60 * 1000;
export const recoveryMaxBytes = 8 * 1024;

export function isSensitiveCachePath(pathname: string): boolean {
  return ["/api", "/cart", "/checkout", "/account", "/order-result", "/quote"].some((path) => pathname === path || pathname.startsWith(`${path}/`));
}

export function readCartRecovery(raw: string | null, now = Date.now()): Record<string, unknown> | undefined {
  if (!raw || raw.length > recoveryMaxBytes) return undefined;
  try {
    const value = JSON.parse(raw) as Record<string, unknown>;
    if (typeof value.savedAt !== "number" || now - value.savedAt < 0 || now - value.savedAt > recoveryTtlMs) return undefined;
    if (typeof value.product_id !== "string" || typeof value.slug !== "string" || typeof value.name !== "string" || typeof value.quantity !== "number" || !Number.isInteger(value.quantity) || value.quantity < 1 || value.quantity > 99) return undefined;
    if (value.cartQuantity !== undefined && (typeof value.cartQuantity !== "number" || !Number.isInteger(value.cartQuantity) || value.cartQuantity < 1 || value.cartQuantity > 99)) return undefined;
    if (!value.unit_price || typeof value.unit_price !== "object") return undefined;
    return value;
  } catch {
    return undefined;
  }
}
