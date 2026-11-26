import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { CatalogControls } from "@/components/CatalogControls";
import { products } from "@/services/catalog";

vi.mock("next/link", () => ({ default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a> }));

describe("catalog controls", () => {
  it("keeps facet controls labelled and touch-sized", () => {
    render(<CatalogControls initialProducts={[products[0]!]} total={1} query={{ search: "", page: 1, perPage: 24 }} facets={[{ name: "tag", label: "Tag", values: [{ value: "linen", label: "Linen", count: 1 }] }]} />);
    fireEvent.click(screen.getByText(/Filter results/));
    const tag = screen.getByRole("checkbox", { name: /Linen/ });
    expect(tag).not.toBeChecked();
    fireEvent.click(tag);
    expect(tag).toBeChecked();
    expect(screen.getByRole("button", { name: /Apply filters/i })).toHaveClass("button");
  });

  it("shows active filter chips with a remove link and counts them on the summary", () => {
    render(<CatalogControls initialProducts={[products[0]!]} query={{ search: "linen", facets: { color: ["natural"] } }} facets={[{ name: "color", label: "Color", values: [{ value: "natural", label: "Natural", count: 1 }] }]} />);
    expect(screen.getByText(/1 filter active/)).toBeInTheDocument();
    const remove = screen.getByRole("link", { name: "Remove filter Natural" });
    expect(remove).toHaveAttribute("href", "?q=linen");
  });

  it("renders numbered pagination with the current page marked and bounded next link", () => {
    render(<CatalogControls initialProducts={products.slice(0, 4)} total={10} query={{ search: "", page: 2, perPage: 4 }} />);
    expect(screen.getByRole("link", { name: "Page 1" })).toHaveAttribute("href", "?page=1");
    expect(screen.getByLabelText("Page 2")).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "Page 3" })).toHaveAttribute("href", "?page=3");
    expect(screen.getByRole("link", { name: "Next" })).toHaveAttribute("href", "?page=3");
    expect(screen.queryByRole("link", { name: "Page 4" })).not.toBeInTheDocument();
  });
});
