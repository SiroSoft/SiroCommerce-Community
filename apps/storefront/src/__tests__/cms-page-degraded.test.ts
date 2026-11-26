import { describe, expect, it } from "vitest";
import { parseCmsPage, readCmsCta, readCmsHero, readCmsImage } from "@/lib/cms";

describe("degraded CMS pages", () => {
  it("renders a page without blocks and never executes corrupt block JSON", () => {
    const page = parseCmsPage({
      slug: "about",
      title: "About",
      body: "<p>Body copy</p>",
      status: "published",
      seo: {},
    });
    expect(page?.blocks).toEqual([]);
    expect(page?.body).toBe("<p>Body copy</p>");

    expect(readCmsHero({ type: "hero", content: "" }, "Fallback")).toEqual({ title: "Fallback" });

    const corrupt = { type: "hero", content: '{"title": "<script>alert(1)</script>"' };
    expect(() => readCmsHero(corrupt, "Fallback")).not.toThrow();
    const hero = readCmsHero(corrupt, "Fallback");
    expect(hero.title).not.toContain("<script>");

    expect(readCmsImage({ type: "image", content: '<img src="javascript:alert(1)">' })).toBeNull();
    expect(readCmsCta({ type: "cta", content: '<a href="javascript:alert(1)">Go</a>' })).toEqual({ label: "Go" });
  });
});
