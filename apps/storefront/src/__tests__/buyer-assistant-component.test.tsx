import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { BuyerAssistant } from "@/components/BuyerAssistant";
import { products } from "@/services/catalog";

describe("buyer assistant fallback", () => {
  it("uses local catalog logic when no provider is configured", () => {
    render(<BuyerAssistant products={products} />);
    expect(screen.getByRole("textbox", { name: "What are you looking for?" })).toBeInTheDocument();
    fireEvent.change(screen.getByRole("textbox"), { target: { value: "linen" } });
    fireEvent.submit(screen.getByRole("button", { name: "Suggest" }));
    expect(screen.getByRole("link", { name: /linen camp shirt/i })).toBeInTheDocument();
    expect(screen.getByText(/price and availability are from the current catalog/i)).toBeInTheDocument();
  });
});
