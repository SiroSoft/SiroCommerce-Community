import { describe, expect, it } from "vitest";
import { defaultSections, resolveHomepageSections } from "@/homepage/resolve-homepage";
import { readBannerItems, readFaqEntries } from "@/homepage/section-data";

describe("homepage section registry", () => {
  it("orders known sections and skips unknown types", () => {
    const sections = resolveHomepageSections([{ id: "z", type: "service_proof", order: 20 }, { id: "a", type: "campaign_hero", order: 20 }, { id: "bad", type: "script", order: 1 }]);
    expect(sections.map((section) => section.id)).toEqual(["a", "z"]);
  });
  it("provides the current general composition", () => {
    expect(resolveHomepageSections(undefined)).toEqual(defaultSections);
  });
  it("accepts the banner, usp and faq section types with nested item props", () => {
    const sections = resolveHomepageSections([
      { id: "banners", type: "banner_grid", order: 1, props: { banners: [{ title: "Linen edit", href: "/category/home", image: "/banner.svg", cta: { label: "Shop linen", href: "/category/home" } }] } },
      { id: "usp", type: "usp_strip", order: 2, props: { items: [{ title: "Fast shipping", copy: "Ships in 48h", icon: "truck" }] } },
      { id: "faq", type: "faq", order: 3, props: { items: [{ question: "Where?", answer: "Everywhere." }] } },
    ]);
    expect(sections.map((section) => section.type)).toEqual(["banner_grid", "usp_strip", "faq"]);
    expect(sections[0]?.props.banners).toHaveLength(1);
    expect(readBannerItems(sections[0]?.props)).toEqual([{ title: "Linen edit", subtitle: undefined, cta: "Shop linen", ctaHref: "/category/home", href: "/category/home", image: "/banner.svg", imageAlt: undefined }]);
    expect(sections[2]?.props.items).toEqual([{ question: "Where?", answer: "Everywhere." }]);
  });
  it("drops unknown props and keeps primitive overrides", () => {
    const [section] = resolveHomepageSections([{ id: "faq", type: "faq", order: 1, props: { title: "Help", "bad-key": "alert(1)", items: [{ question: "Q", answer: "A" }, { question: "Q2" }], nested: { bad: true } } }]);
    expect(section?.props.title).toBe("Help");
    expect(section?.props["bad-key"]).toBeUndefined();
    expect(section?.props.nested).toBeUndefined();
    expect(readFaqEntries(section?.props)).toEqual([{ question: "Q", answer: "A" }]);
  });
});
