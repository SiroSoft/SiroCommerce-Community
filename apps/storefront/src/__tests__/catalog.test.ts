import { describe, expect, it } from "vitest";
import { fixtureCatalog } from "@/services/catalog";

describe("fixture catalog boundary", () => {
  it("filters by category and search without transport concerns", async () => {
    const apparel = await fixtureCatalog.listProducts({ category: "apparel" });
    expect(apparel.every((product) => product.category === "apparel")).toBe(true);
    const search = await fixtureCatalog.listProducts({ search: "ceramic" });
    expect(search.map((product) => product.slug)).toEqual(["ceramic-studio-mug"]);
  });

  it("sorts prices deterministically and exposes out-of-stock state", async () => {
    const result = await fixtureCatalog.listProducts({ sort: "price-desc" });
    expect(result[0]?.price).toBe(9600);
    expect((await fixtureCatalog.getProduct("brass-key-ring"))?.status).toBe("out_of_stock");
  });

  it("uses the same description search and bounded pagination contract as the API adapter", async () => {
    const page = await fixtureCatalog.listProducts({ search: "slow mornings", page: 1, perPage: 1 });
    expect(page.map((product) => product.slug)).toEqual(["linen-camp-shirt"]);
    expect((await fixtureCatalog.listProducts({ page: 2, perPage: 2 })).map((product) => product.slug)).toEqual(["everyday-canvas-tote", "cloud-knit-cardigan"]);
  });

  it("exposes facets, totals, and autocomplete for discovery surfaces", async () => {
    const result = await fixtureCatalog.listProductsResult?.({ facets: { tag: ["handmade"] }, perPage: 10 });
    expect(result?.total).toBe(1);
    expect(result?.products[0]?.slug).toBe("ceramic-studio-mug");
    expect(result?.facets.find((facet) => facet.name === "category")?.values.length).toBeGreaterThan(0);
    await expect(fixtureCatalog.autocomplete?.("card")).resolves.toMatchObject([{ slug: "cloud-knit-cardigan" }]);
  });

  it("intersects facet groups while allowing multiple values within one facet", async () => {
    const result = await fixtureCatalog.listProductsResult?.({ facets: { category: ["apparel"], tag: ["knit", "linen"] }, perPage: 10 });
    expect(result?.products.map((product) => product.slug)).toEqual(["linen-camp-shirt", "cloud-knit-cardigan"]);
  });

  it("bounds autocomplete input and output and keeps unavailable products honest", async () => {
    const suggestions = await fixtureCatalog.autocomplete?.(`${"x".repeat(200)}${"shirt"}`);
    expect(suggestions).toHaveLength(0);
    expect((await fixtureCatalog.getProduct("brass-key-ring"))?.status).toBe("out_of_stock");
  });
});
