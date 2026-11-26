import type { StorefrontCopy } from "./i18n";

export type NavigationItem = { label: string; href: string };

/** Catalog category slugs that must always resolve to a real, localized destination. */
export const CATEGORY_NAVIGATION: readonly NavigationItem[] = [
  { label: "", href: "/category/apparel" },
  { label: "", href: "/category/home-living" },
  { label: "", href: "/category/personal-care" },
  { label: "", href: "/category/food-beverage" },
];

const slugKey = (href: string) => {
  const slug = href.split("?")[0]!.split("/").filter(Boolean).pop() ?? "";
  const key = `category_${slug.replace(/[^a-z0-9]+/gi, "_").toLowerCase()}`;
  return key as keyof StorefrontCopy["navigation"];
};

/**
 * Merchant navigation labels are authored in the catalog language. The UI locale
 * owns what a shopper reads, so labels are translated per locale when a
 * translation exists and the merchant label is kept otherwise.
 */
export function localizeNavigation(items: readonly NavigationItem[], text: StorefrontCopy["navigation"]): NavigationItem[] {
  return items.map((item) => {
    const translated = text[slugKey(item.href)] as string | undefined;
    return translated ? { ...item, label: translated } : item;
  });
}

export function resolveNavigation(configured: readonly NavigationItem[] | undefined, text: StorefrontCopy["navigation"]): NavigationItem[] {
  const items = configured && configured.length > 0 ? configured : CATEGORY_NAVIGATION;
  return localizeNavigation(items, text);
}