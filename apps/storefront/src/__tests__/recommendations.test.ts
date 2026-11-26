import { describe, expect, it } from "vitest";
import { products } from "@/services/catalog";
import { derivePreferenceSignals, resolveFeaturedRecommendations, resolveRecommendations } from "@/services/recommendations";
import { clearRecentlyViewed, isSafeProductId, readRecentlyViewed, rememberRecentlyViewed, recentlyViewedKey, recentlyViewedMaxAgeMs } from "@/services/recently-viewed";

describe("storefront recommendations", () => {
  it("uses deterministic catalog signals and never recommends the current product", () => {
    const result = resolveRecommendations(products[0]!, products);
    expect(result.source).toBe("catalog-fallback");
    expect(result.products.map((product) => product.slug)).toEqual(["cloud-knit-cardigan", "ceramic-studio-mug", "oak-desk-tray", "everyday-canvas-tote"]);
    expect(result.products).not.toContain(products[0]);
  });

  it("ranks featured fallback deterministically", () => {
    expect(resolveFeaturedRecommendations(products, 2).products.map((product) => product.id)).toEqual(["p-2", "p-1"]);
  });

  it("bounds storage and rejects unsafe product IDs", () => {
    const values = new Map<string, string>();
    const storage = { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => values.set(key, value) } as unknown as Storage;
    expect(isSafeProductId("p-1")).toBe(true);
    expect(isSafeProductId("product/with-query")).toBe(false);
    for (let index = 0; index < 10; index += 1) rememberRecentlyViewed(`p-${index}`, storage);
    expect(readRecentlyViewed(storage)).toHaveLength(8);
    expect(readRecentlyViewed(storage)[0]).toBe("p-9");
    values.set(recentlyViewedKey, JSON.stringify(["p-1", "p-1", "not safe/id"]));
    expect(readRecentlyViewed(storage)).toEqual(["p-1"]);
  });

  it("drops stale history and unavailable catalog entries at render time", () => {
    const values = new Map<string, string>([[recentlyViewedKey, JSON.stringify([
      { id: "p-1", viewedAt: Date.now() - recentlyViewedMaxAgeMs - 1 },
      { id: "p-6", viewedAt: Date.now() },
    ])]]);
    const storage = { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => values.set(key, value), removeItem: (key: string) => values.delete(key) } as unknown as Storage;
    expect(readRecentlyViewed(storage)).toEqual(["p-6"]);
    expect(resolveFeaturedRecommendations(products).products.map((product) => product.id)).not.toContain("p-6");
    clearRecentlyViewed(storage);
    expect(readRecentlyViewed(storage)).toEqual([]);
  });

  it("derives only bounded non-sensitive preference signals", () => {
    const signals = derivePreferenceSignals(["p-1", "p-2", "p-3", "p-4", "p-5", "p-6"], products);
    expect(signals.categories).toHaveLength(3);
    expect(signals.tags.length).toBeLessThanOrEqual(6);
    expect(signals).not.toHaveProperty("email");
    expect(resolveRecommendations(products[0]!, products, 4, signals).products).toHaveLength(4);
  });
});
