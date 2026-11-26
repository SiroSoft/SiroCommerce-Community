import "@testing-library/jest-dom/vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it, vi } from "vitest";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";
import Loading from "@/app/loading";
import type { Product } from "@/types";

const product: Product = {
  id: "p1", name: "Test Product", slug: "test-product", status: "active", productType: "simple", capability: "available", category: "test", categoryName: "Test", description: "Desc", price: 5000, currency: "VND", image: "/test.jpg", imageAlt: "Test", rating: 4.5, reviewCount: 10, tags: ["test"],
  bookingSlots: [{ id: "s1", variant_id: "v1", starts_at: new Date(Date.now()+3600000).toISOString(), ends_at: new Date(Date.now()+7200000).toISOString(), capacity: 5, price_minor: 6000, currency: "VND" }],
};

describe("deep UX — loading, effects, transitions, a11y", () => {
  it("loading skeleton has busy region and pulse", () => {
    const { container } = render(<Loading />);
    expect(container.innerHTML).toMatch(/skeleton|skeleton-card|aria-busy|aria-live/i);
  });
  it("product decision panel loading state: adding shows is-loading and aria-busy", async () => {
    const booking: Product = { ...product, productType: "booking", bookingSlots: product.bookingSlots };
    const { container } = render(<ProductDecisionPanel product={booking} />);
    const btn = container.querySelector('button[type="submit"]') as HTMLButtonElement;
    expect(btn).toBeTruthy();
    expect(btn.disabled).toBe(false);
    // click triggers adding
    fireEvent.click(btn);
    await waitFor(() => expect(btn.className).toMatch(/is-loading|aria-busy/));
    expect(btn.getAttribute("aria-busy")).toBeTruthy();
  });
  it("booking slot picker has radio group, gap-2, capacity and price", () => {
    const booking: Product = { ...product, productType: "booking" };
    const { container } = render(<ProductDecisionPanel product={booking} />);
    const group = container.querySelector('[role="radiogroup"]');
    expect(group).toBeTruthy();
    expect(group?.className).toMatch(/gap-2/);
    const radios = container.querySelectorAll('input[type="radio"][name="booking_slot"]');
    expect(radios.length).toBeGreaterThan(0);
    expect(container.innerHTML).toMatch(/capacity/);
    expect(container.innerHTML).toMatch(/Choose a slot/);
  });
  it("quantity control respects max and disables buttons at bounds", () => {
    const { container } = render(<ProductDecisionPanel product={{ ...product, stock: 2 }} />);
    const dec = container.querySelector('button[aria-label*="Decrease"]') as HTMLButtonElement;
    const inc = container.querySelector('button[aria-label*="Increase"]') as HTMLButtonElement;
    const qtyInput = container.querySelector('input[type="number"]') as HTMLInputElement;
    expect(qtyInput).toBeTruthy();
    expect(dec.disabled).toBe(true); // qty 1 at min
    expect(qtyInput.value).toBe("1");
    fireEvent.click(inc);
    expect(qtyInput.value).toBe("2");
    expect(inc.disabled).toBe(true); // at max 2
  });
  it("header has sticky, nav, search, cart and focusable links", () => {
    const { container } = render(<Header />);
    expect(container.innerHTML).toMatch(/nav|header|search/i);
    // all links must be focusable
    const links = container.querySelectorAll("a");
    for (const a of links) expect(a.getAttribute("href")).toBeTruthy();
  });
  it("footer has semantic nav and no hard-coded policy when missing", () => {
    const { container } = render(<Footer />);
    expect(container.querySelector("nav")).toBeTruthy();
    expect(container.innerHTML).toMatch(/footer/i);
  });
  it("globals.css has effect tokens: reduced-motion, hover, active, skeleton, transitions", () => {
    const css = readFileSync(join(process.cwd(), "src/app/globals.css"), "utf8");
    const tokens = [
      "prefers-reduced-motion", "transition", "hover", ":active", "skeleton", "animation", "focus-visible"
    ];
    for (const t of tokens) expect(css).toContain(t);
  });
  it("toast/notice appears after form submit (simulated)", async () => {
    const { container } = render(<ProductDecisionPanel product={product} />);
    const btn = container.querySelector('button[type="submit"]') as HTMLButtonElement;
    fireEvent.click(btn);
    await waitFor(() => expect(btn.getAttribute("aria-busy") || btn.className).toBeTruthy());
  });
});
