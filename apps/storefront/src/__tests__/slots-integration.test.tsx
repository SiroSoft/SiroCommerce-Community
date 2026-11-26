import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";
import {
  Slot,
  SlotProvider,
  createSlotRegistry,
  defaultSlotRegistry,
} from "@/slots";
import type { SlotComponent, SlotRegistry } from "@/slots";

vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock("next/image", () => ({
  default: (props: Record<string, unknown>) => <img {...props} />,
}));

const notFound = vi.hoisted(() => vi.fn(() => { throw new Error("NEXT_NOT_FOUND"); }));
vi.mock("next/navigation", () => ({ notFound }));

vi.mock("@/lib/request-locale", () => ({
  requestLocale: vi.fn(async () => "en" as const),
  requestScope: vi.fn(async () => ({ store_id: "store", channel_id: "web", locale: "en", currency: "USD" })),
  requestCurrency: vi.fn(async () => "USD"),
}));

vi.mock("@/lib/cart-server", () => ({
  loadCartSnapshot: vi.fn(async () => undefined),
}));

vi.mock("@/lib/locale", () => ({
  siteUrl: (path = "") => `https://shop.example.test${path}`,
  storefrontLocale: () => "en",
}));

vi.mock("@/lib/structured-data", () => ({
  jsonLd: () => "{}",
  priceForSchema: (price: number) => String(price),
}));

const catalogMock = vi.hoisted(() => {
  const product = {
    id: "p-1",
    name: "Test product",
    slug: "test-product",
    status: "active",
    productType: "simple",
    capability: "available",
    category: "apparel",
    categoryName: "Apparel",
    description: "A test product",
    price: 1000,
    currency: "USD",
    image: "/catalog/product.svg",
    imageAlt: "Test product",
    rating: 0,
    reviewCount: 0,
    tags: [],
    attributes: [],
  };
  const catalog = vi.fn(() => ({
    getProduct: vi.fn(async () => product),
    listProducts: vi.fn(async () => [product]),
  }));
  class CatalogRequestError extends Error {
    constructor(readonly status: number, message: string) {
      super(message);
      this.name = "CatalogRequestError";
    }
  }
  return { catalog, products: [product], CatalogRequestError };
});

vi.mock("@/services/catalog", () => ({
  catalog: catalogMock.catalog,
  products: catalogMock.products,
  CatalogRequestError: catalogMock.CatalogRequestError,
}));

vi.mock("@/services/recommendations", () => ({
  resolveRecommendations: () => ({ products: [], source: "fallback" }),
}));

vi.mock("@/services/storefront-config", () => ({
  getConfiguredStorefrontConfig: vi.fn(async () => ({
    purchase: { quoteMode: "off" },
    presentation: {
      productCardVariant: "standard",
      productDetailVariant: "editorial",
      productListVariant: "grid",
      purchaseVariant: "standard",
    },
  })),
}));

vi.mock("@/components/PurchaseFlow", () => ({
  PurchaseFlow: ({ initialStep }: { initialStep?: string }) => <div data-testid="purchase-flow">{initialStep}</div>,
}));
vi.mock("@/components/PurchaseFlowLazy", () => ({
  PurchaseFlowLazy: ({ initialStep }: { initialStep?: string }) => <div data-testid="purchase-flow-lazy">{initialStep}</div>,
}));
vi.mock("@/components/ProductGrid", () => ({
  ProductGrid: () => <div data-testid="product-grid" />,
}));
vi.mock("@/components/CatalogState", () => ({
  CatalogState: () => <div data-testid="catalog-state" />,
}));
vi.mock("@/components/ProductDecisionPanel", () => ({
  ProductDecisionPanel: () => <div data-testid="product-decision" />,
}));
vi.mock("@/components/ProductGallery", () => ({
  ProductGallery: () => <div data-testid="product-gallery" />,
}));
vi.mock("@/components/ProductViewTracker", () => ({
  ProductViewTracker: () => <div data-testid="product-view-tracker" />,
}));
vi.mock("@/components/DeliveryEstimator", () => ({
  DeliveryEstimator: () => <div data-testid="delivery-estimator" />,
}));
vi.mock("@/components/SubscriptionExperience", () => ({
  SubscriptionExperience: () => <div data-testid="subscriptions" />,
}));
vi.mock("@/components/Breadcrumbs", () => ({
  Breadcrumbs: () => <div data-testid="breadcrumbs" />,
}));
vi.mock("@/components/BuyerEngagement", () => ({
  BuyerEngagement: () => <div data-testid="engagement" />,
  ReviewSummary: () => <div data-testid="reviews" />,
  WishlistButton: () => <div data-testid="wishlist" />,
}));
vi.mock("@/components/AccountPrivacyControls", () => ({
  AccountPrivacyControls: () => <div data-testid="privacy" />,
}));
vi.mock("@/components/MessageHistory", () => ({
  MessageHistory: () => <div data-testid="messages" />,
}));
vi.mock("@/components/FinancialDocumentsPanel", () => ({
  FinancialDocumentsPanel: () => <div data-testid="financial-documents" />,
}));
vi.mock("@/components/lazy/LazyBuyerAssistant", () => ({
  LazyBuyerAssistant: () => <div data-testid="buyer-assistant" />,
}));
vi.mock("@/components/lazy/LazyProductFbt", () => ({
  LazyProductFbt: () => <div data-testid="product-fbt" />,
}));
vi.mock("@/components/lazy/LazyRecentlyViewed", () => ({
  LazyRecentlyViewed: () => <div data-testid="recently-viewed" />,
}));
vi.mock("@/components/lazy/LazyRecommendationSurface", () => ({
  LazyRecommendationSurface: () => <div data-testid="recommendations" />,
}));

function marker(label: string): SlotComponent {
  function Marker() {
    return <span data-slot-item data-testid={`slot-${label}`}>{label}</span>;
  }
  return Marker;
}

const Failing: SlotComponent = () => {
  throw new Error("slot failure");
};

function renderWithRegistry(node: ReactNode, registry: SlotRegistry) {
  return render(<SlotProvider registry={registry}>{node}</SlotProvider>);
}

function follows(anchor: Element, node: Element): boolean {
  return (anchor.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

function LayoutHarness({ children }: { children: ReactNode }) {
  return (
    <>
      <header data-testid="site-header" />
      <Slot name="header" />
      <main data-testid="main-content">{children}</main>
      <Slot name="footer" />
      <footer data-testid="site-footer" />
    </>
  );
}

afterEach(() => {
  vi.clearAllMocks();
});

describe("storefront slot integration", () => {
  it("exports an empty default registry", () => {
    expect(defaultSlotRegistry).toEqual({});
    expect(createSlotRegistry()).toEqual({});
  });

  it("renders header and footer slots at the layout boundaries in priority order", () => {
    const registry = createSlotRegistry({
      header: [
        { id: "header-late", priority: 20, component: marker("header-late") },
        { id: "header-early", priority: 10, component: marker("header-early") },
      ],
      footer: [{ id: "footer", priority: 10, component: marker("footer") }],
    });

    const { container } = renderWithRegistry(<LayoutHarness><p>Page</p></LayoutHarness>, registry);

    const items = Array.from(container.querySelectorAll("[data-slot-item]")).map((item) => item.textContent);
    expect(items).toEqual(["header-early", "header-late", "footer"]);
    expect(follows(screen.getByTestId("site-header"), screen.getByTestId("slot-header-early"))).toBe(true);
    expect(follows(screen.getByTestId("slot-header-late"), screen.getByTestId("main-content"))).toBe(true);
    expect(follows(screen.getByTestId("main-content"), screen.getByTestId("slot-footer"))).toBe(true);
    expect(follows(screen.getByTestId("slot-footer"), screen.getByTestId("site-footer"))).toBe(true);
  });

  it("does not add wrappers when the default registry is empty", () => {
    const { container } = renderWithRegistry(
      <LayoutHarness><p>Core content</p></LayoutHarness>,
      defaultSlotRegistry,
    );

    expect(container.querySelectorAll("[data-slot]")).toHaveLength(0);
    expect(container.querySelectorAll("[data-slot-item]")).toHaveLength(0);
    expect(screen.getByTestId("site-header").nextSibling).toBe(screen.getByTestId("main-content"));
    expect(screen.getByTestId("main-content").nextSibling).toBe(screen.getByTestId("site-footer"));
  });

  it("renders the cart slot after the purchase flow in priority order", async () => {
    const { default: CartPage } = await import("@/app/cart/page");
    const registry = createSlotRegistry({
      cart: [
        { id: "late", priority: 20, component: marker("cart-late") },
        { id: "early", priority: 10, component: marker("cart-early") },
      ],
    });

    const { container } = renderWithRegistry(await CartPage({}), registry);
    const items = Array.from(container.querySelectorAll("[data-slot-item]")).map((item) => item.textContent);

    expect(items).toEqual(["cart-early", "cart-late"]);
    expect(follows(screen.getByTestId("purchase-flow"), screen.getByTestId("slot-cart-early"))).toBe(true);
  });

  it("renders the checkout slot after the lazy purchase flow", async () => {
    const { default: CheckoutPage } = await import("@/app/checkout/page");
    const registry = createSlotRegistry({
      checkout: [{ id: "checkout", priority: 10, component: marker("checkout") }],
    });

    renderWithRegistry(await CheckoutPage(), registry);

    expect(follows(screen.getByTestId("purchase-flow-lazy"), screen.getByTestId("slot-checkout"))).toBe(true);
  });

  it("renders the pdp slot inside the product article", async () => {
    const { default: ProductDetail } = await import("@/app/product/[slug]/page");
    const registry = createSlotRegistry({
      pdp: [{ id: "pdp", priority: 10, component: marker("pdp") }],
    });

    const { container } = renderWithRegistry(
      await ProductDetail({ params: Promise.resolve({ slug: "test-product" }) }),
      registry,
    );

    const item = screen.getByTestId("slot-pdp");
    expect(container.querySelector("article.detail")).toContainElement(item);
    expect(follows(container.querySelector(".detail-supporting")!, item)).toBe(true);
  });

  it("renders the my-account slot inside the account main column", async () => {
    const { default: AccountPage } = await import("@/app/account/page");
    const registry = createSlotRegistry({
      "my-account": [{ id: "account", priority: 10, component: marker("account") }],
    });

    renderWithRegistry(await AccountPage(), registry);

    const item = screen.getByTestId("slot-account");
    expect(item.closest(".account-main")).not.toBeNull();
    expect(follows(screen.getByTestId("privacy"), item)).toBe(true);
  });

  it("renders the order-status slot after the financial documents", async () => {
    const { default: OrderResultPage } = await import("@/app/order-result/page");
    const registry = createSlotRegistry({
      "order-status": [{ id: "order-status", priority: 10, component: marker("order-status") }],
    });

    renderWithRegistry(await OrderResultPage(), registry);

    expect(follows(screen.getByTestId("financial-documents"), screen.getByTestId("slot-order-status"))).toBe(true);
  });

  it("keeps the page rendered when one slot component throws", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const { default: CartPage } = await import("@/app/cart/page");
    const registry = createSlotRegistry({
      cart: [
        { id: "alpha", priority: 10, component: marker("alpha") },
        { id: "broken", priority: 20, component: Failing },
        { id: "omega", priority: 30, component: marker("omega") },
      ],
    });

    const { container } = renderWithRegistry(await CartPage({}), registry);

    expect(screen.getByTestId("purchase-flow")).toBeInTheDocument();
    expect(screen.getAllByTestId(/^slot-(alpha|omega)$/)).toHaveLength(2);
    expect(container.querySelector('[data-testid="slot-broken"]')).toBeNull();
  });
});
