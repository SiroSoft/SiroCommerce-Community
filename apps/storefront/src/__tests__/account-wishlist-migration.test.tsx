import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  getWishlist: vi.fn(),
  addWishlist: vi.fn(),
  removeWishlist: vi.fn(),
  getProduct: vi.fn(),
}));

vi.mock("@/services/customer", () => ({
  customer: () => ({
    getWishlist: mocks.getWishlist,
    addWishlist: mocks.addWishlist,
    removeWishlist: mocks.removeWishlist,
  }),
}));
vi.mock("@/services/catalog", () => ({
  catalog: () => ({ getProduct: mocks.getProduct }),
}));

const WISHLIST_KEY = "sirocommerce:wishlist";
const REFRESH_KEY = "siro_storefront_refresh_token";

const shirt = {
  id: "p-1",
  slug: "linen-camp-shirt",
  name: "Linen camp shirt",
  price: 6800,
  currency: "USD",
  image: "/catalog/b2c/linen-mandarin-shirt-front.svg",
  imageAlt: "Natural linen shirt illustration",
  rating: 4.5,
  reviewCount: 12,
};

function storedEntries() {
  return [{ slug: "linen-camp-shirt", added_at: "2026-01-01T00:00:00.000Z" }];
}

function signIn() {
  window.localStorage.setItem(REFRESH_KEY, "refresh-token");
  window.dispatchEvent(new Event("siro:session-updated"));
}

describe("wishlist guest to account migration", () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    window.dispatchEvent(new Event("siro:session-updated"));
    mocks.getWishlist.mockReset().mockResolvedValue([]);
    mocks.addWishlist.mockReset().mockResolvedValue({ product_id: "p-1" });
    mocks.removeWishlist.mockReset().mockResolvedValue(undefined);
    mocks.getProduct.mockReset().mockResolvedValue(shirt);
  });

  afterEach(() => cleanup());

  it("uploads local slugs once, clears localStorage, and shows the sync notice", async () => {
    localStorage.setItem(WISHLIST_KEY, JSON.stringify(storedEntries()));
    mocks.getWishlist.mockResolvedValue([{ product_id: "linen-camp-shirt" }]);
    const { WishlistAccountView } = await import("@/components/WishlistProvider");

    render(<WishlistAccountView />);
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();

    signIn();

    await waitFor(() => expect(mocks.addWishlist).toHaveBeenCalledWith("linen-camp-shirt"));
    expect(mocks.addWishlist).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(localStorage.getItem(WISHLIST_KEY)).toBeNull());
    expect(await screen.findByText("Account synced")).toBeInTheDocument();
  });

  it("keeps localStorage and retries later when the API fails", async () => {
    localStorage.setItem(WISHLIST_KEY, JSON.stringify(storedEntries()));
    mocks.addWishlist.mockRejectedValue(new Error("offline"));
    const { WishlistAccountView } = await import("@/components/WishlistProvider");

    render(<WishlistAccountView />);
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();

    signIn();

    await waitFor(() => expect(mocks.addWishlist).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(mocks.getWishlist).toHaveBeenCalled());
    expect(JSON.parse(localStorage.getItem(WISHLIST_KEY) ?? "[]")).toEqual(storedEntries());
  });

  it("clears in-memory account state on logout without writing it to localStorage", async () => {
    localStorage.setItem(REFRESH_KEY, "refresh-token");
    mocks.getWishlist.mockResolvedValue([{ product_id: "p-1" }]);
    const { WishlistButton } = await import("@/components/BuyerEngagement");

    render(<WishlistButton product={shirt as never} />);
    window.dispatchEvent(new Event("siro:session-updated"));
    await waitFor(() => expect(screen.getByRole("button")).toHaveAttribute("aria-pressed", "true"));
    expect(localStorage.getItem(WISHLIST_KEY)).toBeNull();

    localStorage.removeItem(REFRESH_KEY);
    window.dispatchEvent(new Event("siro:session-updated"));

    await waitFor(() => expect(screen.getByRole("button")).toHaveAttribute("aria-pressed", "false"));
    expect(localStorage.getItem(WISHLIST_KEY)).toBeNull();
  });

  it("treats an already-saved account item as migrated without re-uploading it", async () => {
    localStorage.setItem(WISHLIST_KEY, JSON.stringify(storedEntries()));
    mocks.addWishlist.mockRejectedValue({ status: 409 });
    mocks.getWishlist.mockResolvedValue([{ product_id: "linen-camp-shirt" }]);
    const { WishlistAccountView } = await import("@/components/WishlistProvider");

    render(<WishlistAccountView />);
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();

    signIn();

    await waitFor(() => expect(mocks.addWishlist).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(localStorage.getItem(WISHLIST_KEY)).toBeNull());
    expect(await screen.findByText("Account synced")).toBeInTheDocument();

    const getCalls = mocks.getWishlist.mock.calls.length;
    window.dispatchEvent(new Event("siro:session-updated"));
    await waitFor(() => expect(mocks.getWishlist.mock.calls.length).toBeGreaterThan(getCalls));
    expect(mocks.addWishlist).toHaveBeenCalledTimes(1);
  });

  it("waits for the in-flight migration before toggling a newly added item", async () => {
    localStorage.setItem(WISHLIST_KEY, JSON.stringify(storedEntries()));
    let finishMigration: (value: unknown) => void = () => undefined;
    const migration = new Promise((resolve) => {
      finishMigration = resolve;
    });
    mocks.addWishlist.mockImplementation((productId: string) =>
      productId === "linen-camp-shirt" ? migration : Promise.resolve({ product_id: productId }),
    );
    mocks.getWishlist.mockResolvedValue([{ product_id: "linen-camp-shirt" }]);
    const { WishlistAccountView } = await import("@/components/WishlistProvider");
    const { WishlistButton } = await import("@/components/BuyerEngagement");
    const mug = { ...shirt, id: "p-2", slug: "stoneware-mug", name: "Stoneware mug" };

    render(<><WishlistAccountView /><WishlistButton product={mug as never} /></>);
    expect(await screen.findByText("Linen camp shirt")).toBeInTheDocument();

    signIn();
    await waitFor(() => expect(mocks.addWishlist).toHaveBeenCalledWith("linen-camp-shirt"));
    fireEvent.click(screen.getByRole("button", { name: "Save to wishlist: Stoneware mug" }));
    await new Promise((resolve) => window.setTimeout(resolve, 0));
    expect(mocks.addWishlist).not.toHaveBeenCalledWith("p-2");

    finishMigration({ product_id: "linen-camp-shirt" });

    await waitFor(() => expect(mocks.addWishlist).toHaveBeenCalledWith("p-2"));
    await waitFor(() => expect(screen.getByRole("button", { name: "Saved to wishlist: Stoneware mug" })).toBeInTheDocument());
    expect(localStorage.getItem(WISHLIST_KEY)).toBeNull();
  });
});
