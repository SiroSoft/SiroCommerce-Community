import "@testing-library/jest-dom/vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SubscriptionExperience } from "@/components/SubscriptionExperience";
import { setCustomerAccessToken, type CustomerAdapter } from "@/services/customer";
import { cleanup } from "@testing-library/react";

const base = { id: "sub-1", customer_id: "customer-1", plan_id: "plan-1", status: "active" as const, product_name: "Monthly care", interval: "month", interval_count: 1, next_charge_at: "2099-01-01", price: { amount_minor: 1200, currency: "USD" }, renewal_status: "failed" as const };
function adapter(overrides: Partial<CustomerAdapter> = {}): CustomerAdapter {
  return { getSubscriptions: vi.fn(async () => [base]), getSubscription: vi.fn(async () => base), transitionSubscription: vi.fn(async () => ({})), renewSubscription: vi.fn(async () => ({})), startSubscription: vi.fn(async () => ({})), ...overrides } as CustomerAdapter;
}

describe("subscription storefront experience", () => {
  afterEach(() => { cleanup(); setCustomerAccessToken(undefined); });
  it("renders catalog plan cadence and requires consent for checkout handoff", async () => {
    setCustomerAccessToken("customer-token");
    const onCheckout = vi.fn();
    render(<SubscriptionExperience adapter={adapter()} plans={[{ id: "plan-1", name: "Monthly", interval: "month", price: 1200, currency: "USD", purchasable: false }]} onCheckout={onCheckout} />);
    expect(screen.getByText("Monthly")).toBeInTheDocument();
    screen.getByRole("button", { name: "Continue to checkout" }).click();
    expect(onCheckout).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.getByRole("alert")).toHaveTextContent("Confirm the recurring cadence"));
    screen.getByRole("checkbox").click();
    screen.getByRole("button", { name: "Continue to checkout" }).click();
    expect(onCheckout).toHaveBeenCalledWith(expect.objectContaining({ id: "plan-1" }));
  });

  it("starts a plan directly against the subscriptions API instead of redirecting", async () => {
    setCustomerAccessToken("customer-token");
    const start = vi.fn(async () => ({}));
    render(<SubscriptionExperience adapter={adapter({ startSubscription: start, getSubscriptions: vi.fn(async () => []) })} plans={[{ id: "plan-1", name: "Monthly", interval: "month", price: 1200, currency: "USD", purchasable: false }]} />);
    screen.getByRole("checkbox").click();
    screen.getByRole("button", { name: "Continue to checkout" }).click();
    await waitFor(() => expect(start).toHaveBeenCalledWith("plan-1", true));
    await waitFor(() => expect(screen.getByText("Subscription started.")).toBeInTheDocument());
  });

  it("shows authenticated ownership, failure recovery, and accessible empty/error states", async () => {
    setCustomerAccessToken("customer-token");
    const renew = vi.fn(async () => ({}));
    const transition = vi.fn(async () => ({}));
    render(<SubscriptionExperience adapter={adapter({ renewSubscription: renew, transitionSubscription: transition })} />);
    await waitFor(() => expect(screen.getByText("Monthly care")).toBeInTheDocument());
    expect(screen.getAllByText("Retry payment").length).toBeGreaterThan(0);
    screen.getByRole("checkbox").click();
    screen.getByRole("button", { name: "Pause" }).click();
    await waitFor(() => expect(transition).toHaveBeenCalledWith("sub-1", "pause", true));
    await waitFor(() => expect(screen.getByRole("button", { name: "Pause" })).toBeEnabled());
    screen.getAllByRole("button", { name: "Retry payment" })[0]?.click();
    await waitFor(() => expect(renew).toHaveBeenCalledWith("sub-1"));
  });
});
