const categoryRoutes = new Set(["apparel", "home-living", "personal-care", "food-beverage"]);

/** Map a catalog category slug to the storefront route that actually resolves it. */
export function categoryHref(slug: string): string {
  const canonical = slug === "home" ? "home-living" : slug === "accessories" ? "personal-care" : slug;
  return categoryRoutes.has(canonical) ? `/category/${canonical}` : `/search?facet_category=${encodeURIComponent(slug)}`;
}
