import "@testing-library/jest-dom/vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Product } from "@/types";

const products: Product[] = Array.from({ length: 4 }, (_, index) => ({
  id: `p-${index + 2}`,
  name: `Product ${index + 2}`,
  slug: `product-${index + 2}`,
  status: "active",
  productType: "simple",
  capability: "available",
  category: "apparel",
  categoryName: "Apparel",
  description: "A product",
  price: 1000,
  currency: "USD",
  image: "/catalog/product.svg",
  imageAlt: "Product",
  rating: 0,
  reviewCount: 0,
  tags: [],
}));

vi.mock("next/link", () => ({
  default: ({ children, ...props }: React.PropsWithChildren<{ href: string }>) => <a {...props} href={props.href}>{children}</a>,
}));

const notFound = vi.hoisted(() => vi.fn(() => { throw new Error("NEXT_NOT_FOUND"); }));
vi.mock("next/navigation", () => ({
  notFound,
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

const cartServer = vi.hoisted(() => ({
  loadCartSnapshot: vi.fn((): Promise<unknown> => Promise.resolve(undefined)),
  addVariantToCart: vi.fn(async () => undefined),
  publicOrigin: vi.fn(async () => "https://shop.example.test"),
}));
vi.mock("@/lib/cart-server", () => cartServer);

const requestLocale = vi.hoisted(() => vi.fn(async () => "en" as const));
const requestScope = vi.hoisted(() => vi.fn(async () => ({ store_id: "store", channel_id: "web", locale: "en", currency: "USD" })));
vi.mock("@/lib/request-locale", () => ({ requestLocale, requestScope, requestCurrency: vi.fn(async () => "USD") }));

const catalogMock = vi.hoisted(() => vi.fn());
const CatalogRequestError = vi.hoisted(() => class CatalogRequestError extends Error {
  constructor(readonly status: number, message: string) { super(message); this.name = "CatalogRequestError"; }
});
vi.mock("@/services/catalog", () => ({ catalog: catalogMock, CatalogRequestError }));

vi.mock("@/components/PurchaseFlow", () => ({
  PurchaseFlow: ({ initialStep, flash }: { initialStep?: string; flash?: string }) => <div data-testid="purchase-flow">{initialStep}:{flash ?? "none"}</div>,
}));
vi.mock("@/components/PurchaseFlowLazy", () => ({
  PurchaseFlowLazy: ({ initialStep }: { initialStep?: string }) => <div data-testid="purchase-flow-lazy">{initialStep}</div>,
}));
vi.mock("@/components/ProductGrid", () => ({
  ProductGrid: ({ products: list }: { products: unknown[] }) => <div data-testid="product-grid">{list.length}</div>,
}));
vi.mock("@/components/CatalogState", () => ({
  CatalogState: ({ error }: { error: Error }) => <div data-testid="catalog-state">{error.message}</div>,
}));
vi.mock("@/components/SubscriptionExperience", () => ({ SubscriptionExperience: () => <div data-testid="subscriptions" /> }));
vi.mock("@/components/BuyerEngagement", () => ({ BuyerEngagement: () => <div data-testid="engagement" /> }));
vi.mock("@/components/AccountPrivacyControls", () => ({ AccountPrivacyControls: () => <div data-testid="privacy" /> }));
vi.mock("@/components/MessageHistory", () => ({ MessageHistory: () => <div data-testid="messages" /> }));
vi.mock("@/components/WishlistProvider", () => ({ WishlistAccountView: () => <div data-testid="wishlist-view" /> }));

const snapshot = {
  cartId: "cart-abcdefgh",
  currency: "USD",
  subtotalMinor: 1250,
  raw: {},
  lines: [{ lineId: "line-1", productId: "p-1", slug: "shirt", name: "Shirt", unitPriceMinor: 625, quantity: 2, lineTotalMinor: 1250 }],
};

afterEach(() => {
  vi.clearAllMocks();
  vi.unstubAllEnvs();
});

describe("cart page", () => {
  beforeEach(() => {
    cartServer.loadCartSnapshot.mockResolvedValue(undefined);
    catalogMock.mockReturnValue({ listProductsResult: vi.fn(async () => ({ products: [] })) });
  });

  it("renders the empty bag and passes no flash flag", async () => {
    const { default: CartPage, generateMetadata } = await import("@/app/cart/page");
    const meta = await generateMetadata();
    expect(meta.robots).toEqual({ index: false, follow: false });

    render(await CartPage({}));
    expect(screen.getByRole("heading", { name: "Your bag" })).toBeInTheDocument();
    expect(screen.getByTestId("purchase-flow")).toHaveTextContent("cart:none");
    expect(screen.getByRole("link", { name: "Continue shopping" })).toHaveAttribute("href", "/search");
  });

  it("maps every flash flag and array query values", async () => {
    const { default: CartPage } = await import("@/app/cart/page");
    for (const [query, expected] of [
      [{ added: "1" }, "added"],
      [{ updated: "1" }, "updated"],
      [{ removed: "1" }, "removed"],
      [{ add: "failed" }, "failed"],
      [{ added: ["1"] }, "added"],
    ] as const) {
      const { unmount } = render(await CartPage({ searchParams: Promise.resolve(query as Record<string, string | string[]>) }));
      expect(screen.getByTestId("purchase-flow")).toHaveTextContent(`cart:${expected}`);
      unmount();
    }
  });

  it("renders cross-sell products without the current bag lines", async () => {
    cartServer.loadCartSnapshot.mockResolvedValue(snapshot);
    const listProductsResult = vi.fn(async () => ({ products: [products[0]!, products[1]!, products[2]!, products[3]!] }));
    catalogMock.mockReturnValue({ listProductsResult });
    const { default: CartPage } = await import("@/app/cart/page");
    const ui = await CartPage({});
    await act(async () => { render(ui); });
    expect(await screen.findByText("You may also like")).toBeInTheDocument();
    expect(screen.getByTestId("product-grid")).toHaveTextContent("3");
    expect(screen.queryByRole("link", { name: "Continue shopping" })).toBeNull();
  });

  it("degrades cross-sell failures to no recommendations", async () => {
    catalogMock.mockReturnValue({ listProductsResult: vi.fn(async () => { throw new Error("down"); }) });
    const { default: CartPage } = await import("@/app/cart/page");
    const ui = await CartPage({});
    await act(async () => { render(ui); });
    await waitFor(() => expect(screen.queryByText("You may also like")).toBeNull());
  });
});

describe("quote and checkout pages", () => {
  it("renders the quote page without a configured support email", async () => {
    cartServer.loadCartSnapshot.mockResolvedValue(undefined);
    const { default: QuotePage, generateMetadata } = await import("@/app/quote/page");
    expect((await generateMetadata()).robots).toEqual({ index: false, follow: false });
    render(await QuotePage());
    expect(screen.getByTestId("purchase-flow-lazy")).toHaveTextContent("quote");
    expect(screen.queryByRole("link", { name: "Contact support" })).toBeNull();
  });

  it("renders the quote support link when an email is configured", async () => {
    vi.resetModules();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SUPPORT_EMAIL", "help@example.test");
    const { default: QuotePage } = await import("@/app/quote/page");
    render(await QuotePage());
    expect(screen.getByRole("link", { name: "Contact support" })).toHaveAttribute("href", "mailto:help@example.test?subject=Shipping%20quote");
  });

  it("renders the checkout page with its metadata", async () => {
    cartServer.loadCartSnapshot.mockResolvedValue(snapshot);
    const { default: CheckoutPage, generateMetadata } = await import("@/app/checkout/page");
    const meta = await generateMetadata();
    expect(meta.robots).toEqual({ index: false, follow: false });
    render(await CheckoutPage());
    expect(screen.getByTestId("purchase-flow-lazy")).toHaveTextContent("checkout");
  });
});

describe("account pages", () => {
  it("renders the dashboard sidebar and panels", async () => {
    const { default: AccountPage, generateMetadata } = await import("@/app/account/page");
    const meta = await generateMetadata();
    expect(meta.robots).toEqual({ index: false, follow: false });
    render(await AccountPage());
    expect(screen.getByRole("navigation", { name: "Account dashboard" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Privacy" })).toHaveAttribute("href", "#privacy");
    expect(screen.getByTestId("purchase-flow")).toHaveTextContent("account:none");
    expect(screen.getByTestId("subscriptions")).toBeInTheDocument();
    expect(screen.getByTestId("messages")).toBeInTheDocument();
    expect(screen.getByTestId("engagement")).toBeInTheDocument();
    expect(screen.getByTestId("privacy")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /shipping quote/i })).toHaveAttribute("href", "/quote");
  });

  it("renders the wishlist page", async () => {
    const { default: WishlistPage, generateMetadata } = await import("@/app/account/wishlist/page");
    const meta = await generateMetadata();
    expect(meta.robots).toEqual({ index: false, follow: false });
    render(await WishlistPage());
    expect(screen.getByTestId("wishlist-view")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Account dashboard" })).toHaveAttribute("href", "/account");
  });
});

describe("state pages", () => {
  it("reloads from the offline page", async () => {
    const reload = vi.fn();
    vi.stubGlobal("location", { reload, href: "https://shop.example.test/offline" });
    const { default: OfflinePage } = await import("@/app/offline/page");
    render(<OfflinePage />);
    expect(screen.getByRole("heading", { name: "You are offline" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(reload).toHaveBeenCalledOnce();
    expect(screen.getByRole("link", { name: "Return home" })).toHaveAttribute("href", "/");
  });

  it("calls reset from the error page", async () => {
    const reset = vi.fn();
    const { default: ErrorPage } = await import("@/app/error");
    render(<ErrorPage error={new Error("boom")} reset={reset} />);
    expect(screen.getByRole("alert")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(reset).toHaveBeenCalledOnce();
  });

  it("renders the localized not-found page", async () => {
    const { default: NotFound } = await import("@/app/not-found");
    render(await NotFound());
    expect(screen.getByRole("heading", { name: "We could not find that page" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Search the collection" })).toHaveAttribute("href", "/search");
  });
});

describe("cart add route", () => {
  function post(body?: BodyInit, contentType = "application/x-www-form-urlencoded"): Request {
    return new Request("https://shop.example.test/cart/add", { method: "POST", body, headers: contentType ? { "Content-Type": contentType } : {} });
  }

  it("adds a valid variant and redirects with the added flag", async () => {
    const { POST } = await import("@/app/cart/add/route");
    const response = await POST(post("product_id=sku-1&quantity=2&slot_start=slot-1"));
    expect(response.status).toBe(303);
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart?added=1");
    expect(cartServer.addVariantToCart).toHaveBeenCalledWith("sku-1", 2, "slot-1");
  });

  it("clamps quantity and ignores placeholder variants", async () => {
    const { POST } = await import("@/app/cart/add/route");
    let response = await POST(post("product_id=sku-1&quantity=500"));
    expect(cartServer.addVariantToCart).toHaveBeenLastCalledWith("sku-1", 99, undefined);
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart?added=1");

    response = await POST(post("product_id=undefined&quantity=1"));
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart");
    expect(cartServer.addVariantToCart).toHaveBeenCalledOnce();
  });

  it("rejects oversized ids and reports mutation failures", async () => {
    const { POST } = await import("@/app/cart/add/route");
    const longId = "a".repeat(121);
    let response = await POST(post(`product_id=${longId}`));
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart");

    response = await POST(post("product_id=sku-1&slot_start=" + "b".repeat(121)));
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart");

    cartServer.addVariantToCart.mockRejectedValueOnce(new Error("boom"));
    response = await POST(post("product_id=sku-1"));
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart?add=failed");
  });

  it("treats malformed form data as an empty mutation", async () => {
    const { POST } = await import("@/app/cart/add/route");
    const response = await POST(post("not-a-form", "application/json"));
    expect(response.headers.get("location")).toBe("https://shop.example.test/cart");
    expect(cartServer.addVariantToCart).not.toHaveBeenCalled();
  });
});

describe("vendor page", () => {
  const vendor = { id: "v-1", name: "North Loom", slug: "north-loom", suspended: false, description: "Independent studio", product_count: 3 };

  beforeEach(() => {
    requestLocale.mockResolvedValue("en");
  });

  it("renders metadata and products for an active vendor", async () => {
    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => vendor), listProducts: vi.fn(async () => products.slice(0, 2)) });
    const { default: VendorPage, generateMetadata } = await import("@/app/vendor/[slug]/page");
    const meta = await generateMetadata({ params: Promise.resolve({ slug: "north-loom" }) });
    expect(meta).toMatchObject({ title: "North Loom", description: "Independent studio" });
    render(await VendorPage({ params: Promise.resolve({ slug: "north-loom" }) }));
    expect(screen.getByRole("heading", { name: "North Loom" })).toBeInTheDocument();
    expect(screen.getByTestId("product-grid")).toHaveTextContent("2");
  });

  it("renders a suspended vendor without loading products", async () => {
    const listProducts = vi.fn();
    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => ({ ...vendor, suspended: true })), listProducts });
    const { default: VendorPage } = await import("@/app/vendor/[slug]/page");
    render(await VendorPage({ params: Promise.resolve({ slug: "north-loom" }) }));
    expect(screen.getByRole("heading", { name: "Vendor suspended" })).toBeInTheDocument();
    expect(listProducts).not.toHaveBeenCalled();
  });

  it("renders an empty catalog state for a vendor without products", async () => {
    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => vendor), listProducts: vi.fn(async () => []) });
    const { default: VendorPage } = await import("@/app/vendor/[slug]/page");
    render(await VendorPage({ params: Promise.resolve({ slug: "north-loom" }) }));
    expect(screen.getByRole("heading", { name: "No products yet" })).toBeInTheDocument();
  });

  it("shows the catalog error state for vendor and product failures", async () => {
    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => { throw new CatalogRequestError(503, "vendor down"); }) });
    const { default: VendorPage } = await import("@/app/vendor/[slug]/page");
    let { unmount } = render(await VendorPage({ params: Promise.resolve({ slug: "north-loom" }) }));
    expect(screen.getByTestId("catalog-state")).toHaveTextContent("vendor down");
    unmount();

    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => vendor), listProducts: vi.fn(async () => { throw new CatalogRequestError(502, "products down"); }) });
    ({ unmount } = render(await VendorPage({ params: Promise.resolve({ slug: "north-loom" }) })));
    expect(screen.getByTestId("catalog-state")).toHaveTextContent("products down");
  });

  it("uses the not-found title and throws unexpected errors", async () => {
    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => { throw new Error("boom"); }) });
    const { default: VendorPage, generateMetadata } = await import("@/app/vendor/[slug]/page");
    expect((await generateMetadata({ params: Promise.resolve({ slug: "north-loom" }) })).title).toBe("Vendor not found");
    await expect(VendorPage({ params: Promise.resolve({ slug: "north-loom" }) })).rejects.toThrow("boom");

    catalogMock.mockReturnValue({ getVendor: vi.fn(async () => undefined) });
    await expect(generateMetadata({ params: Promise.resolve({ slug: "missing" }) })).resolves.toMatchObject({ title: "Vendor not found" });
    await expect(VendorPage({ params: Promise.resolve({ slug: "missing" }) })).rejects.toThrow("NEXT_NOT_FOUND");
    expect(notFound).toHaveBeenCalled();
  });
});
