import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import manifest from "../app/manifest";
import { PwaRuntime } from "../components/PwaRuntime";
import { isSensitiveCachePath, readCartRecovery, recoveryMaxBytes, recoveryTtlMs } from "../lib/pwa";

describe("storefront PWA boundary", () => {
  afterEach(() => { cleanup(); vi.restoreAllMocks(); });

  it("publishes an installable manifest with local icons", () => {
    const value = manifest();
    expect(value.display).toBe("standalone");
    expect(value.start_url).toBe("/");
    expect(value.icons?.map((icon) => icon.src)).toEqual(["/icon-192.svg", "/icon-512.svg"]);
    expect(value.name).toBe("SIRO Commerce");
  });

  it("shows a mobile-safe offline shell warning without commerce claims", () => {
    Object.defineProperty(window, "innerWidth", { configurable: true, value: 320 });
    vi.spyOn(window.navigator, "onLine", "get").mockReturnValue(false);
    render(<PwaRuntime />);
    expect(screen.getByRole("status")).toHaveTextContent(/you are offline/i);
    expect(screen.getByRole("status")).toHaveTextContent(/prices, stock, checkout, and order status/i);
  });

  it("clears the offline state after connectivity returns for revalidation", () => {
    vi.spyOn(window.navigator, "onLine", "get").mockReturnValue(false);
    render(<PwaRuntime />);
    expect(screen.getByRole("status")).toBeInTheDocument();
    vi.spyOn(window.navigator, "onLine", "get").mockReturnValue(true);
    fireEvent(window, new Event("online"));
    expect(screen.queryByText(/you are offline/i)).not.toBeInTheDocument();
  });

  it("accepts only bounded, fresh cart intent for recovery", () => {
    const now = Date.now();
    const product = { product_id: "p", slug: "p", name: "P", quantity: 1, unit_price: { amount_minor: 1, currency: "VND" }, savedAt: now };
    expect(readCartRecovery(JSON.stringify(product), now)).toMatchObject({ product_id: "p" });
    expect(readCartRecovery(JSON.stringify({ ...product, savedAt: now - recoveryTtlMs - 1 }), now)).toBeUndefined();
    expect(readCartRecovery("x".repeat(recoveryMaxBytes + 1), now)).toBeUndefined();
  });

  it("keeps the persisted cart count equal to the total line quantity", () => {
    const now = Date.now();
    const product = { product_id: "p", slug: "p", name: "P", quantity: 2, cartQuantity: 5, unit_price: { amount_minor: 1, currency: "VND" }, savedAt: now };
    expect(readCartRecovery(JSON.stringify(product), now)).toMatchObject({ quantity: 2, cartQuantity: 5 });
    expect(readCartRecovery(JSON.stringify({ ...product, cartQuantity: 0 }), now)).toBeUndefined();
  });

  it("excludes sensitive and commerce routes from runtime caches", () => {
    expect(isSensitiveCachePath("/api/catalog")).toBe(true);
    expect(isSensitiveCachePath("/checkout")).toBe(true);
    expect(isSensitiveCachePath("/account/orders")).toBe(true);
    expect(isSensitiveCachePath("/product/shirt")).toBe(false);
  });
});
