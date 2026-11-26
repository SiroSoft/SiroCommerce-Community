import { describe, expect, it } from "vitest";
import { verticalPresets } from "@/verticals/presets";
import { resolveVertical } from "@/verticals/resolve-vertical";
import { resolveProductPresentation } from "@/presentation/resolve-product-presentation";
import { products } from "@/services/catalog";

describe("vertical presets", () => {
  it("resolves every contracted key and fails closed", () => {
    expect(Object.keys(verticalPresets)).toEqual(["general", "apparel", "home", "food_beverage", "digital", "marketplace", "b2b"]);
    expect(resolveVertical("not-a-vertical")).toBe(verticalPresets.general);
  });
  it("only changes presentation decisions", () => {
    const product = products[0]!;
    const presentation = resolveProductPresentation(product, resolveVertical("apparel"));
    expect(presentation.imageRatio).toBe("portrait");
    expect(product.price).toBe(6800);
    expect(resolveProductPresentation({ ...product, capability: "unavailable" }, resolveVertical("general")).showVendor).toBe(false);
  });
});
