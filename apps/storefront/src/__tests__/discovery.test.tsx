import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CatalogControls } from "@/components/CatalogControls";
import { SearchForm } from "@/components/SearchForm";
import { productHref } from "@/components/SearchSuggest";
import { products } from "@/services/catalog";
import { parseFacets, parsePage, queryForUrl } from "@/lib/discovery";

describe("discovery states", () => {
  afterEach(() => { document.body.innerHTML = ""; });

  it("renders a usable category result count and sort control", () => {
    render(<CatalogControls initialProducts={products.slice(0, 2)} query={{ category: "apparel" }} />);
    expect(screen.getByText("2 pieces")).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Sort" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Linen camp shirt" })).toBeInTheDocument();
  });

  it("renders the empty search state without crashing", () => {
    render(<CatalogControls initialProducts={[]} query={{ search: "missing" }} />);
    expect(screen.getByText("0 pieces")).toBeInTheDocument();
    expect(screen.getByText(/no products found for "missing"/i)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Clear search" })).toHaveAttribute("href", "/search");
    expect(screen.queryByRole("heading", { name: "Linen camp shirt" })).not.toBeInTheDocument();
  });

  it("keeps sort and pagination state in shareable query URLs", () => {
    expect(parsePage("0")).toBe(1);
    expect(parsePage("3")).toBe(3);
    expect(queryForUrl({ search: "linen", page: 3 }, "price-desc")).toBe("?q=linen&sort=price-desc&page=3");
  });

  it("encodes search terms instead of allowing query delimiters", () => {
    expect(queryForUrl({ search: "mug & tea", page: 2 }, "featured")).toBe("?q=mug+%26+tea&page=2");
  });

  it("renders a next page link only when the bounded page is full", () => {
    render(<CatalogControls initialProducts={products.slice(0, 4)} query={{ search: "", page: 1, perPage: 4 }} />);
    expect(screen.getByRole("link", { name: "Next" })).toHaveAttribute("href", "?page=2");
  });

  it("uses the server total to avoid offering a page past the result set", () => {
    render(<CatalogControls initialProducts={products.slice(0, 2)} total={5} query={{ page: 2, perPage: 4 }} />);
    expect(screen.queryByRole("link", { name: "Next" })).not.toBeInTheDocument();
  });

  it("exposes optional catalog suggestions through an accessible native list", () => {
    render(<SearchForm value="lin" suggestions={[products[0]!]} />);
    expect(screen.getByRole("search")).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Search the collection" })).toHaveAttribute("list", "search-suggestions");
    expect(document.querySelector('#search-suggestions option')).toHaveAttribute("value", "Linen camp shirt");
  });

  it("wires the query suggest listbox to the search input", () => {
    render(<SearchForm value="lin" />);
    const input = screen.getByRole("textbox", { name: "Search the collection" });
    expect(input).toHaveAttribute("aria-controls", "catalog-search-suggestions");
    expect(input).toHaveAttribute("aria-autocomplete", "list");
  });

  it("renders server-provided facets as accessible filter controls", () => {
    render(<CatalogControls initialProducts={products.slice(0, 1)} query={{ category: "apparel", facets: { color: ["natural"] } }} facets={[{ name: "color", label: "Color", values: [{ value: "natural", label: "Natural", count: 1 }] }]} />);
    expect(screen.getByText("Filter results")).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: /natural/i })).toBeChecked();
    expect(screen.getByRole("button", { name: "Apply filters" })).toBeInTheDocument();
  });

  it("parses repeated and comma-separated facet query values", () => {
    expect(parseFacets({ facet_color: ["natural,black", "cream"], q: "shirt" })).toEqual({ color: ["natural", "black", "cream"] });
    expect(queryForUrl({ search: "shirt", facets: { color: ["natural", "black"] } }, "featured")).toBe("?q=shirt&facet_color=natural%2Cblack");
  });

  it("links product suggestions to the real /product/[slug] route", () => {
    expect(productHref("linen-camp-shirt")).toBe("/product/linen-camp-shirt");
    expect(productHref("linen camp shirt")).toBe("/product/linen%20camp%20shirt");
  });
});
