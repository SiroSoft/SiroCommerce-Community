import { describe, expect, it } from "vitest";
import { derivativeSummary, derivativesOf, previewUrl } from "@/lib/media-derivatives";

describe("admin media derivatives", () => {
  it("lists formats and sizes from variants_json", () => {
    const asset = {
      url: "/storage/original.png",
      variants_json: JSON.stringify([
        { w: 320, url: "/storage/a_320.webp", format: "webp" },
        { w: 640, url: "/storage/a_640.webp", format: "webp" },
        { w: 320, url: "/storage/a_320.avif", format: "avif" },
        { w: 640, url: "/storage/a_640.avif", format: "avif" },
      ]),
    };
    const derivatives = derivativesOf(asset);
    expect(derivatives).toHaveLength(4);
    expect(derivativeSummary(derivatives)).toBe("WebP 320/640 · AVIF 320/640");
    expect(previewUrl(asset, asset.url)).toBe("/storage/a_320.webp");
  });

  it("tolerates malformed variants and falls back to the original URL", () => {
    const asset = { url: "/storage/original.png", variants: [{ w: 0, url: "" }, null, "bad"] };
    expect(derivativesOf(asset)).toEqual([]);
    expect(derivativeSummary([])).toBe("");
    expect(previewUrl(asset, asset.url)).toBe("/storage/original.png");
  });
});
