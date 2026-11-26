import { describe, expect, it } from "vitest";
import { derivativeSummary, derivativesOf, previewUrl } from "@/lib/media-derivatives";

describe("media derivative edge cases", () => {
  it("ignores non-object sources and malformed JSON variant strings", () => {
    expect(derivativesOf(null as never)).toEqual([]);
    expect(derivativesOf("url" as never)).toEqual([]);
    expect(derivativesOf({ variants_json: "{not-json" })).toEqual([]);
    expect(derivativesOf({ variants_json: "null" })).toEqual([]);
  });

  it("validates width, url and defaults the format", () => {
    const source = {
      variants: [
        { w: "640.4", url: "  /a.webp  " },
        { w: 0, url: "/zero.webp" },
        { w: 320, url: "   " },
        { w: 480, url: "/b" , format: "   " },
        { w: 480, url: "/c.avif", format: " AVIF " },
        null,
        "bad",
      ],
    };
    const derivatives = derivativesOf(source);
    expect(derivatives).toEqual([
      { w: 480, url: "/c.avif", format: "avif" },
      { w: 480, url: "/b", format: "webp" },
      { w: 640, url: "/a.webp", format: "webp" },
    ]);
  });

  it("summarizes formats in preferred order and dedupes widths", () => {
    expect(derivativeSummary([
      { w: 640, url: "/a.jpg", format: "jpeg" },
      { w: 320, url: "/b.webp", format: "webp" },
      { w: 640, url: "/c.webp", format: "webp" },
      { w: 320, url: "/d.avif", format: "avif" },
      { w: 320, url: "/e.avif", format: "avif" },
    ])).toBe("WebP 320/640 · AVIF 320 · JPEG 640");
  });

  it("chooses preview URLs through the fallback chain", () => {
    const smallNonWebp = { variants: [{ w: 320, url: "/320.jpg", format: "jpeg" }] };
    expect(previewUrl(smallNonWebp, "/original.jpg")).toBe("/320.jpg");

    const webpOnly = { variants: [{ w: 640, url: "/640.webp", format: "webp" }] };
    expect(previewUrl(webpOnly, "/original.jpg")).toBe("/640.webp");

    const any = { variants: [{ w: 640, url: "/640.jpg", format: "jpeg" }] };
    expect(previewUrl(any, "/original.jpg")).toBe("/640.jpg");

    expect(previewUrl({}, "/original.jpg")).toBe("/original.jpg");
    expect(previewUrl({}, null)).toBe("");
    expect(previewUrl({}, undefined)).toBe("");
  });
});
