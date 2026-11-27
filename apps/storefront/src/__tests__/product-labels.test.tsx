import "@testing-library/jest-dom/vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getSlotRegistry, resetSlotRegistry, SlotProvider } from "@/slots";
import { PdpTitleLabels, ProductCardLabels } from "@/plugins/product-labels/index";
import {
  PRODUCT_LABELS_SLOT_ID,
  registerProductLabels,
} from "@/plugins/product-labels/register";

const ENVELOPE = {
  success: true,
  message: "Product labels",
  data: {
    product_id: "p1",
    product_slug: "silk-slip-dress",
    labels: [
      { rule_key: "new-rule", text: "New", kind: "new", priority: 90 },
      { rule_key: "sale-rule", text: "Sale", kind: "sale", priority: 100 },
    ],
  },
};

function fetchMockFor(payload: unknown, ok = true) {
  return vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => ({
    ok,
    json: async () => payload,
    url: String(input),
  }));
}

describe("product-labels slot components", () => {
  beforeEach(() => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_STORE_ID", "default");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CHANNEL_ID", "web");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_LOCALE", "en");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_CURRENCY", "VND");
    resetSlotRegistry();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
    resetSlotRegistry();
  });

  it("registers both slots with the extension id and priority 100", () => {
    registerProductLabels();
    const registry = getSlotRegistry();
    expect(registry["product-card"]).toHaveLength(1);
    expect(registry["product-card"]?.[0]?.id).toBe(PRODUCT_LABELS_SLOT_ID);
    expect(registry["product-card"]?.[0]?.priority).toBe(100);
    expect(registry["pdp.title"]).toHaveLength(1);
    expect(registry["pdp.title"]?.[0]?.id).toBe(PRODUCT_LABELS_SLOT_ID);
    expect(registry["pdp.title"]?.[0]?.priority).toBe(100);
  });

  it("renders card badges top-right once the endpoint answers", async () => {
    const fetchMock = fetchMockFor(ENVELOPE);
    vi.stubGlobal("fetch", fetchMock);
    render(
      <SlotProvider registry={getSlotRegistry()}>
        <ProductCardLabels data={{ productId: "p1", productSlug: "silk-slip-dress" }} />
      </SlotProvider>,
    );
    expect(screen.queryByTestId("product-labels-card")).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId("product-labels-card")).toBeInTheDocument());
    expect(screen.getByTestId("product-label-new-rule")).toHaveTextContent("New");
    expect(screen.getByTestId("product-label-sale-rule")).toHaveTextContent("Sale");
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const url = String(fetchMock.mock.calls[0]?.[0] ?? "");
    expect(url).toContain("/api/ext/siro/product-labels/public/labels?product_slug=silk-slip-dress");
  });

  it("renders PDP badges inline under the title", async () => {
    vi.stubGlobal("fetch", fetchMockFor(ENVELOPE));
    render(
      <SlotProvider registry={getSlotRegistry()}>
        <PdpTitleLabels data={{ productId: "p1", productSlug: "silk-slip-dress", category: "dresses" }} />
      </SlotProvider>,
    );
    await waitFor(() => expect(screen.getByTestId("product-labels-pdp")).toBeInTheDocument());
    expect(screen.getByTestId("product-label-new-rule")).toHaveTextContent("New");
  });

  it("maps best_seller and custom kinds onto the shared Badge primitive", async () => {
    vi.stubGlobal(
      "fetch",
      fetchMockFor({
        success: true,
        data: {
          product_id: "p1",
          product_slug: "silk-slip-dress",
          labels: [
            { rule_key: "top-rule", text: "Best Seller", kind: "best_seller", priority: 80 },
            { rule_key: "vip-rule", text: "Limited", kind: "custom", priority: 70 },
          ],
        },
      }),
    );
    render(
      <SlotProvider registry={getSlotRegistry()}>
        <PdpTitleLabels data={{ productId: "p1", productSlug: "silk-slip-dress" }} />
      </SlotProvider>,
    );
    await waitFor(() => expect(screen.getByTestId("product-label-top-rule")).toBeInTheDocument());
    expect(screen.getByTestId("product-label-top-rule")).toHaveTextContent("Best Seller");
    // The shared Badge renders data-kind with its own kind spelling.
    expect(screen.getByTestId("product-label-top-rule").querySelector("[data-kind]")).toHaveAttribute(
      "data-kind",
      "best-seller",
    );
    expect(screen.getByTestId("product-label-vip-rule")).toHaveTextContent("Limited");
  });

  it("renders nothing when the product has no labels", async () => {
    vi.stubGlobal(
      "fetch",
      fetchMockFor({ success: true, data: { product_id: "p1", product_slug: "plain", labels: [] } }),
    );
    const { container } = render(
      <SlotProvider registry={getSlotRegistry()}>
        <ProductCardLabels data={{ productId: "p1", productSlug: "plain" }} />
      </SlotProvider>,
    );
    await waitFor(() => expect(screen.queryByTestId("product-labels-card")).not.toBeInTheDocument());
    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when the endpoint fails", async () => {
    vi.stubGlobal("fetch", fetchMockFor({ error: "boom" }, false));
    render(
      <SlotProvider registry={getSlotRegistry()}>
        <PdpTitleLabels data={{ productId: "p1", productSlug: "silk-slip-dress" }} />
      </SlotProvider>,
    );
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByTestId("product-labels-pdp")).not.toBeInTheDocument();
  });

  it("does not fetch without a product slug", () => {
    const fetchMock = fetchMockFor(ENVELOPE);
    vi.stubGlobal("fetch", fetchMock);
    render(
      <SlotProvider registry={getSlotRegistry()}>
        <ProductCardLabels data={{ productId: "p1" }} />
      </SlotProvider>,
    );
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.queryByTestId("product-labels-card")).not.toBeInTheDocument();
  });
});
