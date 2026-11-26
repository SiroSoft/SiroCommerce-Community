import { createCommerceScope, type CommerceScope } from "@sirocommerce/scope";
import { fixtureProduct } from "@/fixtures/purchase";
import { products } from "@/services/catalog";
import type { CustomerSession, ProductHandoff, PurchaseOperations } from "@/purchase/contracts";
import { PurchaseError } from "@/purchase/contracts";
import { FixturePurchaseAdapter } from "@/purchase/fixture-adapter";
import { formatMinor } from "@/lib/money";
import type { StorefrontCopy } from "@/lib/i18n";
import { readScopeCookie } from "@/lib/scope-cookie";
import { scopedStorageKey } from "@/lib/deep-links";
import { CHECKOUT_UNAVAILABLE_NOTICE, type PurchaseMode } from "./mode";

export const blankHandoff = (currency: string): ProductHandoff => ({ product_id: "", slug: "", name: "", unit_price: { amount_minor: 0, currency }, quantity: 1 });
export const fixtureAddress = { name: "Nguyen Van A", line1: "1 Main St", city: "Hanoi", country: "VN", postal_code: "100000" };
export const blankAddress = { name: "", line1: "", city: "", country: "", postal_code: "" };
export const supportEmail = (process.env.NEXT_PUBLIC_STOREFRONT_SUPPORT_EMAIL ?? "").trim();
export const bankTransferInstructions = (process.env.NEXT_PUBLIC_STOREFRONT_BANK_TRANSFER_INSTRUCTIONS ?? "").trim();

export const go = (step: string) => {
  window.history.pushState({}, "", `/${step}${window.location.search}`);
  window.dispatchEvent(new PopStateEvent("popstate"));
};

function cookieScope(): { locale?: string; currency?: string } {
  try { return readScopeCookie(); } catch { return {}; }
}

const remembered = cookieScope();

export const scope: CommerceScope = createCommerceScope({
  store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? process.env.NEXT_PUBLIC_STORE_ID ?? "default",
  channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? process.env.NEXT_PUBLIC_CHANNEL_ID ?? "web",
  locale: "en",
  currency: remembered.currency ?? process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
});

export const formatMoney = (amount: number | undefined, currency: string, locale: string, pendingLabel = "Calculated by store") => {
  if (amount === undefined) return pendingLabel;
  const formatted = formatMinor(amount, currency, locale);
  return formatted.includes("₫") ? `${formatted.replace("₫", "").trim()} VND` : formatted;
};

export const sessionGet = (key: string) => { try { return window.sessionStorage.getItem(scopedStorageKey(scope, key)); } catch { return null; } };
export const sessionSet = (key: string, value: string) => { try { window.sessionStorage.setItem(scopedStorageKey(scope, key), value); } catch { /* Recovery state is best effort when storage is unavailable. */ } };
export const sessionRemove = (key: string) => { try { window.sessionStorage.removeItem(scopedStorageKey(scope, key)); } catch { /* Storage may be disabled or full. */ } };

export const testMode = process.env.NODE_ENV === "test" || (process.env.NODE_ENV !== "production" && process.env.NEXT_PUBLIC_STOREFRONT_TEST_MODE === "true");

const validSlug = (value: string) => /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value) && value.length <= 120;

export const parseHandoff = (search: string, text: StorefrontCopy["handoff"], mode: PurchaseMode): { handoff?: ProductHandoff; options: Record<string, string>; invalid?: string } => {
  const params = new URLSearchParams(search);
  const slug = params.get("product")?.trim() ?? "";
  if (!slug) return { options: {} };
  if (!validSlug(slug)) return { options: {}, invalid: text.invalidLink };
  const rawQuantity = params.get("quantity");
  const quantity = rawQuantity === null ? 1 : Number(rawQuantity);
  if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 99) return { options: {}, invalid: text.badQuantity };
  const catalogProduct = mode.fixture ? products.find((item) => item.slug === slug) : undefined;
  const liveProductId = params.get("product_id")?.trim() ?? "";
  const liveProductName = params.get("product_name")?.trim() ?? "";
  const liveProductPrice = Number(params.get("product_price"));
  const liveProductCurrency = params.get("product_currency")?.trim() ?? "";
  const options: Record<string, string> = {};
  for (const [key, value] of params.entries()) {
    if (!key.startsWith("option_")) continue;
    const name = key.slice("option_".length);
    if (!name || !value.trim() || value.length > 120 || !/^[\w -]+$/.test(name)) return { options: {}, invalid: text.badOption };
    const allowed = catalogProduct?.options?.find((option) => option.name === name)?.values;
    if (allowed && !allowed.includes(value)) return { options: {}, invalid: text.optionGone };
    options[name] = value;
  }
  if (!mode.fixture && (!liveProductId || !liveProductName || !Number.isSafeInteger(liveProductPrice) || liveProductPrice < 0 || !/^[A-Z]{3}$/.test(liveProductCurrency))) return { options: {}, invalid: text.incompleteLink };
  const base = !mode.fixture ? { ...fixtureProduct, product_id: liveProductId, slug, name: liveProductName, unit_price: { amount_minor: liveProductPrice, currency: liveProductCurrency } } : catalogProduct ? { ...fixtureProduct, product_id: catalogProduct.id, slug, name: catalogProduct.name } : { ...fixtureProduct, product_id: slug, slug, name: slug.replaceAll("-", " ") };
  const optionLabel = Object.entries(options).map(([name, value]) => `${name}: ${value}`).join(" · ");
  return { handoff: { ...base, name: optionLabel ? `${base.name} · ${optionLabel}` : base.name, quantity }, options };
};

/** Stable cart-line identity: server line_id wins, else product + slot + flash. */
export function cartLineKey(line: { line_id?: string; product_id: string; slot_start?: string; flash_sale_id?: string }): string {
  return line.line_id ?? `${line.product_id}::${line.slot_start ?? ""}::${line.flash_sale_id ?? ""}`;
}

/** Fail-closed adapter for production without a configured commerce API: nothing may look like real data. */
export function createUnavailableAdapter(): PurchaseOperations {
  const target = new FixturePurchaseAdapter();
  return new Proxy(target, {
    get(value, property, receiver) {
      // Hydration is an optional no-op; returning a rejected promise here would leak an unhandled rejection.
      if (property === "hydrate") return () => undefined;
      const member = Reflect.get(value, property, receiver) as unknown;
      if (typeof member !== "function") return member;
      return () => Promise.reject(new PurchaseError("forbidden", CHECKOUT_UNAVAILABLE_NOTICE, { status: 403 }));
    },
  }) as PurchaseOperations;
}

export function persistSession(storage: { setAccessToken(token: string): void; setTokens(tokens: { token: string; refresh_token: string }): void }, session: CustomerSession) {
  // The access token stays in memory; only the refresh token is persisted so a
  // reload or navigation restores the session without asking twice.
  if (session.refresh_token) {
    try { storage.setTokens({ token: session.access_token, refresh_token: session.refresh_token }); } catch { storage.setAccessToken(session.access_token); }
  } else {
    storage.setAccessToken(session.access_token);
  }
  try { window.dispatchEvent(new Event("siro:session-updated")); } catch { /* Non-browser renderers have no session listeners. */ }
}
