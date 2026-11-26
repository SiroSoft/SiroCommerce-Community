import type { CommerceScope } from "@sirocommerce/scope";

export const CART_COOKIE = "siro_cart_id";
export const CART_TOKEN_COOKIE = "siro_cart_token";
/** Readable bag count so the header badge updates on every navigation without a round trip. */
export const BAG_COUNT_COOKIE = "siro_bag_count";

export type CartLineSnapshot = { lineId?: string; productId: string; slug?: string; name: string; image?: string; quantity: number; unitPriceMinor: number; lineTotalMinor: number };
export type CartSnapshot = { cartId: string; cartToken?: string; currency: string; lines: CartLineSnapshot[]; subtotalMinor: number; raw: Record<string, unknown> };

const CART_ID = /^[A-Za-z0-9_-]{8,64}$/;

export function readCartId(value: string | undefined): string | undefined {
  const trimmed = value?.trim() ?? "";
  return CART_ID.test(trimmed) ? trimmed : undefined;
}

/** Isomorphic: the bag token is a cart-scoped secret the browser client must replay on cart calls. */
export function readCartCookies(): { cartId?: string; cartToken?: string } {
  if (typeof document === "undefined") return {};
  const jar = document.cookie.split(";").map((entry) => entry.trim().split("="));
  const value = (name: string) => decodeURIComponent(jar.find(([key]) => key === name)?.[1] ?? "");
  const token = value(CART_TOKEN_COOKIE);
  return { cartId: readCartId(value(CART_COOKIE)), ...(token ? { cartToken: token } : {}) };
}

/** Isomorphic: bag badge count, readable without touching session storage. */
export function readBagCount(): number {
  if (typeof document === "undefined") return 0;
  const match = document.cookie.split(";").map((entry) => entry.trim()).find((entry) => entry.startsWith(`${BAG_COUNT_COOKIE}=`));
  const count = Number(match?.split("=").slice(1).join("=") ?? "");
  return Number.isSafeInteger(count) && count > 0 ? Math.min(count, 99) : 0;
}

/** Server-only: normalize the commerce cart payload into a display snapshot. */
export function toCartSnapshot(cartId: string, data: Record<string, unknown>, fallbackCurrency: string, cartToken?: string): CartSnapshot {
  const currency = typeof data.currency === "string" && data.currency ? data.currency : fallbackCurrency;
  const rawLines = Array.isArray(data.lines) ? data.lines : [];
  const lines = rawLines.map((entry) => {
    const line = (entry ?? {}) as Record<string, unknown>;
    const quantity = Number(line.quantity ?? 1) || 1;
    const unitPriceMinor = Number(line.unit_price ?? line.price ?? 0) || 0;
    const lineTotalMinor = Number(line.line_total ?? line.total ?? unitPriceMinor * quantity) || 0;
    return {
      lineId: typeof line.id === "string" && line.id ? line.id : undefined,
      productId: String(line.product_id ?? line.variant_id ?? ""),
      slug: typeof line.slug === "string" ? line.slug : undefined,
      name: String(line.name ?? ""),
      image: typeof line.image === "string" && line.image ? line.image : undefined,
      quantity,
      unitPriceMinor,
      lineTotalMinor,
    };
  }).filter((line) => line.productId !== "" && line.quantity > 0);
  const subtotalMinor = Number(data.subtotal ?? data.subtotal_minor ?? lines.reduce((sum, line) => sum + line.lineTotalMinor, 0)) || 0;
  return { cartId, cartToken, currency, lines, subtotalMinor, raw: data };
}

export function toDisplayCart(snapshot: CartSnapshot): { id: string; token?: string; lines: { line_id?: string; product_id: string; slug: string; name: string; image?: string; unit_price: { amount_minor: number; currency: string }; quantity: number; line_total: { amount_minor: number; currency: string } }[]; subtotal: { amount_minor: number; currency: string } } {
  return {
    id: snapshot.cartId,
    ...(snapshot.cartToken ? { token: snapshot.cartToken } : {}),
    lines: snapshot.lines.map((line) => ({
      ...(line.lineId ? { line_id: line.lineId } : {}),
      product_id: line.productId,
      slug: line.slug ?? "",
      name: line.name,
      ...(line.image ? { image: line.image } : {}),
      unit_price: { amount_minor: line.unitPriceMinor, currency: snapshot.currency },
      quantity: line.quantity,
      line_total: { amount_minor: line.lineTotalMinor, currency: snapshot.currency },
    })),
    subtotal: { amount_minor: snapshot.subtotalMinor, currency: snapshot.currency },
  };
}

export function cartScopeHeaders(scope: CommerceScope, correlationId: string): Record<string, string> {
  return {
    Accept: "application/json; version=v1",
    "Content-Type": "application/json",
    "X-Correlation-Id": correlationId,
    "X-Store-Id": scope.store_id,
    "X-Channel-Id": scope.channel_id,
    "X-Locale": scope.locale,
    "X-Currency": scope.currency,
  };
}