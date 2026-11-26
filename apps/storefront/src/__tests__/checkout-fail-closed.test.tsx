import "@testing-library/jest-dom/vitest";
import { createElement, type ComponentType } from "react";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { resolvePurchaseMode } from "../components/PurchaseFlow";

describe("production checkout fail-closed mode", () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllEnvs();
  });

  it("resolves production without an API source to unavailable", () => {
    expect(resolvePurchaseMode(undefined, "production", undefined)).toEqual({ live: false, fixture: false, unavailable: true });
    expect(resolvePurchaseMode(undefined, "production", "0")).toEqual({ live: false, fixture: false, unavailable: true });
    expect(resolvePurchaseMode(undefined, "production", "1")).toEqual({ live: false, fixture: true, unavailable: false });
    expect(resolvePurchaseMode("api", "production", undefined)).toEqual({ live: true, fixture: false, unavailable: false });
    expect(resolvePurchaseMode(undefined, "test", undefined)).toEqual({ live: false, fixture: true, unavailable: false });
  });

  it("does not prefill buyer data, invent an order number, or allow placing an order", async () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_PURCHASE_SOURCE", "");
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_ALLOW_FIXTURE", "");

    const { PurchaseFlow } = await import("../components/PurchaseFlow");
    const Flow = PurchaseFlow as unknown as ComponentType<{ initialStep?: string }>;

    window.history.replaceState({}, "", "/checkout");
    render(createElement(Flow, { initialStep: "checkout" }));

    expect(screen.getByTestId("checkout-unavailable")).toHaveTextContent("Checkout is unavailable in this environment");
    expect(screen.getByRole("button", { name: "Place order" })).toBeDisabled();
    expect(screen.getByLabelText("Email")).toHaveValue("");
    expect(screen.queryByDisplayValue("buyer@example.com")).not.toBeInTheDocument();
    expect(screen.queryByDisplayValue("Nguyen Van A")).not.toBeInTheDocument();
    cleanup();

    window.history.replaceState({}, "", "/order-result");
    render(createElement(Flow, { initialStep: "order-result" }));
    expect(screen.queryByText("SO-100001")).not.toBeInTheDocument();
  });
});
