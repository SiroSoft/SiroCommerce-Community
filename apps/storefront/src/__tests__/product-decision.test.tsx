import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import { products } from "@/services/catalog";

describe("product decision panel", () => {
  afterEach(() => { document.body.innerHTML = ""; });

  it("supports quantity changes, sale pricing, and a quantity-aware cart post", () => {
    render(<ProductDecisionPanel product={products[0]!} />);
    expect(screen.getByRole("paragraph", { name: "Price" })).toHaveTextContent("$68.00");
    fireEvent.click(screen.getByRole("button", { name: "Increase quantity" }));
    expect(screen.getByRole("spinbutton", { name: "Quantity" })).toHaveValue(2);
    const form = screen.getByRole("button", { name: "Add to cart" }).closest("form") as HTMLFormElement;
    expect(form.getAttribute("method")).toBe("post");
    expect(new FormData(form).get("product_id")).toBe("p-1");
    expect(new FormData(form).get("quantity")).toBe("2");
    expect(form.getAttribute("action")).toBe("/cart/add");
  });

  it("keeps unsupported options and fulfillment provider-neutral", () => {
    const product = { ...products[0]!, capability: "unavailable" as const, options: [{ name: "Size", values: ["Small", "Large"] }], bundleComponents: [{ id: "component-1", name: "Inner layer", quantity: 1, required: true }] };
    render(<ProductDecisionPanel product={product} />);
    expect(screen.getByRole("button", { name: "Size: Small" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Size: Small" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("heading", { name: "In this bundle" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Unavailable" })).toBeDisabled();
    expect(screen.queryByRole("button", { name: /add to cart/i })).not.toBeInTheDocument();
  });

it("keeps the cart post free of price and option query state", () => {
    const product = { ...products[0]!, options: [{ name: "Size", values: ["Small", "Large"] }] };
    render(<ProductDecisionPanel product={product} />);
    fireEvent.click(screen.getByRole("button", { name: "Size: Large" }));
    expect(screen.getByRole("button", { name: "Size: Large" })).toHaveAttribute("aria-pressed", "true");
    const form = screen.getByRole("button", { name: "Add to cart" }).closest("form") as HTMLFormElement;
    expect(form.getAttribute("action")).toBe("/cart/add");
    expect(form.getAttribute("action")).not.toContain("product_price");
    expect(form.getAttribute("action")).not.toContain("option_Size");
  });

  it("falls back to a select when an option has more than eight values", () => {
    const product = { ...products[0]!, options: [{ name: "Size", values: ["XS", "S", "M", "L", "XL", "XXL", "3XL", "4XL", "5XL"] }] };
    render(<ProductDecisionPanel product={product} />);
    expect(screen.getByRole("combobox", { name: "Size" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Size: Small" })).not.toBeInTheDocument();
  });

  it("exposes option pricing on the pill and a size guide only when size data exists", () => {
    const product = {
      ...products[0]!,
      options: [{ name: "Size", values: ["Small", "Large"] }],
      optionPricing: [{ option: "Size", value: "Large", surcharge: 500, currency: "USD" }],
      attributes: [{ name: "Size fit", value: "Relaxed" }],
    };
    render(<ProductDecisionPanel product={product} />);
    expect(screen.getByRole("button", { name: /Size: Large/ })).toHaveTextContent("+$5.00");
    const toggle = screen.getByRole("button", { name: "Size guide" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("region", { name: "Size guide" })).toHaveTextContent("Relaxed");
  });
});
