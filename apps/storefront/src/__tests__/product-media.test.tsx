import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { ProductCard } from "@/components/ProductCard";
import { ProductGallery } from "@/components/ProductGallery";
import { FlashOfferStrip } from "@/homepage/sections";
import { products } from "@/services/catalog";

afterEach(() => {
  document.body.innerHTML = "";
});

const frames = [
  { url: "/one.svg", alt: "One" },
  { url: "/two.svg", alt: "Two" },
  { url: "/three.svg", alt: "Three" },
];

describe("product media presentation", () => {
  it("stacks a decorative second image behind the primary card image", () => {
    const product = {
      ...products[0]!,
      images: [
        { url: "/one.svg", alt: "Primary" },
        { url: "/two.svg", alt: "Alternate" },
      ],
    };
    const { container } = render(<ProductCard product={product} />);
    const images = [...container.querySelectorAll("img")];
    expect(images).toHaveLength(2);
    expect(images[0]).toHaveAttribute("alt", product.imageAlt);
    expect(images[0]).toHaveAttribute("loading", "lazy");
    expect(images[0]).toHaveAttribute("decoding", "async");
    expect(images[0]).toHaveAttribute("width", "800");
    expect(images[1]).toHaveAttribute("aria-hidden", "true");
    expect(images[1]).toHaveAttribute("src", "/two.svg");
  });

  it("keeps a single image when the catalog has no alternate frame", () => {
    const { container } = render(<ProductCard product={products[0]!} />);
    expect(container.querySelectorAll("img")).toHaveLength(1);
  });

  it("renders card picture sources in avif then webp order with an img fallback", () => {
    const product = {
      ...products[0]!,
      image: "/storage/card.png",
      images: [{
        url: "/storage/card.png",
        alt: "Card",
        placeholder: "data:image/webp;base64,abc",
        variants: [
          { w: 320, url: "/storage/card_320.webp", format: "webp" },
          { w: 320, url: "/storage/card_320.avif", format: "avif" },
          { w: 640, url: "/storage/card_640.webp", format: "webp" },
          { w: 640, url: "/storage/card_640.avif", format: "avif" },
        ],
      }],
    };
    const { container } = render(<ProductCard product={product} />);
    const picture = container.querySelector("picture");
    expect(picture).not.toBeNull();
    const sources = [...picture!.querySelectorAll("source")];
    expect(sources.map((source) => source.getAttribute("type"))).toEqual(["image/avif", "image/webp"]);
    expect(sources[0]!.getAttribute("srcset")).toBe("/storage/card_320.avif 320w, /storage/card_640.avif 640w");
    expect(sources[1]!.getAttribute("srcset")).toBe("/storage/card_320.webp 320w, /storage/card_640.webp 640w");
    const fallback = picture!.querySelector("img");
    expect(fallback).toHaveAttribute("src", "/storage/card.png");
    expect(fallback).not.toHaveAttribute("srcset");
    expect(fallback!.getAttribute("style")).toContain("data:image/webp;base64,abc");
  });

  it("renders gallery picture sources in avif then webp order", () => {
    const { container } = render(<ProductGallery
      frames={[{ url: "/storage/gallery.png", alt: "Gallery image", variants: [
        { w: 480, url: "/storage/gallery_480.webp", format: "webp" },
        { w: 480, url: "/storage/gallery_480.avif", format: "avif" },
      ] }]}
      name="Piece"
      label="Gallery"
      counter="{index}/{total}"
    />);
    const picture = container.querySelector(".detail-image picture");
    expect(picture).not.toBeNull();
    expect([...picture!.querySelectorAll("source")].map((source) => source.getAttribute("type"))).toEqual(["image/avif", "image/webp"]);
    expect(picture!.querySelector("img")).toHaveAttribute("src", "/storage/gallery.png");
  });

  it("keeps the old img markup when no variants exist", () => {
    const { container } = render(<ProductGallery frames={frames} name="Piece" label="Gallery" counter="{index}/{total}" />);
    expect(container.querySelector("picture")).toBeNull();
    expect(container.querySelectorAll("img").length).toBeGreaterThan(0);
  });


  it("selects thumbnails with aria-pressed and opens the zoom dialog", () => {
    render(<ProductGallery frames={frames} name="Piece" label="Gallery" counter="{index}/{total}" />);
    const first = screen.getByRole("button", { name: "Gallery 1" });
    const second = screen.getByRole("button", { name: "Gallery 2" });
    expect(first).toHaveAttribute("aria-pressed", "true");
    fireEvent.click(second);
    expect(second).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByText("Gallery · 2/3")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /open image 2 larger/i }));
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByRole("button", { name: "Close image viewer" })).toHaveFocus();

    fireEvent.keyDown(document, { key: "ArrowRight" });
    expect(screen.getByRole("dialog")).toHaveAccessibleName(/3\/3/);
    fireEvent.keyDown(document, { key: "Escape" });
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("toggles zoom on the overlay image without a dependency", () => {
    const { container } = render(<ProductGallery frames={frames} name="Piece" label="Gallery" counter="{index}/{total}" />);
    fireEvent.click(screen.getByRole("button", { name: /open image 1 larger/i }));
    const frame = container.querySelector(".sf-media-zoom-frame");
    expect(frame).not.toBeNull();
    fireEvent.doubleClick(frame!);
    expect(frame).toHaveClass("is-zoomed");
    fireEvent.doubleClick(frame!);
    expect(frame).not.toHaveClass("is-zoomed");
  });

  it("hides thumbnails and disables zoom for a single image", () => {
    const { container } = render(<ProductGallery frames={[{ url: "/only.svg", alt: "Only" }]} name="Piece" label="Gallery" counter="{index}/{total}" />);
    expect(container.querySelector(".sf-media-thumbs")).toBeNull();
    expect(screen.queryByRole("button", { name: /open image/i })).toBeNull();
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("renders the homepage offer strip only when catalog flash deals exist", () => {
    const deal = {
      sale_id: "s1",
      sale_name: "Gio vang",
      variant_id: products[0]!.id,
      sale_price_minor: 1500,
      starts_at: "2026-01-01T00:00:00Z",
      ends_at: "2099-01-01T00:00:00Z",
      cap_qty: 100,
      sold_qty: 10,
      remaining: 90,
      priority: 0,
    };
    const { container, rerender } = render(<FlashOfferStrip products={[{ ...products[0]!, flashSales: [deal] }]} locale="en" />);
    expect(container.querySelector(".sf-media-flash-strip")).not.toBeNull();
    expect(container.textContent).toMatch(/Gio vang/);
    expect(container.textContent).toMatch(/only 90 left/);

    rerender(<FlashOfferStrip products={[products[0]!]} locale="en" />);
    expect(container.querySelector(".sf-media-flash-strip")).toBeNull();
  });
});
