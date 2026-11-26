import type { CommerceScope } from "@sirocommerce/scope";
import { defaultTheme } from "@/theme/defaults";
import { resolveTheme } from "@/theme/resolve-theme";
import { resolveVertical } from "@/verticals/resolve-vertical";
import { defaultSections, resolveHomepageSections } from "@/homepage/resolve-homepage";
import type { FooterConfig, HeaderConfig, ProductCardVariant, ProductDetailVariant, PurchaseVariant, StorefrontTheme } from "@/theme/types";
import type { HomepageSection } from "@/homepage/types";
import { asCardVariant, asDetailVariant, asFooterLayout, asHeaderLayout, asListLayout, asPurchaseVariant } from "@/theme/layouts";

export type ExperimentConfig = { id: string; enabled?: boolean; variants: Array<{ id: string; weight: number }> };
export type QuoteMode = "required" | "auto" | "off";
export type PublicThemePreset = {
  key: string;
  name: string;
  description: string;
  preview: string;
  layout_archetype?: string;
  theme: Record<string, unknown>;
  brand: Record<string, unknown>;
  header: Record<string, unknown>;
  footer: Record<string, unknown>;
  purchase: Record<string, unknown>;
  policies: Record<string, unknown>;
  presentation: Record<string, unknown>;
  sections: HomepageSection[];
  product_images?: string[];
};
export const defaultHeader: HeaderConfig = { announcement: true, layout: "standard", sticky: false };
export const defaultFooter: FooterConfig = { layout: "standard", show_help: true, show_policies: true, columns: [] };
export interface StorefrontConfig { scope?: CommerceScope; theme: StorefrontTheme; vertical: ReturnType<typeof resolveVertical>; brand: { name: string; wordmark?: string; logoUrl?: string }; navigation: { menuKey: string; items: Array<{ label: string; href: string }> }; header: HeaderConfig; footer: FooterConfig; homepage: { sections: HomepageSection[] }; presentation: { productCardVariant: ProductCardVariant; productDetailVariant: ProductDetailVariant; productListVariant: "list-editorial" | "list-grid-wide"; purchaseVariant: PurchaseVariant }; purchase: { quoteMode: QuoteMode }; productImages: string[]; policies: { shippingUrl?: string; returnsUrl?: string; supportUrl?: string }; experiments: ExperimentConfig[]; version: number; }
export function resolveQuoteMode(value: unknown): QuoteMode { return value === "auto" || value === "off" ? value : "required"; }
export function getDefaultStorefrontConfig(): StorefrontConfig { return { theme: defaultTheme, vertical: resolveVertical("general"), brand: defaultTheme.brand, navigation: { menuKey: "primary", items: [] }, header: defaultHeader, footer: defaultFooter, homepage: { sections: defaultSections }, presentation: { productCardVariant: "editorial", productDetailVariant: "editorial", productListVariant: "list-editorial", purchaseVariant: "standard" }, purchase: { quoteMode: "required" }, productImages: [], policies: {}, experiments: [], version: 1 }; }
function isScope(value: unknown): value is CommerceScope { return Boolean(value && typeof value === "object" && ["store_id", "channel_id", "locale", "currency"].every((key) => typeof (value as Record<string, unknown>)[key] === "string" && (value as Record<string, unknown>)[key])); }
function safeHref(value: unknown): string | undefined { return typeof value === "string" && value.startsWith("/") && value.length <= 500 ? value : undefined; }
function configuredScope(): CommerceScope | undefined {
  const value = { store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? process.env.STOREFRONT_STORE_ID, channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? process.env.STOREFRONT_CHANNEL_ID, locale: process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? process.env.STOREFRONT_LOCALE, currency: process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? process.env.STOREFRONT_CURRENCY };
  return isScope(value) ? value : undefined;
}
export function sanitizeStorefrontConfig(value: unknown, scope?: CommerceScope): StorefrontConfig {
  const fallback = getDefaultStorefrontConfig();
  if (!value || typeof value !== "object") return fallback;
  const root = value as Record<string, unknown>; const data = root.data && typeof root.data === "object" ? root.data as Record<string, unknown> : root;
  const rawBrand = data.brand && typeof data.brand === "object" ? data.brand as Record<string, unknown> : {};
  const rawNavigation = data.navigation && typeof data.navigation === "object" ? data.navigation as Record<string, unknown> : {};
  const items = Array.isArray(rawNavigation.items) ? rawNavigation.items.flatMap((item) => { if (!item || typeof item !== "object") return []; const i = item as Record<string, unknown>; const href = safeHref(i.href); return typeof i.label === "string" && href ? [{ label: i.label.slice(0, 80), href }] : []; }) : [];
  const rawHomepage = data.homepage && typeof data.homepage === "object" ? data.homepage as Record<string, unknown> : {};
  const theme = resolveTheme(data.theme);
  const logoUrl = safeHref(rawBrand.logo_url ?? rawBrand.logoUrl);
  const wordmark = typeof rawBrand.wordmark === "string" && rawBrand.wordmark.trim() ? rawBrand.wordmark.slice(0, 120) : theme.brand.wordmark;
  const brand = { ...theme.brand, ...(typeof rawBrand.name === "string" && rawBrand.name.trim() ? { name: rawBrand.name.slice(0, 120) } : {}), ...(wordmark ? { wordmark } : {}), ...(logoUrl ? { logoUrl } : {}) };
  const rawPresentation = data.presentation && typeof data.presentation === "object" ? data.presentation as Record<string, unknown> : {};
  const rawPurchase = data.purchase && typeof data.purchase === "object" ? data.purchase as Record<string, unknown> : {};
  const rawHeader = data.header && typeof data.header === "object" ? data.header as Record<string, unknown> : {};
  const rawFooter = data.footer && typeof data.footer === "object" ? data.footer as Record<string, unknown> : {};
  const header: HeaderConfig = { announcement: rawHeader.announcement !== false, layout: asHeaderLayout(rawHeader.layout), sticky: rawHeader.sticky === true };
  const footerColumns = Array.isArray(rawFooter.columns) ? rawFooter.columns.slice(0, 6).flatMap((column) => {
    if (!column || typeof column !== "object") return [];
    const c = column as Record<string, unknown>;
    if (typeof c.title !== "string" || !Array.isArray(c.links)) return [];
    const links = c.links.slice(0, 8).flatMap((link) => {
      if (!link || typeof link !== "object") return [];
      const l = link as Record<string, unknown>;
      const href = safeHref(typeof l.href === "string" && l.href.startsWith("http") ? undefined : l.href) ?? (typeof l.href === "string" && /^https?:\/\//.test(l.href) ? l.href.slice(0, 512) : undefined);
      return typeof l.label === "string" && href ? [{ label: l.label.slice(0, 80), href }] : [];
    });
    return [{ title: c.title.slice(0, 80), links }];
  }) : [];
  const footer: FooterConfig = { layout: asFooterLayout(rawFooter.layout), show_help: rawFooter.show_help !== false, show_policies: rawFooter.show_policies !== false, columns: footerColumns };
  const cardVariant = asCardVariant(rawPresentation.product_card_variant);
  const detailVariant = asDetailVariant(rawPresentation.product_detail_variant);
  const purchaseVariant = asPurchaseVariant(rawPresentation.purchase_variant);
  const rawPolicies = data.policies && typeof data.policies === "object" ? data.policies as Record<string, unknown> : {};
  const experiments = Array.isArray(data.experiments) ? data.experiments.slice(0, 50).flatMap((item) => { if (!item || typeof item !== "object") return []; const e = item as Record<string, unknown>; if (typeof e.id !== "string" || !/^[a-z0-9][a-z0-9_-]{0,63}$/.test(e.id) || !Array.isArray(e.variants) || e.variants.length > 8) return []; const variants = e.variants.flatMap((v) => { if (!v || typeof v !== "object") return []; const x = v as Record<string, unknown>; return typeof x.id === "string" && /^[a-z0-9][a-z0-9_-]{0,31}$/.test(x.id) && Number.isInteger(x.weight) && Number(x.weight) > 0 && Number(x.weight) <= 10000 ? [{ id: x.id, weight: Number(x.weight) }] : []; }); return variants.length && variants.reduce((sum, v) => sum + v.weight, 0) <= 10000 ? [{ id: e.id, enabled: e.enabled === true, variants }] : []; }) : [];
  return { scope: isScope(data.scope) ? data.scope : scope, theme, vertical: resolveVertical(data.vertical && typeof data.vertical === "object" ? (data.vertical as Record<string, unknown>).key : data.vertical), brand, navigation: { menuKey: typeof rawNavigation.menu_key === "string" ? rawNavigation.menu_key.slice(0, 40) : "primary", items }, header, footer, homepage: { sections: resolveHomepageSections(rawHomepage.sections) }, presentation: { productCardVariant: cardVariant, productDetailVariant: detailVariant, productListVariant: asListLayout(rawPresentation.product_list_variant), purchaseVariant }, purchase: { quoteMode: resolveQuoteMode(rawPurchase.quote_mode ?? rawPurchase.quoteMode) }, productImages: Array.isArray(data.product_images) ? data.product_images.flatMap((image) => safeHref(image) ? [safeHref(image) as string] : []).slice(0, 12) : [], policies: { ...(safeHref(rawPolicies.shipping_url) ? { shippingUrl: safeHref(rawPolicies.shipping_url) } : {}), ...(safeHref(rawPolicies.returns_url) ? { returnsUrl: safeHref(rawPolicies.returns_url) } : {}), ...(safeHref(rawPolicies.support_url) ? { supportUrl: safeHref(rawPolicies.support_url) } : {}) }, experiments, version: typeof data.version === "number" && Number.isSafeInteger(data.version) ? data.version : 1 };
}
export async function getStorefrontConfig(scope: CommerceScope, options: { fetch?: typeof fetch; baseUrl?: string } = {}): Promise<StorefrontConfig> {
  const request = options.fetch ?? fetch; const baseUrl = options.baseUrl ?? process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL;
  if (!baseUrl) return getDefaultStorefrontConfig();
  try { const response = await request(`${baseUrl.replace(/\/$/, "")}/api/storefront/config`, { headers: { "X-Store-Id": scope.store_id, "X-Channel-Id": scope.channel_id, "X-Locale": scope.locale, "X-Currency": scope.currency }, cache: "no-store" }); if (!response.ok) throw new Error(`Config request failed: ${response.status}`); return sanitizeStorefrontConfig(await response.json(), scope); } catch (error) { if (process.env.NODE_ENV === "development") console.warn("Storefront config unavailable; using defaults.", error); return getDefaultStorefrontConfig(); }
}

export async function getPublicThemePresets(options: { fetch?: typeof fetch; baseUrl?: string } = {}): Promise<PublicThemePreset[]> {
  const request = options.fetch ?? fetch;
  const baseUrl = options.baseUrl ?? process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL;
  if (!baseUrl) return [];
  try {
    const scope = configuredScope();
    const response = await request(`${baseUrl.replace(/\/$/, "")}/api/storefront/theme-presets`, {
      cache: "no-store",
      headers: scope ? { "X-Store-Id": scope.store_id, "X-Channel-Id": scope.channel_id, "X-Locale": scope.locale, "X-Currency": scope.currency } : undefined,
    });
    if (!response.ok) return [];
    const body = await response.json() as { data?: unknown };
    const data = Array.isArray(body?.data) ? body.data : [];
    return data.flatMap((item) => {
      if (!item || typeof item !== "object") return [];
      const preset = item as Record<string, unknown>;
      return typeof preset.key === "string" && typeof preset.name === "string" && preset.theme && typeof preset.theme === "object"
        ? [preset as unknown as PublicThemePreset]
        : [];
    });
  } catch {
    return [];
  }
}

export async function getConfiguredStorefrontConfig(options: { fetch?: typeof fetch; baseUrl?: string; previewTheme?: string } = {}): Promise<StorefrontConfig> {
  const baseUrl = options.baseUrl ?? process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL;
  const scope = configuredScope();
  const config = baseUrl && scope ? await getStorefrontConfig(scope, { ...options, baseUrl }) : getDefaultStorefrontConfig();
  if (!baseUrl || !options.previewTheme) return config;
  const preset = (await getPublicThemePresets({ fetch: options.fetch, baseUrl })).find((item) => item.key === options.previewTheme);
  if (!preset) return config;
  return sanitizeStorefrontConfig({
    ...config,
    theme: preset.theme,
    vertical: { key: preset.key },
    brand: preset.brand,
    header: preset.header,
    footer: preset.footer,
    homepage: { sections: preset.sections },
    presentation: preset.presentation,
    purchase: preset.purchase,
    product_images: preset.product_images ?? [],
    policies: preset.policies,
  }, scope);
}
