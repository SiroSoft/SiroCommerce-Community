import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ProductFbt } from "@/components/ProductFbt";
import { RecentlyViewed } from "@/components/RecentlyViewed";
import { DeliveryEstimator } from "@/components/DeliveryEstimator";
import { readRecentlyViewedProducts, recentlyViewedProductsKey, recentlyViewedProductsLimit } from "@/lib/recently-viewed";

const current = { slug: "current-piece", name: "Current piece", image: "/current.svg", imageAlt: "Current piece", priceMinor: 4500, currency: "USD" };

function suggestion(index: number) {
  return {
    variant_id: `v-${index}`,
    product_id: `p-${index}`,
    name: `Suggestion ${index}`,
    slug: `suggestion-${index}`,
    price_minor: 1000 * index,
    currency: "USD",
    image: `/suggestion-${index}.svg`,
    image_alt: `Suggestion ${index} image`,
  };
}

function mockFetch(body: unknown, ok = true) {
  const mock = vi.fn(async () => new Response(JSON.stringify(body), { status: ok ? 200 : 500, headers: { "Content-Type": "application/json" } }));
  vi.stubGlobal("fetch", mock);
  return mock;
}

describe("product decision extras", () => {
  beforeEach(() => {
    window.localStorage.clear();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_ETA", "");
  });

  afterEach(() => {
    cleanup();
    window.localStorage.clear();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  it("does not call affinities without a usable variant id", async () => {
    const fetchMock = mockFetch({ success: true, data: [] });
    const { container } = render(<ProductFbt variantIds={[]} />);
    await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
    expect(container.querySelector(".sf-fbt")).toBeNull();
  });

  it("renders at most four affinity cards with links and lazy images", async () => {
    const fetchMock = mockFetch({ success: true, data: [suggestion(1), suggestion(2), suggestion(3), suggestion(4), suggestion(5)] });
    const { container } = render(<ProductFbt variantIds={["current-variant"]} />);
    expect(await screen.findByRole("heading", { level: 2, name: "Frequently bought together" })).toBeInTheDocument();
    const section = container.querySelector(".sf-fbt") as HTMLElement;
    const links = within(section).getAllByRole("link");
    expect(links).toHaveLength(4);
    expect(links[0]).toHaveAttribute("href", "/product/suggestion-1");
    const image = within(section).getAllByRole("img")[0]!;
    expect(image).toHaveAttribute("alt", "Suggestion 1 image");
    expect(image).toHaveAttribute("loading", "lazy");
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining("/api/products/current-variant/affinities"), expect.objectContaining({ cache: "no-store" }));
  });

  it("stays fail-closed on empty and failed affinity responses", async () => {
    mockFetch({ success: true, data: [] });
    const empty = render(<ProductFbt variantIds={["v-1"]} />);
    await waitFor(() => expect(empty.container.querySelector(".sf-fbt")).toBeNull());
    empty.unmount();
    mockFetch({ success: false, data: null }, false);
    const failed = render(<ProductFbt variantIds={["v-1"]} />);
    await waitFor(() => expect(failed.container.querySelector(".sf-fbt")).toBeNull());
  });

  it("remembers the viewed product, excludes it from the strip, and keeps eight unique items", async () => {
    const entries = Array.from({ length: 10 }, (_, index) => ({ ...current, slug: `recent-${index}`, name: `Recent ${index}`, viewedAt: Date.now() - index }));
    window.localStorage.setItem(recentlyViewedProductsKey, JSON.stringify(entries));
    render(<RecentlyViewed product={current} />);
    expect(await screen.findByRole("heading", { level: 2, name: "Recently viewed" })).toBeInTheDocument();
    expect(screen.queryByText("Current piece")).not.toBeInTheDocument();
    expect(screen.getAllByRole("link")).toHaveLength(recentlyViewedProductsLimit - 1);
    const stored = readRecentlyViewedProducts();
    expect(stored).toHaveLength(recentlyViewedProductsLimit);
    expect(stored[0]?.slug).toBe("current-piece");
    expect(new Set(stored.map((item) => item.slug)).size).toBe(stored.length);
  });

  it("renders nothing when guest storage has no usable history", async () => {
    window.localStorage.setItem(recentlyViewedProductsKey, "{not-json");
    const { container } = render(<RecentlyViewed product={current} />);
    await waitFor(() => expect(container.querySelector(".sf-recent")).toBeNull());
    expect(window.localStorage.getItem(recentlyViewedProductsKey)).not.toBe("{not-json");
  });

  it("shows only checkout guidance when no shipping configuration exists", () => {
    render(<DeliveryEstimator priceMinor={100000} currency="VND" />);
    expect(screen.getByRole("heading", { level: 2, name: "Delivery estimate" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Province/City" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "District" })).toBeInTheDocument();
    expect(screen.getByText("Shipping is calculated at checkout.")).toBeInTheDocument();
    expect(screen.queryByText(/estimated shipping/i)).not.toBeInTheDocument();
  });

  it("shows configured estimates after selecting a delivery area", () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR", "30000");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR", "500000");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_ETA", "2-4 days");
    render(<DeliveryEstimator priceMinor={100000} currency="VND" />);
    fireEvent.change(screen.getByRole("combobox", { name: "Province/City" }), { target: { value: "Hà Nội" } });
    fireEvent.change(screen.getByRole("combobox", { name: "District" }), { target: { value: "Hoàn Kiếm" } });
    expect(screen.getByText(/Estimated shipping:/)).toBeInTheDocument();
    expect(screen.getByText(/2-4 days/)).toBeInTheDocument();
    expect(screen.getByText(/This is an estimate/)).toBeInTheDocument();
  });

  it("marks free shipping as an estimate when the configured threshold is met", () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR", "30000");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR", "500000");
    render(<DeliveryEstimator priceMinor={600000} currency="VND" />);
    fireEvent.change(screen.getByRole("combobox", { name: "Province/City" }), { target: { value: "Hà Nội" } });
    fireEvent.change(screen.getByRole("combobox", { name: "District" }), { target: { value: "Ba Đình" } });
    expect(screen.getByText("Free shipping (estimate)")).toBeInTheDocument();
    expect(screen.queryByText(/Estimated shipping:/)).not.toBeInTheDocument();
  });
});
