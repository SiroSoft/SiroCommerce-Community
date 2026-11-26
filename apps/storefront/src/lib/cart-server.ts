import { cookies, headers } from "next/headers";
import { requestScope } from "@/lib/request-locale";
import { CART_COOKIE, CART_TOKEN_COOKIE, BAG_COUNT_COOKIE, cartScopeHeaders, readCartId, toCartSnapshot, type CartSnapshot } from "@/lib/cart-session";

const configuredBase = () => {
  const value = process.env.STOREFRONT_API_BASE_URL ?? process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL;
  return value ? value.replace(/\/$/, "") : undefined;
};

const correlation = () => `req_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;

/** A commerce request failed in a way callers must distinguish from an empty bag. */
export class CommerceRequestError extends Error {
  readonly status: number | undefined;
  readonly kind: "network" | "http";

  constructor(message: string, options: { status?: number; kind: "network" | "http"; cause?: unknown } = { kind: "http" }) {
    super(message);
    this.name = "CommerceRequestError";
    this.status = options.status;
    this.kind = options.kind;
    if (options.cause !== undefined) (this as { cause?: unknown }).cause = options.cause;
  }
}

/** Cart 404/410 means the cookie is stale; any other failure must surface. */
export function isCartMissingError(error: unknown): boolean {
  return error instanceof CommerceRequestError && (error.status === 404 || error.status === 410);
}

/** Server-only: the public origin of this storefront, taken from proxy headers. */
export async function publicOrigin(): Promise<string> {
  const configured = process.env.STOREFRONT_PUBLIC_URL ?? process.env.NEXT_PUBLIC_STOREFRONT_SITE_URL;
  if (configured) return configured.replace(/\/$/, "");
  const store = await headers();
  const forwardedHost = store.get("x-forwarded-host");
  const host = forwardedHost ?? store.get("host");
  if (host && !/^(0\.0\.0\.0|127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/i.test(host)) {
    const proto = store.get("x-forwarded-proto") ?? (host.startsWith("localhost") ? "http" : "https");
    return `${proto}://${host}`;
  }
  return configuredBase() ?? "http://127.0.0.1:1";
}

/** Server-only: nginx proxies /api on the storefront origin, so same-origin is the safe default. */
async function apiBase(): Promise<string> {
  return configuredBase() ?? await publicOrigin();
}

export async function commerceRequest<T>(path: string, init: { method?: string; body?: unknown; cartToken?: string } = {}): Promise<T> {
  const scope = await requestScope();
  let response: Response;
  try {
    response = await fetch(`${await apiBase()}${path}`, {
      method: init.method ?? "GET",
      headers: { ...cartScopeHeaders(scope, correlation()), ...(init.cartToken ? { "X-Cart-Token": init.cartToken } : {}) },
      ...(init.body === undefined ? {} : { body: JSON.stringify(init.body) }),
      cache: "no-store",
    });
  } catch (error) {
    throw new CommerceRequestError(`commerce_request_network_failed_${path}`, { kind: "network", cause: error });
  }
  const payload = (await response.json().catch(() => undefined)) as { success?: boolean; data?: T } | undefined;
  if (!response.ok || payload?.success !== true) {
    throw new CommerceRequestError(`commerce_request_failed_${response.status}_${path}`, { kind: "http", status: response.status });
  }
  return payload.data as T;
}

/** Server-only: the bag is resolved from the httpOnly cart cookie, never from the URL. */
export async function loadCartSnapshot(): Promise<CartSnapshot | undefined> {
  const store = await cookies();
  const cartId = readCartId(store.get(CART_COOKIE)?.value);
  if (!cartId) return undefined;
  const cartToken = store.get(CART_TOKEN_COOKIE)?.value;
  try {
    const data = await commerceRequest<Record<string, unknown>>(`/api/carts/${encodeURIComponent(cartId)}`, cartToken ? { cartToken } : {});
    const scope = await requestScope();
    return toCartSnapshot(cartId, data, scope.currency, cartToken);
  } catch (error) {
    // A missing/expired cart is a legitimate empty bag. Network and 5xx
    // failures are not: never hide them as "no cart".
    if (isCartMissingError(error)) return undefined;
    throw error instanceof CommerceRequestError ? error : new CommerceRequestError("commerce_cart_snapshot_failed", { kind: "network", cause: error });
  }
}

/**
 * Server-only: add a variant to the bag using POST only.
 * Price, name and currency are resolved by the commerce API from the variant id,
 * so nothing a browser sends can influence what is charged.
 */
export async function addVariantToCart(productId: string, quantity: number, slotStart?: string): Promise<string> {
  const store = await cookies();
  const existingId = readCartId(store.get(CART_COOKIE)?.value);
  let cartToken = store.get(CART_TOKEN_COOKIE)?.value;
  let cartId = existingId;
  let issuedToken: string | undefined;
  const body: Record<string, unknown> = { variant_id: productId, qty: quantity };
  if (slotStart) body.slot_start = slotStart;
  const addLine = async (id: string, token?: string) => commerceRequest(`/api/carts/${encodeURIComponent(id)}/lines`, { method: "POST", body, ...(token ? { cartToken: token } : {}) });
  if (cartId) {
    try {
      await addLine(cartId, cartToken);
    } catch (error) {
      // Only a stale/expired cart may be replaced. A timeout or 5xx must not
      // silently create a second cart or duplicate the customer's item.
      if (!isCartMissingError(error)) throw error;
      cartId = undefined;
      cartToken = undefined;
    }
  }
  if (!cartId) {
    const created = await commerceRequest<Record<string, unknown>>("/api/carts", { method: "POST", body: { currency: (await requestScope()).currency } });
    cartId = String(created.id ?? "").trim();
    if (!cartId) throw new CommerceRequestError("commerce_cart_create_returned_no_id", { kind: "http" });
    const token = created.access_token ?? created.cart_token ?? created.token;
    if (typeof token === "string" && token) { cartToken = token; issuedToken = token; }
    await addLine(cartId, cartToken);
  }
  const secure = process.env.NODE_ENV === "production";
  store.set(CART_COOKIE, cartId, { httpOnly: true, sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 30 });
  if (issuedToken) store.set(CART_TOKEN_COOKIE, issuedToken, { sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 30 });
  // Keep the header badge truthful: count priced lines right after the write.
  try {
    const snapshot = await loadCartSnapshot();
    const count = snapshot?.lines.reduce((sum, line) => sum + line.quantity, 0) ?? 0;
    store.set(BAG_COUNT_COOKIE, String(count), { sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 30 });
  } catch {
    // The badge is advisory; a stale count never blocks the bag.
  }
  return cartId;
}