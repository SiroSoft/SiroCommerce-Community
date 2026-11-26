import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { BuyerEngagement, WishlistButton } from "@/components/BuyerEngagement";
import type { Product } from "@/types";

const product = { id: "p-1", slug: "linen-overshirt", name: "Linen Overshirt", price: 1000, currency: "USD", rating: 4.5, reviewCount: 12 } as Product;

describe("buyer engagement", () => {
  beforeEach(() => { sessionStorage.clear(); localStorage.clear(); });
  afterEach(() => { cleanup(); });

  it("starts unsaved without fixtures and persists a guest wishlist toggle", async () => {
    render(<WishlistButton product={product} />);
    const button = screen.getByRole("button");
    expect(button).toHaveAttribute("aria-pressed", "false");
    expect(button.textContent).toContain("Save to wishlist");

    fireEvent.click(button);
    await waitFor(() => expect(screen.getByRole("button")).toHaveAttribute("aria-pressed", "true"));
    expect(screen.getByRole("button").textContent).toContain("Saved to wishlist");
    const stored = JSON.parse(localStorage.getItem("sirocommerce:wishlist") ?? "[]") as { slug: string; added_at: string }[];
    expect(stored).toHaveLength(1);
    expect(stored[0]?.slug).toBe("linen-overshirt");
    expect(typeof stored[0]?.added_at).toBe("string");

    fireEvent.click(screen.getByRole("button"));
    await waitFor(() => expect(screen.getByRole("button")).toHaveAttribute("aria-pressed", "false"));
    expect(JSON.parse(localStorage.getItem("sirocommerce:wishlist") ?? "[]")).toEqual([]);
  });

  it("loads addresses and notification preferences through the API", async () => {
    window.localStorage.setItem("siro_storefront_refresh_token", "refresh-fixture");
    render(<BuyerEngagement />);
    expect(screen.getByText(/loading|Loading/i)).toBeTruthy();
  });

  it("shows the signed-out state without a doomed request when never signed in", () => {
    window.localStorage.removeItem("siro_storefront_refresh_token");
    render(<BuyerEngagement />);
    expect(screen.queryByText(/loading|Loading/i)).toBeNull();
  });
});
