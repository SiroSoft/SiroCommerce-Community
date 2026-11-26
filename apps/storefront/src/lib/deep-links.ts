import type { CommerceScope } from "@sirocommerce/scope";

export type DeepLinkTarget = "product" | "cart" | "checkout" | "order" | "return";
export type DeepLink = { target: DeepLinkTarget; path: string; scope: CommerceScope; productSlug?: string; orderId?: string; orderNumber?: string; guestToken?: string; quantity?: number };
const id = /^[A-Za-z0-9_-]{1,128}$/;
const slug = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const targetPaths: Record<DeepLinkTarget, string> = { product: "/product", cart: "/cart", checkout: "/checkout", order: "/order-result", return: "/account" };
const sameScope = (actual: CommerceScope, expected: CommerceScope) => actual.store_id === expected.store_id && actual.channel_id === expected.channel_id && actual.locale === expected.locale && actual.currency === expected.currency;

export function createDeepLink(input: Omit<DeepLink, "path">): string {
  const query = new URLSearchParams({ store: input.scope.store_id, channel: input.scope.channel_id, locale: input.scope.locale, currency: input.scope.currency });
  if (input.productSlug && input.target !== "product") query.set("product", input.productSlug);
  if (input.orderId) query.set("order", input.orderId);
  if (input.orderNumber) query.set("number", input.orderNumber);
  if (input.guestToken) query.set("guest_token", input.guestToken);
  if (input.quantity !== undefined) query.set("quantity", String(input.quantity));
  const path = input.target === "product" && input.productSlug ? `${targetPaths.product}/${encodeURIComponent(input.productSlug)}` : targetPaths[input.target];
  return `${path}?${query.toString()}`;
}

export function parseDeepLink(value: string | URL, expected: CommerceScope, options: { authenticated?: boolean } = {}): { link?: DeepLink; error?: "invalid" | "scope_mismatch" | "missing_capability" } {
  let url: URL;
  try { url = new URL(String(value), "https://sirocommerce.invalid"); } catch { return { error: "invalid" }; }
  const productPath = url.pathname.match(/^\/product\/([^/]+)$/);
  const target = (productPath ? "product" : Object.entries(targetPaths).find(([, path]) => path === url.pathname)?.[0] ?? "") as DeepLinkTarget;
  if (!target) return { error: "invalid" };
  const actual = { store_id: url.searchParams.get("store") ?? "", channel_id: url.searchParams.get("channel") ?? "", locale: url.searchParams.get("locale") ?? "", currency: url.searchParams.get("currency") ?? "" };
  if (!actual.store_id || !actual.channel_id || !actual.locale || !actual.currency) return { error: "invalid" };
  if (!sameScope(actual, expected)) return { error: "scope_mismatch" };
  let productSlug = url.searchParams.get("product") ?? undefined;
  if (productPath) {
    const encodedProductSlug = productPath[1];
    if (encodedProductSlug === undefined) return { error: "invalid" };
    try { productSlug = decodeURIComponent(encodedProductSlug); } catch { return { error: "invalid" }; }
  }
  const orderId = url.searchParams.get("order") ?? undefined;
  const orderNumber = url.searchParams.get("number") ?? undefined;
  const guestToken = url.searchParams.get("guest_token") ?? undefined;
  const rawQuantity = url.searchParams.get("quantity");
  const quantity = rawQuantity === null ? undefined : Number(rawQuantity);
  if (productSlug !== undefined && !slug.test(productSlug)) return { error: "invalid" };
  if (orderId !== undefined && !id.test(orderId)) return { error: "invalid" };
  if (orderNumber !== undefined && !id.test(orderNumber)) return { error: "invalid" };
  if (quantity !== undefined && (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 99)) return { error: "invalid" };
  if (target === "product" && !productSlug) return { error: "invalid" };
  if ((target === "order" || target === "return") && !orderId && !orderNumber) return { error: "invalid" };
  if (target === "order" && !guestToken && !orderId) return { error: "missing_capability" };
  if (target === "order" && !guestToken && !options.authenticated) return { error: "missing_capability" };
  return { link: { target, path: url.pathname, scope: actual, productSlug, orderId, orderNumber, guestToken, quantity } };
}

export function scopedStorageKey(scope: CommerceScope, suffix: string): string { return `siro:${scope.store_id}:${scope.channel_id}:${scope.locale}:${scope.currency}:${suffix}`; }
