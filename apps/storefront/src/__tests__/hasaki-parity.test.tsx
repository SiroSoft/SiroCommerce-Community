import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { createApiCatalog } from "@/services/catalog";
import { ProductDecisionPanel, FlashCountdown } from "@/components/ProductDecisionPanel";
import { ProductCard } from "@/components/ProductCard";
import type { Product } from "@/types";

function response(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

const base: Product = {
  id: "p1", name: "Serum", slug: "serum", status: "active", productType: "simple", capability: "available",
  category: "beauty", categoryName: "Beauty", description: "Serum", price: 200000, currency: "VND",
  image: "/x.jpg", imageAlt: "x", rating: 4.8, reviewCount: 10, tags: [],
};

describe("hasaki parity — flash, member, store stock", () => {
  it("maps flash_sales, member_prices and stock_by_source from the API", async () => {
    const transport = vi.fn(async () => response({ success: true, message: "ok", data: [{
      id: "p1", name: "Serum", slug: "serum", price: 200000, currency: "VND",
      flash_sales: [{ sale_id: "s1", sale_name: "Gio vang", variant_id: "p1", sale_price_minor: 150000, starts_at: "2026-01-01T00:00:00Z", ends_at: "2026-12-31T00:00:00Z", cap_qty: 100, sold_qty: 10, remaining: 90, priority: 0 }],
      member_prices: [{ id: "m1", variant_id: "p1", tier: "gold", price_minor: 180000, currency: "VND" }],
      stock_by_source: { p1: { default: 50, "store-hcm-01": 5 } },
    }], meta: {} }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    await expect(catalog.listProducts()).resolves.toMatchObject([{
      flashSales: [{ sale_id: "s1", sale_price_minor: 150000 }],
      memberPrices: [{ tier: "gold", price_minor: 180000 }],
      stockBySource: { p1: { default: 50 } },
    }]);
  });

  it("shows flash badge with countdown, member table and store availability", () => {
    const product: Product = {
      ...base,
      flashSales: [{ sale_id: "s1", sale_name: "Gio vang", variant_id: "p1", sale_price_minor: 150000, starts_at: "2026-01-01T00:00:00Z", ends_at: "2099-01-01T00:00:00Z", cap_qty: 100, sold_qty: 10, remaining: 90, priority: 0 }],
      memberPrices: [{ id: "m1", variant_id: "p1", tier: "gold", price_minor: 180000, currency: "VND" }],
      stockBySource: { p1: { default: 50, "store-hcm-01": 5 } },
    };
    const { container } = render(<ProductDecisionPanel product={product} />);
    expect(container.textContent).toMatch(/Gio vang/);
    expect(container.textContent).toMatch(/only 90 left/);
    expect(screen.getByRole("timer")).toBeVisible();
    expect(container.textContent).toMatch(/Member prices/);
    expect(container.textContent).toMatch(/gold/);
    expect(container.textContent).toMatch(/In-store availability/);
    expect(container.textContent).toMatch(/store-hcm-01/);
    // flash price displayed instead of regular
    expect(container.textContent).toMatch(/150/);
  });

  it("countdown shows ended state after the window", () => {
    const { container } = render(<FlashCountdown endsAt="2000-01-01T00:00:00Z" locale="en" />);
    expect(container.textContent).toMatch(/Deal ended/);
  });

  it("drops malformed flash, member and stock payloads instead of crashing", async () => {
    const transport = vi.fn(async () => response({ success: true, message: "ok", data: [{
      id: "bad", name: "Bad", slug: "bad", price: 100, currency: "VND",
      flash_sales: [{ sale_id: "s1" }],
      member_prices: "gold",
      stock_by_source: { p1: { default: -5 } },
    }], meta: {} }));
    const catalog = createApiCatalog({ baseUrl: "https://api.example.test", fetch: transport });
    const products = await catalog.listProducts();
    expect(products).toHaveLength(1);
    expect(products[0]?.slug).toBe("bad");
    expect(products[0]?.flashSales).toBeUndefined();
    expect(products[0]?.memberPrices).toBeUndefined();
    expect(products[0]?.stockBySource).toBeUndefined();
  });

  it("countdown handles invalid dates as ended", () => {
    const { container } = render(<FlashCountdown endsAt="not-a-date" locale="en" />);
    expect(container.textContent).toMatch(/Deal ended/);
  });

  it("card surfaces the flash price with a flash badge", () => {
    const product: Product = {
      ...base,
      flashSales: [{ sale_id: "s1", sale_name: "Gio vang", variant_id: "p1", sale_price_minor: 150000, starts_at: "2026-01-01T00:00:00Z", ends_at: "2099-01-01T00:00:00Z", cap_qty: 100, sold_qty: 10, remaining: 90, priority: 0 }],
    };
    const { container } = render(<ProductCard product={product} />);
    expect(container.textContent).toMatch(/Flash deal/);
    expect(container.textContent).toMatch(/150/);
  });

  it("card surfaces the best member price cue when no flash deal", () => {
    const product: Product = {
      ...base,
      memberPrices: [
        { id: "m1", variant_id: "p1", tier: "gold", price_minor: 180000, currency: "VND" },
        { id: "m2", variant_id: "p1", tier: "silver", price_minor: 190000, currency: "VND" },
      ],
    };
    const { container } = render(<ProductCard product={product} />);
    expect(container.textContent).toMatch(/Member from/);
    expect(container.textContent).toMatch(/180/);
  });

  it("hides flash and member sections when no deals exist", () => {
    const { container } = render(<ProductDecisionPanel product={base} />);
    expect(container.textContent).not.toMatch(/Member prices/);
    expect(container.textContent).not.toMatch(/In-store availability/);
    expect(container.textContent).not.toMatch(/only .* left/);
  });
});
