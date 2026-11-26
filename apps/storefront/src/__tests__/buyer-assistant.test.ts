import { describe, expect, it } from "vitest";
import { products } from "@/services/catalog";
import { analyzeBuyerQuestion, BUYER_ASSISTANT_MAX_INPUT, suggestProducts } from "@/lib/buyer-assistant";

describe("buyer assistant suggestions", () => {
  it("matches catalog text deterministically", () => {
    expect(suggestProducts("linen", products).map((product) => product.slug)).toEqual(["linen-camp-shirt"]);
    expect(suggestProducts("gift", products).map((product) => product.slug)).toEqual(["brass-key-ring"]);
  });
  it("returns no matches without catalog evidence", () => {
    expect(suggestProducts("wireless headphones", products)).toEqual([]);
    expect(suggestProducts("", products)).toEqual([]);
  });
  it("bounds input and refuses transaction or policy questions", () => {
    expect(analyzeBuyerQuestion("delivery for linen", products).outcome).toBe("unsupported");
    expect(analyzeBuyerQuestion("x".repeat(BUYER_ASSISTANT_MAX_INPUT + 1), products).outcome).toBe("too_long");
    expect(analyzeBuyerQuestion("   ", products).outcome).toBe("empty");
  });
  it("returns current catalog facts only through matched products", () => {
    const result = analyzeBuyerQuestion("brass gift", products);
    expect(result.suggestions[0]).toMatchObject({ slug: "brass-key-ring", price: 2400, status: "out_of_stock" });
  });
});
