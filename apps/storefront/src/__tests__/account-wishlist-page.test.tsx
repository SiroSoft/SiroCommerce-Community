import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { WishlistAccountView } from "@/components/WishlistProvider";
import { catalog } from "@/services/catalog";

vi.mock("@/services/catalog", () => ({ catalog: vi.fn() }));
vi.mock("@/services/customer", () => ({ customer: vi.fn() }));

const mockCatalog = vi.mocked(catalog);

const shirt = {
  id: "p-1",
  slug: "linen-camp-shirt",
  name: "Linen camp shirt",
  price: 6800,
  currency: "USD",
  image: "/catalog/b2c/linen-mandarin-shirt-front.svg",
  imageAlt: "Natural linen shirt illustration",
};

describe("account wishlist route", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    vi.clearAllMocks();
    mockCatalog.mockReturnValue({ getProduct: vi.fn().mockResolvedValue(shirt) } as never);
  });
  afterEach(() => cleanup());

  it("lists guest items saved in localStorage instead of fixture data", async () => {
    localStorage.setItem("sirocommerce:wishlist", JSON.stringify([{ slug: "linen-camp-shirt", added_at: "2026-01-01T00:00:00.000Z" }]));
    render(<WishlistAccountView />);
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();
    expect(screen.getByText("1 saved")).toBeInTheDocument();
    expect(screen.queryByText("stoneware-mug")).not.toBeInTheDocument();
    expect(mockCatalog).toHaveBeenCalled();
  });

  it("shows an empty wishlist when no local items were saved", async () => {
    render(<WishlistAccountView />);
    await waitFor(() => expect(screen.getByText(/Your wishlist is empty/)).toBeInTheDocument());
    expect(screen.queryByText("Linen camp shirt")).not.toBeInTheDocument();
  });
});
