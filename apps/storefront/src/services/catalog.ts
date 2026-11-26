import { ApiClientError, RequestCoordinator } from "@sirocommerce/api-client";
import type { CommerceScope } from "@sirocommerce/scope";
import type { BundleComponent, CatalogAdapter, CatalogFacet, CatalogResult, CommerceParty, CommerceSite, DeliveryContract, ImageVariant, Product, ProductImageFrame, ProductQuery, QuantityPrice, SubscriptionPlan, VendorProfile } from "@/types";

export const products: Product[] = [
  { id: "p-1", slug: "linen-camp-shirt", name: "Linen camp shirt", status: "active", productType: "simple", capability: "available", category: "apparel", categoryName: "Apparel", description: "A relaxed linen layer made for slow mornings and warm streets.", price: 6800, compareAtPrice: 8200, currency: "USD", image: "/catalog/b2c/linen-mandarin-shirt-front.svg", imageAlt: "Natural linen shirt illustration", rating: 4.8, reviewCount: 124, tags: ["linen", "new"] },
  { id: "p-2", slug: "ceramic-studio-mug", name: "Ceramic studio mug", status: "active", productType: "simple", capability: "available", category: "home", categoryName: "Home", description: "Hand-finished stoneware with a quiet, tactile glaze.", price: 3200, currency: "USD", image: "/catalog/b2c/jasmine-oolong-tea-front.svg", imageAlt: "Ceramic tea illustration", rating: 4.9, reviewCount: 88, tags: ["ceramic", "handmade"] },
  { id: "p-3", slug: "everyday-canvas-tote", name: "Everyday canvas tote", status: "active", productType: "simple", capability: "available", category: "accessories", categoryName: "Accessories", description: "A sturdy carry-all with room for market finds and daily essentials.", price: 4400, currency: "USD", image: "/catalog/b2c/structured-canvas-tote-front.svg", imageAlt: "Canvas tote bag illustration", rating: 4.7, reviewCount: 56, tags: ["canvas", "everyday"] },
  { id: "p-4", slug: "cloud-knit-cardigan", name: "Cloud knit cardigan", status: "active", productType: "simple", capability: "available", category: "apparel", categoryName: "Apparel", description: "A soft, oversized knit for cool evenings and considered layering.", price: 9600, compareAtPrice: 12000, currency: "USD", image: "/catalog/b2c/linen-mandarin-shirt-front.svg", imageAlt: "Soft knit illustration", rating: 4.6, reviewCount: 41, tags: ["knit", "layering"] },
  { id: "p-5", slug: "oak-desk-tray", name: "Oak desk tray", status: "active", productType: "simple", capability: "available", category: "home", categoryName: "Home", description: "Solid oak organization for the small things that make a space yours.", price: 5800, currency: "USD", image: "/catalog/b2c/stainless-steel-flask-500ml-front.svg", imageAlt: "Oak desk object illustration", rating: 4.8, reviewCount: 29, tags: ["oak", "organize"] },
  { id: "p-6", slug: "brass-key-ring", name: "Brass key ring", status: "out_of_stock", productType: "simple", capability: "available", category: "accessories", categoryName: "Accessories", description: "A small solid brass detail designed to age beautifully.", price: 2400, currency: "USD", image: "/catalog/b2c/natural-latex-mattress-front.svg", imageAlt: "Brass accessory illustration", rating: 4.5, reviewCount: 17, tags: ["brass", "gift"] },
  { id: "p-7", slug: "booking-yoga-retreat", name: "Yoga retreat — weekend booking", status: "active", productType: "booking", capability: "available", category: "wellness", categoryName: "Wellness", description: "Weekend yoga retreat with guided practice. Choose a slot, capacity is limited.", price: 4500, currency: "VND", image: "/catalog/b2c/linen-mandarin-shirt-front.svg", imageAlt: "Yoga retreat illustration", rating: 4.1, reviewCount: 2, tags: ["booking", "wellness"], bookingSlots: [{ id: "s1", variant_id: "p-7", starts_at: new Date(Date.now()+86400000).toISOString(), ends_at: new Date(Date.now()+86400000+7200000).toISOString(), capacity: 8, price_minor: 4500, currency: "VND" }, { id: "s2", variant_id: "p-7", starts_at: new Date(Date.now()+172800000).toISOString(), ends_at: new Date(Date.now()+172800000+7200000).toISOString(), capacity: 5, price_minor: 5000, currency: "VND" }] },
];

export const vendorProfiles: Record<string, VendorProfile> = {};

export const fixtureCatalog: CatalogAdapter = {
  async listProducts(query = {}) {
    throwIfAborted(query.signal);
    const search = query.search?.trim().toLowerCase();
    let result = products.filter((product) => (!query.category || product.category === query.category) && (!query.vendor || product.vendor?.slug === query.vendor) && (!search || `${product.name} ${product.description} ${product.tags.join(" ")}`.toLowerCase().includes(search)) && Object.entries(query.facets ?? {}).every(([name, values]) => values.length === 0 || values.some((value) => name === "category" ? product.category === value : name === "tag" && product.tags.includes(value))));
    if (query.sort === "price-asc") result = [...result].sort((a, b) => a.price - b.price || a.id.localeCompare(b.id));
    if (query.sort === "price-desc") result = [...result].sort((a, b) => b.price - a.price || a.id.localeCompare(b.id));
    if (query.page !== undefined || query.perPage !== undefined) {
      const page = Math.max(1, Math.floor(query.page ?? 1));
      const perPage = Math.min(100, Math.max(1, Math.floor(query.perPage ?? 100)));
      result = result.slice((page - 1) * perPage, page * perPage);
    }
    throwIfAborted(query.signal);
    return result;
  },
  async getProduct(slug, signal) { throwIfAborted(signal); const product = products.find((value) => value.slug === slug); throwIfAborted(signal); return product; },
  async getVendor(slug, signal) { throwIfAborted(signal); const vendor = vendorProfiles[slug]; throwIfAborted(signal); return vendor; },
  async listProductsResult(query = {}) {
    const all = await this.listProducts({ ...query, page: undefined, perPage: undefined });
    const facets: CatalogFacet[] = [
      { name: "category", label: "Category", values: [...new Set(all.map((product) => product.category))].map((value) => ({ value, label: all.find((product) => product.category === value)?.categoryName, count: all.filter((product) => product.category === value).length })) },
      { name: "tag", label: "Tag", values: [...new Set(all.flatMap((product) => product.tags))].map((value) => ({ value, count: all.filter((product) => product.tags.includes(value)).length })) },
    ];
    const page = Math.max(1, Math.floor(query.page ?? 1));
    const perPage = Math.min(100, Math.max(1, Math.floor(query.perPage ?? 100)));
    return { products: all.slice((page - 1) * perPage, page * perPage), total: all.length, facets };
  },
  async autocomplete(query, signal) { throwIfAborted(signal); const value = query.trim().slice(0, 80).toLowerCase(); const result = value ? products.filter((product) => product.name.toLowerCase().includes(value)).slice(0, 8) : []; throwIfAborted(signal); return result; },
};

function throwIfAborted(signal?: AbortSignal): void {
  if (signal?.aborted) throw new DOMException("The catalog request was aborted.", "AbortError");
}

function localProductImage(value: unknown, slug: string): string {
  if (typeof value === "string" && value.startsWith("/")) return value;
  const localImages: Record<string, string> = {
    "linen-camp-shirt": "/catalog/b2c/linen-mandarin-shirt-front.svg",
    "everyday-canvas-tote": "/catalog/b2c/structured-canvas-tote-front.svg",
  };
  return localImages[slug] ?? "/placeholder-product.svg";
}

interface ApiProduct {
  id?: string | number;
  name?: string;
  slug?: string;
  status?: string;
  category?: string;
  category_name?: string;
  categoryName?: string;
  description?: string;
  price?: number | string;
  compare_at_price?: number | string | null;
  compareAtPrice?: number | string | null;
  currency?: string;
  image?: string;
  image_alt?: string;
  imageAlt?: string;
  rating?: number;
  review_count?: number;
  reviewCount?: number;
  tags?: string[];
  stock?: number;
  is_active?: boolean;
  type?: string;
  product_type?: string;
  allow_backorder?: boolean;
  is_preorder?: boolean;
  attributes?: unknown;
  options?: unknown;
  option_pricing?: unknown;
  optionPricing?: unknown;
  quantity_pricing?: unknown;
  quantityPricing?: unknown;
  delivery?: unknown;
  vendor?: unknown;
  site?: unknown;
  subscription_plans?: unknown;
  subscriptionPlans?: unknown;
  bundle_components?: unknown;
  bundleComponents?: unknown;
  variant_options?: unknown;
  variantOptions?: unknown;
  grouped_items?: unknown;
  groupedItems?: unknown;
}

interface ApiVendor {
  id?: string;
  name?: string;
  slug?: string;
  status?: string;
  suspended?: boolean;
  description?: string | null;
  contact_email?: string | null;
  product_count?: number;
}

export class CatalogRequestError extends Error {
  constructor(readonly status: number, message: string) {
    super(message);
    this.name = "CatalogRequestError";
  }
}

export function isCatalogAbort(error: unknown): boolean {
  return (error instanceof DOMException && error.name === "AbortError") || (error instanceof Error && error.name === "AbortError");
}

/**
 * A product page is a gallery. Frames are de-duplicated, the primary image stays
 * first, and any frame the catalog cannot resolve is dropped rather than shown
 * as a broken image.
 */
const YOUTUBE_HOSTS = ["youtube.com", "www.youtube.com", "m.youtube.com", "youtu.be", "vimeo.com", "www.vimeo.com", "player.vimeo.com"];
function frameType(raw: string | undefined, url: string): "image" | "video" | "youtube" | undefined {
  const t = (raw ?? "").toLowerCase();
  if (t === "video" || t === "youtube" || t === "image") return t;
  if (/^https?:\/\//i.test(url)) {
    try {
      const u = new URL(url);
      if (YOUTUBE_HOSTS.includes(u.hostname.toLowerCase())) return "youtube";
      if (/\.(mp4|webm)$/i.test(u.pathname)) return "video";
    } catch { /* relative URL -> image */ }
  } else if (/\.(mp4|webm)$/i.test(url)) return "video";
  return undefined;
}
function imageVariants(value: unknown): ImageVariant[] | undefined {
  if (!Array.isArray(value)) return undefined;
  const variants: ImageVariant[] = [];
  for (const entry of value) {
    if (!entry || typeof entry !== "object") continue;
    const variant = entry as { w?: unknown; url?: unknown; format?: unknown };
    const width = Number(variant.w);
    if (!Number.isFinite(width) || width <= 0 || typeof variant.url !== "string" || !variant.url.trim()) continue;
    variants.push({ w: Math.round(width), url: variant.url.trim(), ...(typeof variant.format === "string" && variant.format.trim() ? { format: variant.format.trim().toLowerCase() } : {}) });
  }
  return variants.length > 0 ? variants.sort((a, b) => a.w - b.w) : undefined;
}
function imagePlaceholder(value: unknown): string | undefined {
  if (typeof value !== "string") return undefined;
  const candidate = value.trim();
  return candidate !== "" ? candidate : undefined;
}
function productGallery(value: ApiProduct, slug: string): ProductImageFrame[] {
  const fallbackAlt = value.image_alt ?? value.name ?? "Product image";
  const seen = new Set<string>();
  const frames: ProductImageFrame[] = [];
  const push = (url: unknown, alt: unknown, type?: unknown, variants?: unknown, placeholder?: unknown) => {
    // Video/embed frames keep their remote URL; images keep the legacy local resolution.
    if (typeof url === "string" && (/^https?:\/\//i.test(url) || /\.(mp4|webm)$/i.test(url))) {
      const ft = frameType(typeof type === "string" ? type : undefined, url);
      if (ft === "video" || ft === "youtube") {
        if (seen.has(url)) return;
        seen.add(url);
        frames.push({ url, alt: typeof alt === "string" && alt !== "" ? alt : fallbackAlt, type: ft });
        return;
      }
    }
    const resolved = localProductImage(url, slug);
    if (resolved === "/placeholder-product.svg") return;
    const parsedVariants = imageVariants(variants);
    const parsedPlaceholder = imagePlaceholder(placeholder);
    if (seen.has(resolved)) {
      const existing = frames.find((frame) => frame.url === resolved);
      if (existing) {
        if (parsedVariants) existing.variants = parsedVariants;
        if (parsedPlaceholder) existing.placeholder = parsedPlaceholder;
      }
      return;
    }
    seen.add(resolved);
    frames.push({
      url: resolved,
      alt: typeof alt === "string" && alt !== "" ? alt : fallbackAlt,
      ...(parsedVariants ? { variants: parsedVariants } : {}),
      ...(parsedPlaceholder ? { placeholder: parsedPlaceholder } : {}),
    });
  };
  push(value.image, value.image_alt);
  const raw = (value as { images?: unknown; media?: unknown }).images ?? (value as { media?: unknown }).media;
  if (Array.isArray(raw)) for (const entry of raw) {
    const item = entry && typeof entry === "object" ? (entry as { url?: unknown; alt?: unknown; type?: unknown; variants?: unknown; placeholder?: unknown }) : {};
    push(item.url, item.alt, item.type, item.variants, item.placeholder);
  }
  const primary = localProductImage(value.image, slug);
  if (frames.length > 0 && frames[0]!.url !== primary) frames.unshift({ url: primary, alt: fallbackAlt });
  return frames;
}

function productFromApi(value: ApiProduct): Product {
  const id = value.id ?? value.slug;
  const name = value.name;
  const slug = value.slug;
  const gallery = productGallery(value, slug ?? "");
  const price = value.price === undefined ? 0 : typeof value.price === "number" ? value.price : typeof value.price === "string" && value.price.trim() ? Number(value.price) : Number.NaN;
  const rawCompareAtPrice = value.compare_at_price ?? value.compareAtPrice;
  const compareAtPrice = rawCompareAtPrice == null ? undefined : typeof rawCompareAtPrice === "number" ? rawCompareAtPrice : typeof rawCompareAtPrice === "string" && rawCompareAtPrice.trim() ? Number(rawCompareAtPrice) : Number.NaN;
  const validCurrency = value.currency === undefined || (typeof value.currency === "string" && value.currency.trim().length > 0);
  if ((typeof id !== "string" && typeof id !== "number") || typeof name !== "string" || !name.trim() || typeof slug !== "string" || !slug.trim() || !Number.isFinite(price) || price < 0 || (compareAtPrice !== undefined && (!Number.isFinite(compareAtPrice) || compareAtPrice < 0 || compareAtPrice < price)) || (value.status !== undefined && value.status !== "active" && value.status !== "out_of_stock" && value.status !== "backorder") || !validCurrency) {
    throw new CatalogRequestError(502, "The catalog returned invalid product data.");
  }
  const category = typeof value.category === "string" && value.category.trim() ? value.category : "collection";
  const stock = value.stock === undefined ? undefined : Number(value.stock);
  const rating = Number(value.rating ?? 0);
  const reviewCount = Number(value.review_count ?? value.reviewCount ?? 0);
  const tags = value.tags ?? [];
  const rawType = value.product_type ?? value.type ?? "simple";
  const supportedTypes = ["simple", "variable", "configurable", "bundle", "downloadable", "grouped", "virtual", "booking"] as const;
  const productType = typeof rawType === "string" && supportedTypes.includes(rawType as typeof supportedTypes[number]) ? rawType as Product["productType"] : "unsupported";
  const attributes = parseAttributes(value.attributes);
  const options = parseOptions(value.options);
  const variantOptions = parseVariantOptions(value.variant_options ?? value.variantOptions);
  const groupedItems = parseGroupedItems(value.grouped_items ?? value.groupedItems, value.currency ?? "USD");
  const optionPricing = parseOptionPricing(value.option_pricing ?? value.optionPricing, value.currency ?? "USD");
  const quantityPricing = parseQuantityPricing(value.quantity_pricing ?? value.quantityPricing, value.currency ?? "USD");
  const delivery = parseDelivery(value.delivery);
  const vendor = parseParty(value.vendor);
  const site = parseSite(value.site);
  const subscriptionPlans = parseSubscriptionPlans(value.subscription_plans ?? value.subscriptionPlans, value.currency ?? "USD");
  const bundleComponents = parseBundleComponents(value.bundle_components ?? value.bundleComponents);
  const bookingSlots = parseBookingSlots((value as unknown as Record<string, unknown>).booking_slots ?? (value as unknown as Record<string, unknown>).bookingSlots);
  const booking = parseBooking((value as unknown as Record<string, unknown>).booking);
  const flashSales = parseFlashSales((value as unknown as Record<string, unknown>).flash_sales ?? (value as unknown as Record<string, unknown>).flashSales);
  const memberPrices = parseMemberPrices((value as unknown as Record<string, unknown>).member_prices ?? (value as unknown as Record<string, unknown>).memberPrices);
  const stockBySource = parseStockBySource((value as unknown as Record<string, unknown>).stock_by_source);
  if (stock !== undefined && (!Number.isFinite(stock) || stock < 0) || !Number.isFinite(rating) || rating < 0 || !Number.isFinite(reviewCount) || reviewCount < 0 || !Array.isArray(tags) || tags.some((tag) => typeof tag !== "string") || (value.attributes !== undefined && !attributes) || (value.options !== undefined && !options) || (value.option_pricing !== undefined && !optionPricing) || (value.optionPricing !== undefined && !optionPricing) || (value.quantity_pricing !== undefined && !quantityPricing) || (value.quantityPricing !== undefined && !quantityPricing)) throw new CatalogRequestError(502, "The catalog returned invalid product data.");
  const allowBackorder = value.allow_backorder === true;
  return {
    id: String(id),
    name,
    slug,
    status: value.status === "out_of_stock" || value.is_active === false ? "out_of_stock" : allowBackorder && stock === 0 ? "backorder" : stock === 0 ? "out_of_stock" : "active",
    productType,
    capability: productType === "unsupported" ? "unavailable" : "available",
    category,
    categoryName: value.category_name ?? value.categoryName ?? category,
    description: value.description ?? "",
    price,
    compareAtPrice,
    currency: value.currency ?? "USD",
    image: gallery[0]?.url ?? localProductImage(value.image, slug),
    imageAlt: gallery[0]?.alt ?? value.image_alt ?? value.imageAlt ?? value.name ?? "Product image",
    ...(gallery.length > 0 ? { images: gallery } : {}),
    rating,
    reviewCount,
    tags,
    stock,
    allowBackorder,
    ...(value.is_preorder === true ? { is_preorder: true as const } : {}),
    attributes: attributes ?? undefined,
    options: options ?? undefined,
    ...(optionPricing ? { optionPricing } : {}),
    ...(quantityPricing ? { quantityPricing } : {}),
    ...(delivery ? { delivery } : {}),
    ...(vendor ? { vendor } : {}),
    ...(site ? { site } : {}),
    ...(subscriptionPlans ? { subscriptionPlans } : {}),
    ...(bundleComponents ? { bundleComponents } : {}),
    ...(variantOptions ? { variantOptions } : {}),
    ...(groupedItems ? { groupedItems } : {}),
    ...(bookingSlots ? { bookingSlots } : {}),
    ...(booking ? { booking } : {}),
    ...(flashSales ? { flashSales } : {}),
    ...(memberPrices ? { memberPrices } : {}),
    ...(stockBySource ? { stockBySource } : {}),
  };
}

function parseParty(value: unknown): CommerceParty | undefined {
  if (!value || typeof value !== "object") return undefined;
  const item = value as Record<string, unknown>;
  return typeof item.id === "string" && typeof item.name === "string" && item.id.trim() && item.name.trim() ? { id: item.id, name: item.name, ...(typeof item.slug === "string" && item.slug.trim() ? { slug: item.slug } : {}) } : undefined;
}

function parseVendorProfile(value: unknown): VendorProfile | undefined {
  if (!value || typeof value !== "object") return undefined;
  const item = value as ApiVendor;
  const productCount = Number(item.product_count ?? 0);
  if (typeof item.id !== "string" || !item.id.trim() || typeof item.name !== "string" || !item.name.trim() || typeof item.slug !== "string" || !item.slug.trim() || (item.status !== "active" && item.status !== "suspended") || !Number.isSafeInteger(productCount) || productCount < 0) return undefined;
  return {
    id: item.id,
    name: item.name,
    slug: item.slug,
    status: item.status,
    suspended: item.suspended === true || item.status === "suspended",
    ...(typeof item.description === "string" && item.description.trim() ? { description: item.description } : {}),
    ...(item.contact_email === null || typeof item.contact_email === "string" ? { contact_email: item.contact_email } : {}),
    product_count: productCount,
  };
}

function parseSite(value: unknown): CommerceSite | undefined {
  const party = parseParty(value);
  if (!party) return undefined;
  const item = value as Record<string, unknown>;
  return { ...party, ...(typeof item.locale === "string" ? { locale: item.locale } : {}), ...(typeof item.currency === "string" ? { currency: item.currency } : {}) };
}

function parseDelivery(value: unknown): DeliveryContract | undefined {
  if (!value || typeof value !== "object") return undefined;
  const item = value as Record<string, unknown>;
  const modes = ["physical", "virtual", "downloadable", "scheduled", "unknown"] as const;
  const states = ["available", "pending", "unavailable", "unknown"] as const;
  const reservations = ["not_reserved", "reserved", "unknown"] as const;
  const mode = modes.includes(item.mode as typeof modes[number]) ? item.mode as DeliveryContract["mode"] : "unknown";
  const state = states.includes(item.state as typeof states[number]) ? item.state as DeliveryContract["state"] : "unknown";
  return { mode, state, ...(typeof item.scheduled_for === "string" ? { scheduled_for: item.scheduled_for } : {}), ...(typeof item.message === "string" ? { message: item.message } : {}), ...(typeof item.warehouse === "string" ? { warehouse: item.warehouse } : {}), reservation: reservations.includes(item.reservation as typeof reservations[number]) ? item.reservation as DeliveryContract["reservation"] : "unknown" };
}

function parseSubscriptionPlans(value: unknown, fallbackCurrency: string): SubscriptionPlan[] | undefined {
  if (!Array.isArray(value)) return value === undefined ? undefined : [];
  const parsed = value.map((entry) => {
    if (!entry || typeof entry !== "object") return null;
    const item = entry as Record<string, unknown>;
    const rawPrice = item.price !== undefined ? item.price : item.amount_minor;
    const price = typeof rawPrice === "number" ? rawPrice : Number(rawPrice);
    const currency = typeof item.currency === "string" ? item.currency : fallbackCurrency;
    const intervalCountRaw = item.interval_count ?? item.intervalCount;
    const intervalCount = intervalCountRaw === undefined || intervalCountRaw === null ? undefined : Number(intervalCountRaw);
    const trialPeriod = typeof item.trial_period === "string" ? item.trial_period : typeof item.trialPeriod === "string" ? item.trialPeriod : null;
    const trialPeriodCountRaw = item.trial_period_count ?? item.trialPeriodCount;
    const trialPeriodCount = trialPeriodCountRaw === undefined || trialPeriodCountRaw === null ? null : Number(trialPeriodCountRaw);
    return typeof item.id === "string" && typeof item.name === "string" && typeof item.interval === "string" && Number.isFinite(price) && price >= 0 && /^[A-Z]{3}$/.test(currency) && (intervalCount === undefined || (Number.isSafeInteger(intervalCount) && intervalCount > 0)) && (trialPeriodCount === null || (Number.isSafeInteger(trialPeriodCount) && trialPeriodCount >= 0))
      ? { id: item.id, name: item.name, interval: item.interval, price, currency, purchasable: false as const, ...(intervalCount !== undefined ? { intervalCount } : {}), trialPeriod, trialPeriodCount }
      : null;
  });
  return parsed.every(Boolean) ? parsed as SubscriptionPlan[] : undefined;
}

function parseBundleComponents(value: unknown): BundleComponent[] | undefined {
  if (!Array.isArray(value)) return value === undefined ? undefined : [];
  const parsed = value.map((entry) => {
    if (!entry || typeof entry !== "object") return null;
    const item = entry as Record<string, unknown>;
    const quantity = typeof item.quantity === "number" ? item.quantity : Number(item.quantity);
    const options = item.options;
    return typeof item.id === "string" && typeof item.name === "string" && Number.isSafeInteger(quantity) && quantity > 0 && typeof item.required === "boolean" && (options === undefined || Array.isArray(options) && options.every((option) => typeof option === "string")) ? { id: item.id, name: item.name, quantity, required: item.required, ...(Array.isArray(options) ? { options: options as string[] } : {}) } : null;
  });
  return parsed.every(Boolean) ? parsed as BundleComponent[] : undefined;
}

function parseOptionPricing(value: unknown, fallbackCurrency: string): Product["optionPricing"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    const record = item && typeof item === "object" ? item as Record<string, unknown> : {};
    const surcharge = typeof record.surcharge === "number" ? record.surcharge : typeof record.surcharge === "string" && record.surcharge.trim() ? Number(record.surcharge) : Number.NaN;
    const option = record.option ?? record.name;
    const currency = record.currency ?? fallbackCurrency;
    return typeof option === "string" && typeof record.value === "string" && Number.isFinite(surcharge) && surcharge >= 0 && typeof currency === "string" && /^[A-Z]{3}$/.test(currency) ? { option, value: record.value, surcharge, currency } : null;
  });
  return parsed.every(Boolean) ? parsed as Product["optionPricing"] : undefined;
}

function parseQuantityPricing(value: unknown, fallbackCurrency: string): QuantityPrice[] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    const record = item && typeof item === "object" ? item as Record<string, unknown> : {};
    const minimum = typeof record.minimum === "number" ? record.minimum : Number(record.minimum);
    const price = typeof record.price === "number" ? record.price : Number(record.price);
    const currency = record.currency ?? fallbackCurrency;
    return Number.isSafeInteger(minimum) && minimum > 0 && Number.isFinite(price) && price >= 0 && typeof currency === "string" && /^[A-Z]{3}$/.test(currency) ? { minimum, price, currency } : null;
  });
  return parsed.every(Boolean) ? parsed as QuantityPrice[] : undefined;
}

function parseFacets(value: unknown): CatalogFacet[] {
  const entries = Array.isArray(value) ? value : value && typeof value === "object" ? Object.entries(value).map(([name, values]) => ({ name, values })) : [];
  return entries.flatMap((item) => {
    if (!item || typeof item !== "object") return [];
    const record = item as Record<string, unknown>;
    const values = Array.isArray(record.values) ? record.values : [];
    const parsed = values.flatMap((entry) => {
      if (!entry || typeof entry !== "object") return [];
      const valueRecord = entry as Record<string, unknown>;
      const count = Number(valueRecord.count);
      return typeof valueRecord.value === "string" && Number.isSafeInteger(count) && count >= 0 ? [{ value: valueRecord.value, label: typeof valueRecord.label === "string" ? valueRecord.label : undefined, count }] : [];
    });
    return typeof record.name === "string" && parsed.length === values.length ? [{ name: record.name, label: typeof record.label === "string" ? record.label : record.name, values: parsed }] : [];
  });
}

function parseAttributes(value: unknown): Product["attributes"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    if (!item || typeof item !== "object") return null;
    const record = item as Record<string, unknown>;
    return typeof record.name === "string" && typeof record.value === "string" ? { name: record.name, value: record.value } : null;
  });
  return parsed.every(Boolean) ? parsed as Product["attributes"] : undefined;
}

function parseVariantOptions(value: unknown): Product["variantOptions"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    const record = item && typeof item === "object" ? item as Record<string, unknown> : {};
    const options = record.options && typeof record.options === "object" ? Object.fromEntries(Object.entries(record.options as Record<string, unknown>).filter(([, option]) => typeof option === "string")) : {};
    const price = typeof record.price_minor === "number" ? record.price_minor : Number(record.price_minor);
    const compare = record.compare_price_minor === null || record.compare_price_minor === undefined ? undefined : Number(record.compare_price_minor);
    const stock = record.stock === null || record.stock === undefined ? undefined : Number(record.stock);
    return typeof record.variant_id === "string" && Object.keys(options).length > 0 && Number.isFinite(price) && price >= 0
      ? { variantId: record.variant_id, options: options as Record<string, string>, price, ...(compare !== undefined && Number.isFinite(compare) ? { comparePrice: compare } : {}), ...(typeof record.sku === "string" ? { sku: record.sku } : {}), ...(stock !== undefined && Number.isFinite(stock) ? { stock } : {}) }
      : null;
  });
  return parsed.every(Boolean) ? parsed as Product["variantOptions"] : undefined;
}

function parseGroupedItems(value: unknown, fallbackCurrency: string): Product["groupedItems"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    const record = item && typeof item === "object" ? item as Record<string, unknown> : {};
    const price = typeof record.price_minor === "number" ? record.price_minor : Number(record.price_minor);
    const stock = record.stock === null || record.stock === undefined ? undefined : Number(record.stock);
    return typeof record.variant_id === "string" && typeof record.name === "string" && Number.isFinite(price) && price >= 0
      ? {
        variantId: record.variant_id,
        name: record.name,
        ...(typeof record.slug === "string" ? { slug: record.slug } : {}),
        price,
        currency: typeof record.currency === "string" && /^[A-Z]{3}$/.test(record.currency) ? record.currency : fallbackCurrency,
        ...(typeof record.image === "string" && record.image ? { image: record.image } : {}),
        ...(stock !== undefined && Number.isFinite(stock) ? { stock } : {}),
        ...(record.backorderable === true ? { backorderable: true } : {}),
      }
      : null;
  });
  return parsed.every(Boolean) ? parsed as Product["groupedItems"] : undefined;
}

function parseBookingSlots(value: unknown): Product["bookingSlots"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    if (!item || typeof item !== "object") return null;
    const r = item as Record<string, unknown>;
    const starts = typeof r.starts_at === "string" ? r.starts_at : typeof r.startsAt === "string" ? r.startsAt : "";
    const ends = typeof r.ends_at === "string" ? r.ends_at : typeof r.endsAt === "string" ? r.endsAt : "";
    const cap = typeof r.capacity === "number" ? r.capacity : Number(r.capacity);
    const price = r.price_minor === undefined || r.price_minor === null ? null : typeof r.price_minor === "number" ? r.price_minor : Number(r.price_minor);
    return typeof r.id === "string" && typeof r.variant_id === "string" && starts && ends && Number.isFinite(cap) ? { id: r.id, variant_id: r.variant_id, starts_at: starts, ends_at: ends, capacity: cap, price_minor: price !== null && Number.isFinite(price) ? price : null, currency: typeof r.currency === "string" ? r.currency : null } : null;
  });
  return parsed.every(Boolean) ? parsed as Product["bookingSlots"] : undefined;
}
function parseBooking(value: unknown): Product["booking"] | undefined {
  if (!value || typeof value !== "object") return undefined;
  const r = value as Record<string, unknown>;
  if (typeof r.variant_id !== "string") return undefined;
  return { variant_id: r.variant_id, slots_count: typeof r.slots_count === "number" ? r.slots_count : 0, ...(r.delivery && typeof r.delivery === "object" ? { delivery: r.delivery as never } : {}) };
}
function parseFlashSales(value: unknown): Product["flashSales"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    if (!item || typeof item !== "object") return null;
    const r = item as Record<string, unknown>;
    const price = typeof r.sale_price_minor === "number" ? r.sale_price_minor : Number(r.sale_price_minor);
    if (typeof r.sale_id !== "string" || typeof r.variant_id !== "string" || typeof r.ends_at !== "string" || !Number.isFinite(price) || price < 0) return null;
    return { sale_id: r.sale_id, sale_name: typeof r.sale_name === "string" ? r.sale_name : "", variant_id: r.variant_id, sale_price_minor: price, starts_at: typeof r.starts_at === "string" ? r.starts_at : "", ends_at: r.ends_at, cap_qty: typeof r.cap_qty === "number" ? r.cap_qty : 0, sold_qty: typeof r.sold_qty === "number" ? r.sold_qty : 0, remaining: r.remaining === null || r.remaining === undefined ? null : Number(r.remaining), priority: typeof r.priority === "number" ? r.priority : 0 };
  });
  return parsed.every(Boolean) ? parsed as Product["flashSales"] : undefined;
}
function parseMemberPrices(value: unknown): Product["memberPrices"] | undefined {
  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    if (!item || typeof item !== "object") return null;
    const r = item as Record<string, unknown>;
    const price = typeof r.price_minor === "number" ? r.price_minor : Number(r.price_minor);
    if (typeof r.variant_id !== "string" || typeof r.tier !== "string" || !Number.isFinite(price) || price < 0) return null;
    return { id: typeof r.id === "string" ? r.id : "", variant_id: r.variant_id, tier: r.tier, price_minor: price, currency: typeof r.currency === "string" ? r.currency : "VND" };
  });
  return parsed.every(Boolean) ? parsed as Product["memberPrices"] : undefined;
}
function parseStockBySource(value: unknown): Product["stockBySource"] | undefined {
  if (!value || typeof value !== "object" || Array.isArray(value)) return undefined;
  const out: Record<string, Record<string, number>> = {};
  for (const [vid, srcs] of Object.entries(value as Record<string, unknown>)) {
    if (!srcs || typeof srcs !== "object" || Array.isArray(srcs)) return undefined;
    const inner: Record<string, number> = {};
    for (const [src, qty] of Object.entries(srcs as Record<string, unknown>)) {
      const n = typeof qty === "number" ? qty : Number(qty);
      if (!Number.isFinite(n) || n < 0) return undefined;
      inner[src] = n;
    }
    out[vid] = inner;
  }
  return out;
}
function parseOptions(value: unknown): Product["options"] | undefined {  if (value === undefined) return undefined;
  if (!Array.isArray(value)) return undefined;
  const parsed = value.map((item) => {
    if (!item || typeof item !== "object") return null;
    const record = item as Record<string, unknown>;
    return typeof record.name === "string" && Array.isArray(record.values) && record.values.every((entry) => typeof entry === "string") ? { name: record.name, values: record.values as string[] } : null;
  });
  return parsed.every(Boolean) ? parsed as Product["options"] : undefined;
}

function dedupeProducts(values: Product[]): Product[] {
  const seen = new Set<string>();
  return values.filter((product) => {
    const keys = [`id:${product.id}`, `slug:${product.slug}`];
    if (keys.some((key) => seen.has(key))) return false;
    keys.forEach((key) => seen.add(key));
    return true;
  });
}

function apiScope(): CommerceScope | undefined {
  const value = {
    store_id: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? process.env.STOREFRONT_STORE_ID,
    channel_id: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? process.env.STOREFRONT_CHANNEL_ID,
    locale: process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? process.env.STOREFRONT_LOCALE ?? "en",
    currency: process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? process.env.STOREFRONT_CURRENCY ?? "USD",
  };
  return value.store_id && value.channel_id ? value as CommerceScope : undefined;
}

export function createApiCatalog(options: { baseUrl: string; fetch?: typeof fetch; scope?: CommerceScope; cacheTtlMs?: number } ): CatalogAdapter {
  const client = new RequestCoordinator({ baseUrl: options.baseUrl, fetch: options.fetch });
  const scope = options.scope ?? apiScope();
  const cache = new Map<string, { expiresAt: number; value: Product[] | Product | undefined; facets?: CatalogFacet[]; total?: number }>();
  const configuredTtl = options.cacheTtlMs ?? Number(process.env.STOREFRONT_CATALOG_CACHE_TTL_MS ?? 30_000);
  const cacheTtlMs = Number.isFinite(configuredTtl) ? Math.max(0, configuredTtl) : 30_000;
  const locale = scope?.locale ?? process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? process.env.STOREFRONT_LOCALE ?? "en";
  const currency = scope?.currency ?? process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? process.env.STOREFRONT_CURRENCY ?? "USD";
  const scopeKey = JSON.stringify({ ...(scope ?? {}), locale, currency });
  const key = (kind: string, value: unknown) => `${scopeKey}:${kind}:${JSON.stringify(value)}`;
  let cacheGeneration = 0;
  async function request<T>(path: string, query: Record<string, string>, signal?: AbortSignal): Promise<{ data: T; meta: Record<string, unknown> }> {
    try {
      const suffix = new URLSearchParams(query).toString();
       const result = await client.request<T>(`${path}${suffix ? `?${suffix}` : ""}`, { scope, signal });
       return { data: result.body.data, meta: result.body.meta ?? {} };
    } catch (error) {
      if (isCatalogAbort(error)) throw error;
      if (error instanceof ApiClientError) throw new CatalogRequestError(error.status, error.message);
      throw new CatalogRequestError(503, "The catalog service is unavailable.");
    }
  }
  async function listResult(query: ProductQuery = {}): Promise<CatalogResult> {
        throwIfAborted(query.signal);
        const generation = cacheGeneration;
      const page = Math.max(1, Math.floor(query.page ?? 1));
      const perPage = Math.min(100, Math.max(1, Math.floor(query.perPage ?? 100)));
       const cacheKey = key("list", { category: query.category ?? "", search: query.search ?? "", vendor: query.vendor ?? "", sort: query.sort ?? "featured", page, perPage, facets: query.facets ?? {} });
      const cached = cache.get(cacheKey);
        if (cached && cached.expiresAt > Date.now()) return { products: cached.value as Product[], facets: cached.facets ?? [], total: cached.total };
       const params: Record<string, string> = { page: String(page), per_page: String(perPage), locale, currency };
      if (query.category) params.category = query.category;
       if (query.search) params.search = query.search;
       if (query.vendor) params.vendor = query.vendor;
       if (query.sort && query.sort !== "featured") params.sort = query.sort;
       for (const [name, values] of Object.entries(query.facets ?? {})) if (values.length > 0) params[`facet_${name}`] = values.join(",");
        const response = await request<ApiProduct[]>("/api/products", params, query.signal);
        const data = response.data;
       throwIfAborted(query.signal);
       if (!Array.isArray(data)) throw new CatalogRequestError(502, "The catalog returned invalid product data.");
        let result = dedupeProducts(data.map(productFromApi));
      if (query.category) result = result.filter((product) => product.category === query.category);
      if (query.search) {
        const needle = query.search.toLowerCase();
        result = result.filter((product) => `${product.name} ${product.description} ${product.tags.join(" ")}`.toLowerCase().includes(needle));
      }
      if (query.sort === "price-asc") result.sort((a, b) => a.price - b.price);
      if (query.sort === "price-desc") result.sort((a, b) => b.price - a.price);
        const facets = parseFacets(response.meta.facets);
        const total = typeof response.meta.total === "number" ? response.meta.total : undefined;
        if (generation === cacheGeneration && !query.signal?.aborted) cache.set(cacheKey, { expiresAt: Date.now() + cacheTtlMs, value: result, facets, total });
        return { products: result, total, facets };
  }
  return {
     async listProducts(query = {}) { return (await listResult(query)).products; },
     listProductsResult: listResult,
     async autocomplete(query, signal) {
       const value = query.trim();
       if (!value) return [];
        return (await listResult({ search: value.slice(0, 80), perPage: 8, signal })).products.slice(0, 8);
     },
     async getProduct(slug, signal) {
       throwIfAborted(signal);
       const generation = cacheGeneration;
      const cacheKey = key("detail", slug);
      const cached = cache.get(cacheKey);
      if (cached && cached.expiresAt > Date.now()) return cached.value as Product | undefined;
      try {
            const detailData = (await request<ApiProduct | unknown[]>(`/api/products/${encodeURIComponent(slug)}`, { locale, currency }, signal)).data;
          if (Array.isArray(detailData)) return undefined;
          const product = productFromApi(detailData);
         throwIfAborted(signal);
         if (generation === cacheGeneration && !signal?.aborted) cache.set(cacheKey, { expiresAt: Date.now() + cacheTtlMs, value: product });
         return product;
       }
       catch (error) {
         if (error instanceof CatalogRequestError && error.status === 404) {
           if (generation === cacheGeneration && !signal?.aborted) cache.set(cacheKey, { expiresAt: Date.now() + cacheTtlMs, value: undefined });
           return undefined;
         }
         throw error;
       }
       },
     async getVendor(slug, signal) {
       throwIfAborted(signal);
       try {
         const data = (await request<ApiVendor>(`/api/vendors/${encodeURIComponent(slug)}`, { locale, currency }, signal)).data;
         const vendor = parseVendorProfile(data);
         if (!vendor) throw new CatalogRequestError(502, "The catalog returned invalid vendor data.");
         throwIfAborted(signal);
         return vendor;
       } catch (error) {
         if (error instanceof CatalogRequestError && error.status === 404) return undefined;
         throw error;
       }
     },
     invalidateCache() { cacheGeneration += 1; cache.clear(); },
  };
}

export function catalog(scope?: CommerceScope): CatalogAdapter {
  if (process.env.STOREFRONT_CATALOG_SOURCE === "fixture") return fixtureCatalog;
  return createApiCatalog({ baseUrl: process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL ?? "http://127.0.0.1:1", ...(scope ? { scope } : {}) });
}
