import { describe, expect, it } from "vitest";
import {
  CARD_SHAPE_OPTIONS,
  CARD_VARIANT_OPTIONS,
  DENSITY_OPTIONS,
  FOOTER_LAYOUT_OPTIONS,
  HEADER_LAYOUT_OPTIONS,
  HOMEPAGE_SECTION_TYPES,
  LIST_LAYOUT_OPTIONS,
  PAGE_LAYOUT_OPTIONS,
  PURCHASE_VARIANT_OPTIONS,
  RADIUS_OPTIONS,
} from "@/services/storefront-theme.service";

/** FE_THEME_LAYOUT_SPEC RULE-2: admin options mirror theme/layouts.ts. */
describe("theme layout options", () => {
  it("offers every batch-1 layout", () => {
    expect(HEADER_LAYOUT_OPTIONS).toEqual(["standard", "minimal", "centered", "split"]);
    expect(FOOTER_LAYOUT_OPTIONS).toEqual(["standard", "minimal", "mega"]);
    expect(LIST_LAYOUT_OPTIONS).toEqual(["list-editorial", "list-grid-wide"]);
    expect(PAGE_LAYOUT_OPTIONS).toEqual(["editorial", "focused", "page-gallery-left"]);
    expect(CARD_VARIANT_OPTIONS).toEqual(["editorial", "minimal"]);
    expect(PURCHASE_VARIANT_OPTIONS).toEqual(["standard", "compact"]);
    expect(DENSITY_OPTIONS).toEqual(["compact", "comfortable", "spacious"]);
    expect(RADIUS_OPTIONS).toEqual(["sharp", "soft", "round"]);
    expect(CARD_SHAPE_OPTIONS).toEqual(["flat", "bordered", "elevated"]);
  });

  it("offers the multi-vertical section types", () => {
    expect(HOMEPAGE_SECTION_TYPES).toEqual(expect.arrayContaining(["banner_grid", "usp_strip", "faq"]));
  });
});
