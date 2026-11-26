import { describe, expect, it } from "vitest";
import { categoryHref } from "@/lib/category-href";
import { resolveCategoryHref } from "@/homepage/sections";

describe("categoryHref", () => {
  it("routes canonical catalog categories to their category page", () => {
    expect(categoryHref("apparel")).toBe("/category/apparel");
    expect(categoryHref("home-living")).toBe("/category/home-living");
    expect(categoryHref("personal-care")).toBe("/category/personal-care");
    expect(categoryHref("food-beverage")).toBe("/category/food-beverage");
  });

  it("maps legacy aliases to the canonical route", () => {
    expect(categoryHref("home")).toBe("/category/home-living");
    expect(categoryHref("accessories")).toBe("/category/personal-care");
  });

  it("falls back to a search facet for unknown categories and encodes the slug", () => {
    expect(categoryHref("beauty")).toBe("/search?facet_category=beauty");
    expect(categoryHref("home decor")).toBe("/search?facet_category=home%20decor");
    expect(categoryHref("a&b")).toBe("/search?facet_category=a%26b");
  });

  it("keeps the homepage resolveCategoryHref export as an alias", () => {
    expect(resolveCategoryHref).toBe(categoryHref);
  });
});
