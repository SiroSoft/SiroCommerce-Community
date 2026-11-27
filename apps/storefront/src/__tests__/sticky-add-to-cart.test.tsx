import "@testing-library/jest-dom/vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getSlotRegistry, resetSlotRegistry } from "@/slots";
import { StickyAddToCart } from "@/plugins/sticky-add-to-cart";
import { registerStickyAddToCart } from "@/plugins/sticky-add-to-cart/register";

const API_BASE = "http://localhost:8092";

function mockIntersectionObserver() {
  const observed: Element[] = [];
  let callback: IntersectionObserverCallback = () => undefined;
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      constructor(next: IntersectionObserverCallback) {
        callback = next;
      }
      observe(node: Element) {
        observed.push(node);
      }
      disconnect() {
        observed.length = 0;
      }
      unobserve() {
        /* noop */
      }
    },
  );
  return {
    /** Simulate the anchor scrolling out of view upward (past the main ATC). */
    triggerScrolledPast() {
      callback(
        observed.map(
          (target) =>
            ({
              target,
              isIntersecting: false,
              boundingClientRect: { top: -10 },
            }) as IntersectionObserverEntry,
        ),
        {} as IntersectionObserver,
      );
    },
    triggerVisible() {
      callback(
        observed.map(
          (target) =>
            ({
              target,
              isIntersecting: true,
              boundingClientRect: { top: 100 },
            }) as IntersectionObserverEntry,
        ),
        {} as IntersectionObserver,
      );
    },
  };
}

function mockMatchMedia(matches: boolean) {
  vi.stubGlobal(
    "matchMedia",
    vi.fn().mockImplementation(() => ({
      matches,
      media: "(max-width: 700px)",
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  );
}

interface FetchPlan {
  settings?: Record<string, unknown> | null;
  product?: Record<string, unknown> | null;
  fail?: boolean;
}

function mockFetch(plan: FetchPlan) {
  const fetchMock = vi.fn(async (url: string) => {
    if (plan.fail) throw new Error("network down");
    if (url.includes("/api/ext/siro/sticky-add-to-cart/public")) {
      return { ok: true, json: async () => ({ success: true, data: plan.settings }) };
    }
    if (url.includes("/api/products/")) {
      return { ok: true, json: async () => ({ success: true, data: plan.product }) };
    }
    return { ok: false, json: async () => ({}) };
  });
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

const ACTIVE_SETTINGS = { active: true, button_label: "Add to Cart now", show_price: true };
const PRODUCT = { name: "Test Lamp", price_minor: 250000, currency: "VND", status: "active" };

function renderBar(overrides: { settings?: Record<string, unknown> | null; product?: Record<string, unknown> | null; fail?: boolean; mobile?: boolean } = {}) {
  mockMatchMedia(overrides.mobile ?? false);
  const io = mockIntersectionObserver();
  mockFetch({
    settings: overrides.settings ?? ACTIVE_SETTINGS,
    product: overrides.product ?? PRODUCT,
    fail: overrides.fail,
  });
  render(<StickyAddToCart locale="en" data={{ productId: "prod-1", productSlug: "test-lamp", category: "lighting" }} />);
  return { io };
}

describe("sticky-add-to-cart slot component", () => {
  beforeEach(() => {
    process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL = API_BASE;
    resetSlotRegistry();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    delete process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL;
  });

  it("renders only the anchor until the shopper scrolls past the main ATC", async () => {
    const { io } = renderBar();
    expect(screen.getByTestId("sticky-add-to-cart-anchor")).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByTestId("sticky-add-to-cart")).not.toBeInTheDocument());
    io.triggerVisible();
    await waitFor(() => expect(screen.queryByTestId("sticky-add-to-cart")).not.toBeInTheDocument());
    io.triggerScrolledPast();
    await waitFor(() => expect(screen.getByTestId("sticky-add-to-cart")).toBeInTheDocument());
  });

  it("shows name, price and a form posting to /cart/add with the default variant", async () => {
    const { io } = renderBar();
    io.triggerScrolledPast();
    const bar = await screen.findByTestId("sticky-add-to-cart");
    expect(bar).toHaveTextContent("Test Lamp");
    expect(bar).toHaveTextContent("250");
    const button = screen.getByRole("button", { name: "Add to Cart now" });
    expect(button).toBeInTheDocument();
    const form = button.closest("form");
    expect(form).toHaveAttribute("method", "post");
    expect(form).toHaveAttribute("action", "/cart/add");
    expect(form?.querySelector('input[name="product_id"]')).toHaveAttribute("value", "prod-1");
    expect(form?.querySelector('input[name="quantity"]')).toHaveAttribute("value", "1");
  });

  it("stays hidden when the bar is inactive", async () => {
    const { io } = renderBar({ settings: { active: false, button_label: "", show_price: false } });
    io.triggerScrolledPast();
    await waitFor(() => expect(screen.queryByTestId("sticky-add-to-cart")).not.toBeInTheDocument());
  });

  it("stays hidden for a non-purchasable product", async () => {
    const { io } = renderBar({ product: { ...PRODUCT, status: "archived" } });
    io.triggerScrolledPast();
    await waitFor(() => expect(screen.queryByTestId("sticky-add-to-cart")).not.toBeInTheDocument());
  });

  it("stays hidden on mobile, where the core .mobile-add bar already serves", async () => {
    const { io } = renderBar({ mobile: true });
    io.triggerScrolledPast();
    await waitFor(() => expect(screen.queryByTestId("sticky-add-to-cart")).not.toBeInTheDocument());
  });

  it("omits the price when show_price is false", async () => {
    const { io } = renderBar({ settings: { ...ACTIVE_SETTINGS, show_price: false } });
    io.triggerScrolledPast();
    const bar = await screen.findByTestId("sticky-add-to-cart");
    expect(bar).toHaveTextContent("Test Lamp");
    expect(bar).not.toHaveTextContent("250");
    expect(screen.getByRole("button", { name: "Add to Cart now" })).toBeInTheDocument();
  });

  it("renders nothing when the settings/product fetch fails", async () => {
    const { io } = renderBar({ fail: true });
    io.triggerScrolledPast();
    await waitFor(() => expect(screen.queryByTestId("sticky-add-to-cart")).not.toBeInTheDocument());
  });

  it("registers on pdp.below-atc with the extension id and priority 100", () => {
    resetSlotRegistry();
    registerStickyAddToCart();
    const registrations = getSlotRegistry()["pdp.below-atc"] ?? [];
    expect(registrations).toHaveLength(1);
    expect(registrations[0]?.id).toBe("siro/sticky-add-to-cart");
    expect(registrations[0]?.priority).toBe(100);
  });
});
