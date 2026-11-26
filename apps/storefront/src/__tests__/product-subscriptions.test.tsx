import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import { products } from "@/services/catalog";
import { setCustomerAccessToken, createApiCustomer } from "@/services/customer";
import type { Product } from "@/types";

const plan = { id: "plan-monthly", name: "Monthly care", interval: "monthly", price: 1200, currency: "USD", purchasable: false as const, intervalCount: 1, trialPeriod: "weekly", trialPeriodCount: 7 };
const product = { ...products[0]!, subscriptionPlans: [plan] } as Product;

describe("product subscription plan block", () => {
  afterEach(() => { cleanup(); setCustomerAccessToken(undefined); });

  it("renders cadence, price, trial, and a sign-in link for guests", () => {
    render(<ProductDecisionPanel product={product} />);
    expect(screen.getByRole("heading", { name: "Subscribe & save" })).toBeInTheDocument();
    expect(screen.getByText("Monthly care")).toBeInTheDocument();
    expect(screen.getByText(/7 weekly free trial/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Sign in to subscribe" })).toHaveAttribute("href", "/account");
  });

  it("starts the subscription through the account API with an idempotency key and redirects", async () => {
    setCustomerAccessToken("customer-token");
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ success: true, message: "ok", data: {}, meta: {} }), { status: 200, headers: { "Content-Type": "application/json" } }));
    const adapter = createApiCustomer({ baseUrl: "https://api.example.test", fetch: fetcher, accessToken: "customer-token" });
    const navigate = vi.fn();
    render(<ProductDecisionPanel product={product} subscriptionAdapter={adapter} onSubscribeNavigate={navigate} />);
    fireEvent.click(screen.getByRole("button", { name: "Subscribe" }));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith("/account#subscriptions"));
    const calls = fetcher.mock.calls as unknown as [RequestInfo | URL, RequestInit | undefined][];
    expect(new URL(String(calls[0]![0])).pathname).toBe("/api/account/subscriptions");
    expect(calls[0]![1]?.method).toBe("POST");
    expect(new Headers(calls[0]![1]?.headers).get("Idempotency-Key")).toMatch(/^storefront-/);
    expect(JSON.parse(String(calls[0]![1]?.body))).toEqual(expect.objectContaining({ plan_id: "plan-monthly" }));
    expect(await screen.findByText("Subscription started.")).toBeInTheDocument();
  });

  it("does not render the block when the product has no plans", () => {
    render(<ProductDecisionPanel product={products[0]!} />);
    expect(screen.queryByRole("heading", { name: "Subscribe & save" })).toBeNull();
  });
});
