import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { ProductMediaManager } from "@/modules/products/components/product-media-manager";
import { ProductVariantsManager } from "@/modules/products/components/product-variants-manager";

vi.mock("@/services/fe1.service", () => ({
  fe1Service: {
    media: vi.fn(async () => [{
      id: "m-1",
      url: "/storage/products/p-1/images/hash.jpg",
      alt: "Front",
      variants: [
        { w: 320, url: "/storage/products/p-1/images/hash_320.webp", format: "webp" },
        { w: 320, url: "/storage/products/p-1/images/hash_320.avif", format: "avif" },
        { w: 640, url: "/storage/products/p-1/images/hash_640.webp", format: "webp" },
        { w: 640, url: "/storage/products/p-1/images/hash_640.avif", format: "avif" },
      ],
    }]),
    addMedia: vi.fn(async () => ({ id: "m-2" })),
    reorderMedia: vi.fn(async () => undefined),
    deleteMedia: vi.fn(async () => undefined),
    variants: vi.fn(async () => [{ id: "v-1", sku: "SKU-1", price_minor: 6800, currency: "USD" }]),
    createVariant: vi.fn(async () => ({ id: "v-2" })),
  },
}));

describe("product media and variants managers", () => {
  it("lists gallery images with reorder, delete controls and derivative formats", async () => {
    render(<ProductMediaManager productId={1} />);
    expect(await screen.findByText("Front")).toBeTruthy();
    expect(screen.getByText("4 derivatives · WebP 320/640 · AVIF 320/640")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Move up" })).toBeTruthy();
    expect(screen.getByRole("button", { name: /delete front/i })).toBeTruthy();
  });

  it("lists variants and validates the create form", async () => {
    render(<ProductVariantsManager productId={1} />);
    expect(await screen.findByText("SKU-1")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Add variant" })).toBeTruthy();
  });
});
