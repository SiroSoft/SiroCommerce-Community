import { describe, expect, it } from "vitest";
import nextConfig from "../../next.config";
import {
  CARD_IMAGE_WIDTHS,
  GALLERY_IMAGE_WIDTHS,
  isInternalRasterAsset,
  isSvgUrl,
  nextImageUrl,
  srcSetFor,
} from "@/lib/product-image";

describe("product image srcset contract", () => {
  it("groups backend derivatives by format and prefers avif before webp", () => {
    const result = srcSetFor("/storage/products/p-1/hash.png", [320, 640], "50vw", [
      { w: 960, url: "/storage/products/p-1/hash_960.webp", format: "webp" },
      { w: 640, url: "/storage/products/p-1/hash_640.avif", format: "avif" },
      { w: 320, url: "/storage/products/p-1/hash_320.webp", format: "webp" },
      { w: 320, url: "/storage/products/p-1/hash_320.avif", format: "avif" },
      { w: 640, url: "/storage/products/p-1/hash_640.webp", format: "webp" },
    ]);
    expect(result.sizes).toBe("50vw");
    expect(result.src).toBe("/storage/products/p-1/hash.png");
    expect(result.srcSet).toBeUndefined();
    expect(result.sources).toEqual([
      {
        type: "image/avif",
        srcSet: "/storage/products/p-1/hash_320.avif 320w, /storage/products/p-1/hash_640.avif 640w",
      },
      {
        type: "image/webp",
        srcSet:
          "/storage/products/p-1/hash_320.webp 320w, /storage/products/p-1/hash_640.webp 640w, /storage/products/p-1/hash_960.webp 960w",
      },
    ]);
  });

  it("falls back to the existing contract when variants are missing or invalid", () => {
    const result = srcSetFor("/catalog/b2c/frame.webp", [320, 640], "50vw", []);
    expect(result.sources).toEqual([]);
    expect(result.src).toBe("/catalog/b2c/frame.webp");
    expect(result.srcSet).toBe(
      "/_next/image?url=%2Fcatalog%2Fb2c%2Fframe.webp&w=320&q=75 320w, /_next/image?url=%2Fcatalog%2Fb2c%2Fframe.webp&w=640&q=75 640w",
    );
    expect(srcSetFor("/catalog/b2c/frame.webp", [320], "50vw", [{ w: 0, url: "" }]).srcSet).toBe(
      "/_next/image?url=%2Fcatalog%2Fb2c%2Fframe.webp&w=320&q=75 320w",
    );
  });

  it("keeps SVG resolution independent even when variants are supplied", () => {
    const result = srcSetFor("/catalog/b2c/frame.svg", [320, 640], "50vw", [{ w: 320, url: "/storage/frame_320.webp" }]);
    expect(result.sources).toEqual([]);
    expect(result.srcSet).toBeUndefined();
    expect(result.sizes).toBe("50vw");
  });

  it("infers the format of legacy variants that predate the format field", () => {
    const result = srcSetFor("/storage/a.png", [320], "50vw", [
      { w: 320, url: "/storage/a_320.webp" },
      { w: 320, url: "/storage/a_320.avif" },
    ]);
    expect(result.sources.map((source) => source.type)).toEqual(["image/avif", "image/webp"]);
  });

  it("never fabricates variants for SVG catalog art but still returns sizes", () => {
    const result = srcSetFor("/catalog/b2c/linen-mandarin-shirt-front.svg", [320, 800], "(max-width: 700px) 100vw, 50vw");
    expect(result.srcSet).toBeUndefined();
    expect(result.sizes).toBe("(max-width: 700px) 100vw, 50vw");
    expect(isSvgUrl("/only.svg?rev=2")).toBe(true);
  });

  it("routes local raster catalog assets through the Next image optimizer", () => {
    const result = srcSetFor("/catalog/b2c/linen-mandarin-shirt-front.webp", [320, 640], "50vw");
    expect(result.sizes).toBe("50vw");
    expect(result.srcSet).toBe(
      "/_next/image?url=%2Fcatalog%2Fb2c%2Flinen-mandarin-shirt-front.webp&w=320&q=75 320w, " +
        "/_next/image?url=%2Fcatalog%2Fb2c%2Flinen-mandarin-shirt-front.webp&w=640&q=75 640w",
    );
  });

  it("encodes local paths with spaces and uppercase characters correctly", () => {
    expect(nextImageUrl("/catalog/b2c/Linen Shirt Front.webp", 320)).toBe(
      "/_next/image?url=%2Fcatalog%2Fb2c%2FLinen%20Shirt%20Front.webp&w=320&q=75",
    );
    expect(isInternalRasterAsset("/catalog/b2c/Linen Shirt Front.webp")).toBe(true);
    expect(isInternalRasterAsset("/catalog/b2c/Linen Shirt Front.svg")).toBe(false);
  });

  it("supports avif, jpg and png local assets but not gif", () => {
    expect(isInternalRasterAsset("/catalog/b2c/frame.avif")).toBe(true);
    expect(isInternalRasterAsset("/catalog/b2c/frame.jpg")).toBe(true);
    expect(isInternalRasterAsset("/catalog/b2c/frame.jpeg")).toBe(true);
    expect(isInternalRasterAsset("/catalog/b2c/frame.png")).toBe(true);
    expect(isInternalRasterAsset("/catalog/b2c/frame.gif")).toBe(false);
    expect(isInternalRasterAsset("/catalog/b2c/frame.webp?rev=2")).toBe(false);
  });

  it("builds width candidates for raster CDN URLs that already carry query transforms", () => {
    const result = srcSetFor("https://cdn.example.test/media/shirt.jpg?w=800&q=80&h=1000", [320, 800], "50vw");
    expect(result.sizes).toBe("50vw");
    expect(result.srcSet).toBe("https://cdn.example.test/media/shirt.jpg?w=320&q=80 320w, https://cdn.example.test/media/shirt.jpg?w=800&q=80 800w");
  });

  it("keeps relative CDN paths relative while swapping the width", () => {
    const result = srcSetFor("/media/shirt.webp?w=800", [400, 900], "100vw");
    expect(result.srcSet).toBe("/media/shirt.webp?w=400 400w, /media/shirt.webp?w=900 900w");
  });

  it("leaves bare remote raster paths alone because no variant contract exists", () => {
    expect(srcSetFor("https://cdn.example.test/images/shirt.jpg", [320, 800]).srcSet).toBeUndefined();
    expect(srcSetFor("https://cdn.example.test/images/shirt.jpg", [320, 800]).sizes).toBeDefined();
  });

  it("ignores non-image and malformed candidates", () => {
    expect(srcSetFor("data:image/png;base64,abc", [320]).srcSet).toBeUndefined();
    expect(srcSetFor("https://cdn.example.test/media/photo.jpg?w=800", [0, -1, Number.NaN]).srcSet).toBeUndefined();
  });

  it("allow-lists every emitted optimizer width in the Next image config", () => {
    const allocated = [
      ...(nextConfig.images?.deviceSizes ?? []),
      ...(nextConfig.images?.imageSizes ?? []),
    ];
    for (const width of new Set([...CARD_IMAGE_WIDTHS, ...GALLERY_IMAGE_WIDTHS, 96, 192])) {
      expect(allocated).toContain(width);
    }
  });
});
