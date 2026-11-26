/**
 * Responsive image helpers for catalog media.
 *
 * Catalog assets are frequently merchant-supplied SVG illustrations. Those are
 * resolution independent, so a `srcset` would only add noise; raster CDN URLs
 * that already carry transform query parameters can be resized by swapping the
 * `w` parameter. Local raster assets (the generated `.webp`/`.avif` renditions
 * live under `public/catalog/b2c`) are routed through the Next image optimizer,
 * which emits width-specific URLs. Backend derivatives (`variants`) are grouped
 * by format so callers can render `<picture>` with AVIF first, then WebP, and a
 * plain `<img>` fallback to the original. Anything else (data URLs, unknown
 * extensions, bare remote paths) is left untouched. Callers always receive a
 * `sizes` value so the markup stays consistent even when no candidate set can
 * be inferred.
 */

import type { ImageVariant } from "@/types";

export type ImageSource = { type: string; srcSet: string };

export type ImageSources = {
  /** `<source>` entries in preference order (AVIF, then WebP). */
  sources: ImageSource[];
  /** Original URL used by the `<img>` fallback. */
  src: string;
  sizes: string;
  /**
   * Legacy single-attrsrcset fallback for URLs without backend derivatives.
   * Present only when `sources` is empty.
   */
  srcSet?: string;
};

export const CARD_IMAGE_SIZES = "(max-width: 700px) 100vw, (max-width: 1100px) 50vw, 25vw";
export const GALLERY_IMAGE_SIZES = "(max-width: 700px) 100vw, 55vw";
export const HERO_IMAGE_SIZES = "(max-width: 900px) 100vw, 50vw";

export const CARD_IMAGE_WIDTHS = [320, 480, 640, 800];
export const GALLERY_IMAGE_WIDTHS = [480, 800, 1200, 1600];
export const HERO_IMAGE_WIDTHS = [640, 960, 1280, 1600];

const SVG_PATTERN = /\.svg(?:[?#]|$)/i;
const RASTER_PATTERN = /\.(?:avif|webp|jpe?g|png|gif)(?:[?#]|$)/i;
const OPTIMIZABLE_RASTER_PATTERN = /\.(?:avif|webp|jpe?g|png)$/i;

const NEXT_IMAGE_ENDPOINT = "/_next/image";
const NEXT_IMAGE_QUALITY = 75;

const FORMAT_TYPES: Record<string, string> = {
  avif: "image/avif",
  webp: "image/webp",
};

const FORMAT_ORDER = ["image/avif", "image/webp"];

export function isSvgUrl(url: string): boolean {
  return SVG_PATTERN.test(url.trim());
}

/**
 * True for a site-local raster file with no query string, e.g.
 * `/catalog/b2c/linen-shirt-front.webp`. These are the only URLs this helper
 * sends through `/_next/image`; CDN URLs keep their own transform contract.
 */
export function isInternalRasterAsset(url: string): boolean {
  const candidate = url.trim();
  if (!candidate.startsWith("/") || candidate.startsWith("//")) return false;
  if (candidate.includes("?") || candidate.includes("#")) return false;
  return !isSvgUrl(candidate) && OPTIMIZABLE_RASTER_PATTERN.test(candidate);
}

/** Builds a Next image optimizer URL with a correctly encoded source path. */
export function nextImageUrl(url: string, width: number, quality: number = NEXT_IMAGE_QUALITY): string {
  return `${NEXT_IMAGE_ENDPOINT}?url=${encodeURIComponent(url.trim())}&w=${width}&q=${quality}`;
}

function withWidth(url: string, width: number): string {
  const parsed = new URL(url, "https://storefront.local");
  parsed.searchParams.set("w", String(width));
  // A stale fixed height would fight the intrinsic ratio of a resized image.
  parsed.searchParams.delete("h");
  const isAbsolute = /^[a-z][a-z\d+\-.]*:/i.test(url);
  return isAbsolute ? parsed.toString() : `${parsed.pathname}${parsed.search}`;
}

function variantFormat(variant: ImageVariant): string {
  const declared = (variant.format ?? "").trim().toLowerCase();
  if (declared === "avif" || declared === "webp") return declared;
  return /\.avif(?:[?#]|$)/i.test(variant.url) ? "avif" : "webp";
}

function variantSources(variants: readonly ImageVariant[]): ImageSource[] {
  const grouped = new Map<string, { w: number; url: string }[]>();
  for (const variant of variants) {
    const type = FORMAT_TYPES[variantFormat(variant)];
    if (!type) continue;
    const list = grouped.get(type) ?? [];
    list.push({ w: variant.w, url: variant.url });
    grouped.set(type, list);
  }
  const sources: ImageSource[] = [];
  for (const type of FORMAT_ORDER) {
    const list = grouped.get(type);
    if (!list || list.length === 0) continue;
    const srcSet = list
      .slice()
      .sort((a, b) => a.w - b.w)
      .map((variant) => `${variant.url} ${variant.w}w`)
      .join(", ");
    sources.push({ type, srcSet });
  }
  return sources;
}

/**
 * Returns candidate sets for a responsive image. Explicit backend derivatives
 * win when present and are grouped into AVIF/WebP `<source>` entries; SVG
 * returns just `sizes`; local raster assets get `/_next/image` candidates;
 * raster URLs with an existing query string (CDN transforms such as
 * `?w=800&q=80`) get width candidates. Bare remote paths are left untouched.
 */
export function srcSetFor(
  url: string,
  widths: readonly number[] = CARD_IMAGE_WIDTHS,
  sizes: string = CARD_IMAGE_SIZES,
  variants?: readonly ImageVariant[],
): ImageSources {
  const safeSizes = sizes.trim() || CARD_IMAGE_SIZES;
  const candidate = url.trim();
  if (!candidate || isSvgUrl(candidate)) return { sources: [], src: candidate, sizes: safeSizes };

  const prebuilt = (variants ?? [])
    .filter((variant) => Number.isFinite(variant.w) && variant.w > 0 && typeof variant.url === "string" && variant.url.trim() !== "")
    .map((variant) => ({ w: Math.round(variant.w), url: variant.url.trim(), format: variant.format }));
  if (prebuilt.length > 0) {
    const sources = variantSources(prebuilt);
    if (sources.length > 0) return { sources, src: candidate, sizes: safeSizes };
  }

  const unique = [...new Set(widths.filter((width) => Number.isFinite(width) && width > 0).map((width) => Math.round(width)))];
  if (unique.length === 0) return { sources: [], src: candidate, sizes: safeSizes };

  if (isInternalRasterAsset(candidate)) {
    const srcSet = unique.map((width) => `${nextImageUrl(candidate, width)} ${width}w`).join(", ");
    return { sources: [], src: candidate, sizes: safeSizes, srcSet };
  }

  if (!RASTER_PATTERN.test(candidate)) return { sources: [], src: candidate, sizes: safeSizes };

  let parsed: URL;
  try {
    parsed = new URL(candidate, "https://storefront.local");
  } catch {
    return { sources: [], src: candidate, sizes: safeSizes };
  }
  // A bare remote path has no documented variant contract; do not invent URLs.
  if (!parsed.search) return { sources: [], src: candidate, sizes: safeSizes };

  return {
    sources: [],
    src: candidate,
    sizes: safeSizes,
    srcSet: unique.map((width) => `${withWidth(candidate, width)} ${width}w`).join(", "),
  };
}
