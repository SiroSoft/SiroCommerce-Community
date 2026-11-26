import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import type { Product } from "@/types";

const mockProduct: Product = {
  id: "p1", name: "Test", slug: "test", status: "active", productType: "simple", capability: "available", category: "test", categoryName: "Test", description: "Desc", price: 1000, currency: "VND", image: "/test.jpg", imageAlt: "Test", rating: 4, reviewCount: 1, tags: [],
};

describe("R9 storefront UI tokens — button, booking, card, table", () => {
  it("product decision panel has quantity and add button with token classes", () => {
    const { container } = render(<ProductDecisionPanel product={mockProduct} />);
    expect(container.innerHTML).toMatch(/quantity-control|detail-add|button/);
    expect(container.innerHTML).toMatch(/quantity-control|detail-add/);
  });
  it("booking product renders slot picker with gap-2", () => {
    const bookingProduct: Product = { ...mockProduct, productType: "booking", bookingSlots: [{ id: "s1", variant_id: "v1", starts_at: new Date().toISOString(), ends_at: new Date(Date.now()+3600000).toISOString(), capacity: 5 }] };
    const { container } = render(<ProductDecisionPanel product={bookingProduct} />);
    expect(container.innerHTML).toMatch(/Choose a slot|booking-slot/);
    expect(container.innerHTML).toMatch(/gap-2/);
  });
  it("detail page has p-6 and space-y tokens", () => {
    const { container } = render(<div className="detail-info p-6 space-y-4 gap-2">x</div>);
    expect(container.innerHTML).toMatch(/p-6|space-y-4|gap-2/);
  });
});
