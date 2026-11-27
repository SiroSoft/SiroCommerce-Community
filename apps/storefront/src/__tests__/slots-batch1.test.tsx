import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ProductCard } from "@/components/ProductCard";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import { LocaleProvider } from "@/lib/i18n-client";
import { products } from "@/services/catalog";
import {
  SLOT_NAMES,
  Slot,
  SlotProvider,
  createSlotRegistry,
  isSlotName,
} from "@/slots";
import type { SlotComponent, SlotProps } from "@/slots";
import type { Product } from "@/types";

vi.mock("next/link", () => ({
  default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a {...props} href={props.href}>
      {children}
    </a>
  ),
}));

afterEach(cleanup);

const NEW_SLOTS = [
  "product-card",
  "pdp.title",
  "pdp.below-atc",
  "pdp.below-gallery",
] as const;

/** Marker that echoes the slot data it received, so tests prove the wiring. */
function dataMarker(label: string): SlotComponent {
  function Marker(props: SlotProps) {
    const data = (props.data ?? {}) as Record<string, unknown>;
    return (
      <span
        data-slot-item
        data-testid={`slot-${label}`}
        data-product-id={String(data.productId ?? "")}
        data-product-slug={String(data.productSlug ?? "")}
        data-category={String(data.category ?? "")}
      >
        {label}
      </span>
    );
  }
  return Marker;
}

const cardProduct: Product = {
  id: "p1",
  name: "Silk slip dress",
  slug: "silk-slip-dress",
  status: "active",
  productType: "simple",
  capability: "available",
  category: "beauty",
  categoryName: "Beauty",
  description: "A fluid dress.",
  price: 12000,
  currency: "USD",
  image: "/dress.svg",
  imageAlt: "Silk dress",
  rating: 4.6,
  reviewCount: 24,
  tags: ["new"],
};

describe("batch 1 new slots (PLAN §2.1)", () => {
  it("declares the four new slot names and accepts them", () => {
    for (const name of NEW_SLOTS) {
      expect(SLOT_NAMES).toContain(name);
      expect(isSlotName(name)).toBe(true);
    }
  });

  it.each(NEW_SLOTS)("renders %s registrations in ascending priority order", (slot) => {
    const registry = createSlotRegistry({
      [slot]: [
        { id: "second", priority: 20, component: dataMarker("second") },
        { id: "first", priority: 10, component: dataMarker("first") },
      ],
    });
    const { container } = render(
      <SlotProvider registry={registry}>
        <Slot name={slot} />
      </SlotProvider>,
    );
    const labels = Array.from(container.querySelectorAll("[data-slot-item]")).map(
      (item) => item.textContent,
    );
    expect(labels).toEqual(["first", "second"]);
  });

  it("renders the product-card slot inside the card image frame with product data", () => {
    const registry = createSlotRegistry({
      "product-card": [
        { id: "siro/product-labels@1", priority: 10, component: dataMarker("card-badge") },
      ],
    });
    const { container } = render(
      <LocaleProvider locale="en">
        <SlotProvider registry={registry}>
          <ProductCard product={cardProduct} />
        </SlotProvider>
      </LocaleProvider>,
    );
    const marker = screen.getByTestId("slot-card-badge");
    expect(marker).toBeInTheDocument();
    expect(marker).toHaveAttribute("data-product-id", "p1");
    expect(marker).toHaveAttribute("data-product-slug", "silk-slip-dress");
    // The marker must sit inside the image frame (badge corner), not in the meta.
    expect(marker.closest(".product-image")).not.toBeNull();
    expect(container.querySelector(".product-meta")?.contains(marker)).toBe(false);
  });

  it("renders pdp.title right below the product title with product data", () => {
    const product = products[0]!;
    const registry = createSlotRegistry({
      "pdp.title": [
        { id: "siro/product-labels@1", priority: 10, component: dataMarker("pdp-title") },
      ],
    });
    const { container } = render(
      <SlotProvider registry={registry}>
        <ProductDecisionPanel product={product} />
      </SlotProvider>,
    );
    const marker = screen.getByTestId("slot-pdp-title");
    expect(marker).toBeInTheDocument();
    expect(marker).toHaveAttribute("data-product-id", product.id);
    expect(marker).toHaveAttribute("data-product-slug", product.slug);
    expect(marker).toHaveAttribute("data-category", product.category);
    const heading = container.querySelector("h1");
    expect(heading).not.toBeNull();
    // Title marker follows the h1 in document order.
    expect(
      Boolean(
        heading &&
          heading.compareDocumentPosition(marker) & Node.DOCUMENT_POSITION_FOLLOWING,
      ),
    ).toBe(true);
  });

  it("renders pdp.below-atc below the Add to Cart button with product data", () => {
    const product = products[0]!;
    const registry = createSlotRegistry({
      "pdp.below-atc": [
        { id: "siro/sticky-add-to-cart@1", priority: 10, component: dataMarker("below-atc") },
      ],
    });
    const { container } = render(
      <SlotProvider registry={registry}>
        <ProductDecisionPanel product={product} />
      </SlotProvider>,
    );
    const marker = screen.getByTestId("slot-below-atc");
    expect(marker).toBeInTheDocument();
    expect(marker).toHaveAttribute("data-product-id", product.id);
    expect(marker).toHaveAttribute("data-product-slug", product.slug);
    expect(marker).toHaveAttribute("data-category", product.category);
    const atc = screen.getByRole("button", { name: "Add to cart" });
    // Below-ATC marker follows the Add to Cart button in document order.
    expect(
      Boolean(atc.compareDocumentPosition(marker) & Node.DOCUMENT_POSITION_FOLLOWING),
    ).toBe(true);
    // And it is outside the ATC form itself.
    expect(atc.closest("form")?.contains(marker)).toBe(false);
  });
});
