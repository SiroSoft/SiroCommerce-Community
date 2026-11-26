export type RecentlyViewedProduct = {
  slug: string;
  name: string;
  image: string;
  imageAlt: string;
  priceMinor: number;
  currency: string;
};

type StoredRecentlyViewed = RecentlyViewedProduct & { viewedAt: number };

export const recentlyViewedProductsKey = "sirocommerce:recently-viewed-products";
export const recentlyViewedProductsLimit = 8;
export const recentlyViewedProductsMaxAgeMs = 30 * 24 * 60 * 60 * 1000;

const slugPattern = /^[A-Za-z0-9][A-Za-z0-9._~-]{0,159}$/;
const currencyPattern = /^[A-Z]{3}$/;

function storageOrUndefined(): Storage | undefined {
  if (typeof window === "undefined") return undefined;
  try {
    return window.localStorage;
  } catch {
    return undefined;
  }
}

function safeSlug(value: unknown): string | undefined {
  return typeof value === "string" && slugPattern.test(value) ? value : undefined;
}

function safeName(value: unknown): string | undefined {
  if (typeof value !== "string") return undefined;
  const name = value.trim();
  return name !== "" && name.length <= 200 ? name : undefined;
}

function safeImage(value: unknown): string {
  if (typeof value !== "string") return "";
  const image = value.trim();
  if (image.startsWith("/") && !image.startsWith("//")) return image.slice(0, 2048);
  if (/^https?:\/\//i.test(image)) return image.slice(0, 2048);
  return "";
}

function safeImageAlt(value: unknown, fallback: string): string {
  return typeof value === "string" && value.trim() !== "" ? value.trim().slice(0, 200) : fallback;
}

function toStored(product: RecentlyViewedProduct, viewedAt: number): StoredRecentlyViewed | undefined {
  const slug = safeSlug(product.slug);
  const name = safeName(product.name);
  const priceMinor = Number.isSafeInteger(product.priceMinor) && product.priceMinor >= 0 ? product.priceMinor : undefined;
  const currency = typeof product.currency === "string" && currencyPattern.test(product.currency) ? product.currency : undefined;
  if (!slug || !name || priceMinor === undefined || !currency) return undefined;
  return {
    slug,
    name,
    image: safeImage(product.image),
    imageAlt: safeImageAlt(product.imageAlt, name),
    priceMinor,
    currency,
    viewedAt,
  };
}

function readStored(storage: Storage | undefined): StoredRecentlyViewed[] {
  if (!storage) return [];
  try {
    const parsed: unknown = JSON.parse(storage.getItem(recentlyViewedProductsKey) ?? "null");
    if (!Array.isArray(parsed)) return [];
    const now = Date.now();
    const seen = new Set<string>();
    const items: StoredRecentlyViewed[] = [];
    for (const entry of parsed) {
      if (!entry || typeof entry !== "object") continue;
      const record = entry as Record<string, unknown>;
      const viewedAt = typeof record.viewedAt === "number" ? record.viewedAt : Number.NaN;
      if (!Number.isFinite(viewedAt) || viewedAt <= 0 || now - viewedAt > recentlyViewedProductsMaxAgeMs) continue;
      const stored = toStored({
        slug: record.slug as string,
        name: record.name as string,
        image: record.image as string,
        imageAlt: record.imageAlt as string,
        priceMinor: typeof record.priceMinor === "number" ? record.priceMinor : Number.NaN,
        currency: record.currency as string,
      }, viewedAt);
      if (!stored || seen.has(stored.slug)) continue;
      seen.add(stored.slug);
      items.push(stored);
      if (items.length >= recentlyViewedProductsLimit) break;
    }
    return items;
  } catch {
    return [];
  }
}

function toPublicProduct(item: StoredRecentlyViewed): RecentlyViewedProduct {
  return {
    slug: item.slug,
    name: item.name,
    image: item.image,
    imageAlt: item.imageAlt,
    priceMinor: item.priceMinor,
    currency: item.currency,
  };
}

export function readRecentlyViewedProducts(storage: Storage | undefined = storageOrUndefined()): RecentlyViewedProduct[] {
  return readStored(storage).map(toPublicProduct);
}

export function rememberRecentlyViewedProduct(product: RecentlyViewedProduct, storage: Storage | undefined = storageOrUndefined()): RecentlyViewedProduct[] {
  if (!storage) return [];
  const current = toStored(product, Date.now());
  if (!current) return readRecentlyViewedProducts(storage);
  const previous = readStored(storage).filter((item) => item.slug !== current.slug);
  const next = [current, ...previous].slice(0, recentlyViewedProductsLimit);
  try {
    storage.setItem(recentlyViewedProductsKey, JSON.stringify(next));
  } catch {
    return next.map(toPublicProduct);
  }
  return next.map(toPublicProduct);
}

export function clearRecentlyViewedProducts(storage: Storage | undefined = storageOrUndefined()): void {
  try {
    storage?.removeItem(recentlyViewedProductsKey);
  } catch {
    return;
  }
}
