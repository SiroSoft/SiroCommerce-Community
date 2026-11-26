import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AccountWishlist } from "../components/account/AccountWishlist";
import { customer } from "@/services/customer";
import { catalog } from "@/services/catalog";

vi.mock("@/services/customer", () => ({ customer: vi.fn() }));
vi.mock("@/services/catalog", () => ({ catalog: vi.fn() }));

const mockCustomer = vi.mocked(customer);
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

describe("account wishlist", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    vi.clearAllMocks();
    mockCustomer.mockReturnValue({ getWishlist: vi.fn(), removeWishlist: vi.fn() } as never);
    mockCatalog.mockReturnValue({ getProduct: vi.fn() } as never);
  });

  function adapter() {
    return mockCustomer() as unknown as { getWishlist: ReturnType<typeof vi.fn>; removeWishlist: ReturnType<typeof vi.fn> };
  }

  function catalogAdapter() {
    return mockCatalog() as unknown as { getProduct: ReturnType<typeof vi.fn> };
  }

  it("prompts signed-out customers to sign in without calling the API", () => {
    render(<AccountWishlist isAuthenticated={false} />);
    expect(screen.getByRole("heading", { name: "Wishlist" })).toBeInTheDocument();
    expect(screen.getByText("Sign in to view your wishlist.")).toBeInTheDocument();
    expect(mockCustomer).not.toHaveBeenCalled();
  });

  it("renders saved items with catalog name, image, and price", async () => {
    adapter().getWishlist.mockResolvedValue([{ product_id: "p-1" }]);
    catalogAdapter().getProduct.mockResolvedValue(shirt);
    const { container } = render(<AccountWishlist isAuthenticated />);
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();
    expect(screen.getByText("$68.00")).toBeInTheDocument();
    expect(container.querySelector("img.cart-item-image")).toHaveAttribute("src", "/catalog/b2c/linen-mandarin-shirt-front.svg");
    expect(screen.getByText("1 saved")).toBeInTheDocument();
  });

  it("keeps rows whose catalog details are unavailable under their saved id", async () => {
    adapter().getWishlist.mockResolvedValue([{ product_id: "p-unknown" }]);
    catalogAdapter().getProduct.mockResolvedValue(undefined);
    render(<AccountWishlist isAuthenticated />);
    expect(await screen.findByText("p-unknown")).toBeInTheDocument();
    expect(screen.getByText("Details unavailable")).toBeInTheDocument();
  });

  it("shows the empty state when the backend wishlist has no items", async () => {
    adapter().getWishlist.mockResolvedValue([]);
    render(<AccountWishlist isAuthenticated />);
    expect(await screen.findByText(/Your wishlist is empty/)).toBeInTheDocument();
  });

  it("shows an error with retry when the wishlist cannot load", async () => {
    adapter().getWishlist.mockRejectedValue(new Error("offline"));
    render(<AccountWishlist isAuthenticated />);
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not load your wishlist.");
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(adapter().getWishlist).toHaveBeenCalledTimes(2));
  });

  it("removes an item through DELETE /api/wishlist/{product}", async () => {
    adapter().getWishlist.mockResolvedValue([{ product_id: "p-1" }]);
    adapter().removeWishlist.mockResolvedValue(undefined);
    catalogAdapter().getProduct.mockResolvedValue(shirt);
    render(<AccountWishlist isAuthenticated />);
    const remove = await screen.findByRole("button", { name: "Remove Linen camp shirt from wishlist" });
    fireEvent.click(remove);
    await waitFor(() => expect(adapter().removeWishlist).toHaveBeenCalledWith("p-1"));
    await waitFor(() => expect(screen.queryByText("Linen camp shirt")).not.toBeInTheDocument());
    expect(screen.getByText("Removed from your wishlist.")).toBeInTheDocument();
  });

  it("posts add-to-cart to /cart/add with the saved product", async () => {
    adapter().getWishlist.mockResolvedValue([{ product_id: "p-1", variant_id: "v-1" }]);
    catalogAdapter().getProduct.mockResolvedValue(shirt);
    const { container } = render(<AccountWishlist isAuthenticated />);
    await screen.findByText("Linen camp shirt");
    const form = container.querySelector('form[action="/cart/add"]');
    expect(form).not.toBeNull();
    expect(form?.querySelector('input[name="product_id"]')).toHaveAttribute("value", "v-1");
    expect(form?.querySelector('input[name="quantity"]')).toHaveAttribute("value", "1");
  });
});
