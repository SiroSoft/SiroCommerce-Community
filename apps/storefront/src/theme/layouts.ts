import type { FooterLayout, HeaderLayout, ProductCardVariant, ProductDetailVariant, PurchaseVariant } from "./types";

/**
 * Single source of layout options (FE_THEME_LAYOUT_SPEC RULE-2).
 * Every layer (backend validator, sanitize, admin builder, tests) must agree
 * with these lists. Pre-existing unprefixed keys are grandfathered; all NEW
 * keys must be surface-prefixed per RULE-1.
 */
export const HEADER_LAYOUTS = ["standard", "minimal", "centered", "split"] as const;
export const FOOTER_LAYOUTS = ["standard", "minimal", "mega"] as const;
export const LIST_LAYOUTS = ["list-editorial", "list-grid-wide"] as const;
export const PAGE_LAYOUTS = ["editorial", "focused", "page-gallery-left", "split-buybox"] as const;
export const CARD_VARIANTS = ["editorial", "minimal", "spotlight"] as const;
export const DETAIL_VARIANTS = ["editorial", "focused", "page-gallery-left", "split-buybox"] as const;
export const PURCHASE_VARIANTS = ["standard", "compact"] as const;

export type HeaderLayoutKey = (typeof HEADER_LAYOUTS)[number];
export type FooterLayoutKey = (typeof FOOTER_LAYOUTS)[number];
export type ListLayoutKey = (typeof LIST_LAYOUTS)[number];
export type PageLayoutKey = (typeof PAGE_LAYOUTS)[number];

export function asHeaderLayout(value: unknown): HeaderLayout {
  return (HEADER_LAYOUTS as readonly string[]).includes(typeof value === "string" ? value : "") ? (value as HeaderLayout) : "standard";
}
export function asFooterLayout(value: unknown): FooterLayout {
  return (FOOTER_LAYOUTS as readonly string[]).includes(typeof value === "string" ? value : "") ? (value as FooterLayout) : "standard";
}
export function asListLayout(value: unknown): ListLayoutKey {
  return (LIST_LAYOUTS as readonly string[]).includes(typeof value === "string" ? value : "") ? (value as ListLayoutKey) : "list-editorial";
}
export function asCardVariant(value: unknown): ProductCardVariant {
  return value === "minimal" || value === "spotlight" ? value : "editorial";
}
export function asDetailVariant(value: unknown): ProductDetailVariant {
  return value === "focused" || value === "page-gallery-left" || value === "split-buybox" ? value : "editorial";
}
export function asPurchaseVariant(value: unknown): PurchaseVariant {
  return value === "compact" ? "compact" : "standard";
}
