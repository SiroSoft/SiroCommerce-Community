import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ProductCard } from "@/components/ProductCard";
import { LocaleProvider } from "@/lib/i18n-client";
import type { Product } from "@/types";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));

afterEach(cleanup);

const product: Product = {
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

describe("spotlight product card", () => {
  it("renders a full-bleed spotlight card with overlay label and price", () => {
    const { container } = render(<LocaleProvider locale="en"><ProductCard product={product} variant="spotlight" /></LocaleProvider>);
    const article = container.querySelector("article");
    expect(article).toHaveAttribute("data-card-variant", "spotlight");
    expect(article?.className).toContain("sf-card-spotlight");
    expect(screen.getByText("Spotlight")).toBeInTheDocument();
    expect(screen.getByAltText("Silk dress")).toHaveAttribute("src", "/dress.svg");
    expect(screen.getByRole("link", { name: /Silk slip dress/ })).toHaveAttribute("href", "/product/silk-slip-dress");
    expect(container.querySelector(".product-meta strong")).toHaveTextContent("$120.00");
  });

  it("keeps the editorial card free of the spotlight overlay label", () => {
    render(<LocaleProvider locale="en"><ProductCard product={product} /></LocaleProvider>);
    expect(screen.queryByText("Spotlight")).not.toBeInTheDocument();
  });
});
