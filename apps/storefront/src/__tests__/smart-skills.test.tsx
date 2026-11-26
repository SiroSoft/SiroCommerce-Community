import "@testing-library/jest-dom/vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { BoughtTogether } from "@/components/BoughtTogether";
import { ReviewRollup } from "@/components/ReviewRollup";
import type { Product } from "@/types";

const product: Product = {
  id: "p1", name: "Serum", slug: "serum", status: "active", productType: "simple", capability: "available",
  category: "beauty", categoryName: "Beauty", description: "Serum", price: 200000, currency: "VND",
  image: "/x.jpg", imageAlt: "x", rating: 4.8, reviewCount: 10, tags: [],
};

function mockFetch(body: unknown, ok = true) {
  vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify(body), { status: ok ? 200 : 500, headers: { "Content-Type": "application/json" } })));
}

describe("smart skills storefront", () => {
  beforeEach(() => {
    vi.unstubAllGlobals();
  });

  it("bought-together renders nothing on cold start", async () => {
    mockFetch({ success: true, message: "ok", data: [] });
    const { container } = render(<BoughtTogether variantIds={["p1"]} products={[product]} />);
    await waitFor(() => expect(container.innerHTML).not.toContain("bought-together"));
  });

  it("bought-together shows mined pairs with add-all", async () => {
    const other: Product = { ...product, id: "p2", slug: "cream", name: "Cream" };
    mockFetch({ success: true, message: "ok", data: [{ variant_id: "p2", orders: 5, score: 5 }] });
    render(<BoughtTogether variantIds={["p1"]} products={[product, other]} />);
    expect(await screen.findByText("Cream")).toBeVisible();
    expect(screen.getByRole("button", { name: /add all/i })).toBeVisible();
  });

  it("review rollup renders counts, aspects and verbatim excerpts", async () => {
    mockFetch({
      success: true, message: "ok", data: {
        product_id: "p1", rating_avg: 4.5, rating_count: 2, pos_count: 1, neg_count: 1,
        aspects: { comfort: { pos: 1, neg: 0 } },
        excerpts: { positive: "Beautiful soft linen", negative: null },
      },
    });
    render(<ReviewRollup productId="p1" />);
    expect(await screen.findByText(/Beautiful soft linen/)).toBeVisible();
    expect(screen.getByText("comfort")).toBeVisible();
  });

  it("review rollup renders nothing without a summary", async () => {
    mockFetch({ success: true, message: "ok", data: null });
    const { container } = render(<ReviewRollup productId="p-none" />);
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(container.querySelector(".review-rollup")).toBeNull();
  });
});
