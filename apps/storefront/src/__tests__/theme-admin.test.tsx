import "@testing-library/jest-dom/vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";
import { ProductCard } from "@/components/ProductCard";
import { ProductGrid } from "@/components/ProductGrid";
import type { Product } from "@/types";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));
vi.mock("next/navigation", () => ({ usePathname: () => "/" }));

const product: Product = {
  id: "p1", name: "Serum", slug: "serum", status: "active", productType: "simple", capability: "available",
  category: "beauty", categoryName: "Beauty", description: "Serum", price: 200000, currency: "VND",
  image: "/x.jpg", imageAlt: "x", rating: 4.8, reviewCount: 10, tags: [],
};

describe("admin-driven chrome and variants", () => {
  it("hides the announcement and centers the header from config", () => {
    const { container } = render(<Header config={{ brand: { name: "Shop", wordmark: "shop" }, navigation: { menuKey: "primary", items: [] }, header: { announcement: false, layout: "centered", sticky: true } }} />);
    expect(container.querySelector(".announcement")).toBeNull();
    expect(container.querySelector("header")).toHaveAttribute("data-header-layout", "centered");
    expect(container.querySelector("header")).toHaveStyle({ position: "sticky" });
  });

  it("renders the split header with navigation first", () => {
    const { container } = render(<Header config={{ brand: { name: "Shop", wordmark: "shop" }, navigation: { menuKey: "primary", items: [{ label: "New", href: "/category/new" }] }, header: { announcement: true, layout: "split", sticky: false } }} />);
    expect(container.querySelector("header")).toHaveAttribute("data-header-layout", "split");
    const inner = container.querySelector(".header-inner");
    expect(inner?.children[0]?.tagName).toBe("NAV");
    expect(inner?.children[1]?.className).toContain("brand-lockup");
  });

  it("renders the mega footer with custom columns", () => {
    const mega = render(<Footer config={{ brand: { name: "Shop" }, navigation: { menuKey: "primary", items: [] }, policies: {}, footer: { layout: "mega", show_help: true, show_policies: false, columns: [{ title: "Tet", links: [{ label: "Sale", href: "/sale" }] }] } }} />);
    expect(mega.container.querySelector("footer")).toHaveAttribute("data-footer-layout", "mega");
    expect(mega.container.querySelector(".footer-mega-grid")).not.toBeNull();
    expect(within(mega.container).getByText("Tet")).toBeInTheDocument();
  });

  it("renders the wide list grid", () => {
    const { container } = render(<ProductGrid products={[product]} listVariant="list-grid-wide" />);
    expect(container.querySelector(".product-grid")).toHaveAttribute("data-list-layout", "list-grid-wide");
  });

  it("ships CSS for every batch-1 layout hook", () => {
    const css = readFileSync(join(process.cwd(), "src", "app", "globals.css"), "utf8");
    for (const selector of [".header-split", ".footer-mega", '[data-list-layout="list-grid-wide"]', '[data-detail-variant="page-gallery-left"]', '[data-density="compact"]', '[data-density="spacious"]', "--theme-font-display", "--theme-font-size"]) {
      expect(css).toContain(selector);
    }
  });

  it("renders the minimal footer and custom columns from config", () => {
    const { container } = render(<Footer config={{ brand: { name: "Shop", wordmark: "shop" }, navigation: { menuKey: "primary", items: [] }, policies: {}, footer: { layout: "minimal", show_help: false, show_policies: false, columns: [] } }} />);
    expect(container.querySelector("footer")).toHaveAttribute("data-footer-layout", "minimal");
    const standard = render(<Footer config={{ brand: { name: "Shop" }, navigation: { menuKey: "primary", items: [] }, policies: {}, footer: { layout: "standard", show_help: false, show_policies: false, columns: [{ title: "Tet", links: [{ label: "Sale", href: "/sale" }] }] } }} />);
    expect(within(standard.container).getByText("Tet")).toBeInTheDocument();
    expect(within(standard.container).getByRole("link", { name: "Sale" })).toHaveAttribute("href", "/sale");
  });

  it("renders the minimal card without ratings", () => {
    const { container } = render(<ProductCard product={product} variant="minimal" />);
    expect(container.querySelector("article")).toHaveAttribute("data-card-variant", "minimal");
    expect(container.textContent).not.toMatch(/4\.8/);
    expect(container.textContent).toMatch(/Serum/);
  });
});
