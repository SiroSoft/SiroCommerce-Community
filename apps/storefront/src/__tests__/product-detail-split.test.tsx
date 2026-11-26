import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import { LocaleProvider } from "@/lib/i18n-client";
import { products } from "@/services/catalog";
import { asDetailVariant } from "@/theme/layouts";
import { sanitizeStorefrontConfig } from "@/services/storefront-config";

afterEach(cleanup);

const product = {
  ...products[0]!,
  delivery: { mode: "physical" as const, state: "available" as const, message: "Dispatches within two days." },
};

describe("split-buybox product detail variant", () => {
  it("accepts the config value and keeps existing defaults intact", () => {
    expect(asDetailVariant("split-buybox")).toBe("split-buybox");
    expect(asDetailVariant("unknown")).toBe("editorial");
    const config = sanitizeStorefrontConfig({ presentation: { product_detail_variant: "split-buybox", product_card_variant: "spotlight" } });
    expect(config.presentation.productDetailVariant).toBe("split-buybox");
    expect(config.presentation.productCardVariant).toBe("spotlight");
  });

  it("renders description, delivery, and review link inside the buybox accordion", () => {
    const { container } = render(<LocaleProvider locale="en"><ProductDecisionPanel product={product} variant="split-buybox" /></LocaleProvider>);
    expect(container.querySelector(".sf-sec-product-accordion")).not.toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: product.name })).toBeInTheDocument();
    expect(screen.getByText("Description")).toBeInTheDocument();
    expect(screen.getByText(product.description)).toBeInTheDocument();
    expect(screen.getByText("Delivery")).toBeInTheDocument();
    expect(screen.getByText("Dispatches within two days.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "What buyers say" })).toHaveAttribute("href", "#reviews-title");
    expect(container.querySelector(".detail-info > .description")).toBeNull();
  });

  it("keeps the default editorial layout free of the accordion", () => {
    const { container } = render(<LocaleProvider locale="en"><ProductDecisionPanel product={product} /></LocaleProvider>);
    expect(container.querySelector(".sf-sec-product-accordion")).toBeNull();
    expect(container.querySelector(".description")).not.toBeNull();
  });
});
