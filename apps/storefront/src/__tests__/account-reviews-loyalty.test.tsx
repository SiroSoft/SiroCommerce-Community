import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AccountLoyalty } from "@/components/account/AccountLoyalty";
import { AccountReviews } from "@/components/account/AccountReviews";
import { CustomerRequestError, type CustomerAdapter } from "@/services/customer";

function adapter(overrides: Partial<CustomerAdapter> = {}): CustomerAdapter {
  return {
    getLoyalty: vi.fn(async () => ({ balance_points: 240, state: "active" })),
    earnLoyalty: vi.fn(async () => ({})),
    redeemLoyalty: vi.fn(async () => ({})),
    submitReview: vi.fn(async () => ({ product_id: "prod-1", rating: 5, body: "Excellent quality.", status: "pending" })),
    ...overrides,
  } as CustomerAdapter;
}

describe("account reviews", () => {
  afterEach(() => cleanup());

  it("asks signed-out customers to sign in instead of showing the form", () => {
    render(<AccountReviews sessionToken="" adapter={adapter()} />);
    expect(screen.getByRole("heading", { name: "Reviews" })).toBeInTheDocument();
    expect(screen.getByText("Sign in to write a review.")).toBeInTheDocument();
    expect(screen.queryByLabelText("Product ID")).not.toBeInTheDocument();
  });

  it("validates rating and required fields without calling the API", async () => {
    const submitReview = vi.fn(async () => ({ product_id: "prod-1", rating: 5, body: "Excellent quality." }));
    render(<AccountReviews sessionToken="customer-token" adapter={adapter({ submitReview })} />);
    fireEvent.click(screen.getByRole("button", { name: "Submit review" }));
    expect(await screen.findAllByText("This field is required.")).toHaveLength(4);
    expect(screen.getByText("Rating must be between 1 and 5.")).toBeInTheDocument();
    expect(submitReview).not.toHaveBeenCalled();
  });

  it("submits the backend review contract and confirms moderation", async () => {
    const submitReview = vi.fn(async () => ({ product_id: "prod-1", rating: 5, body: "Excellent quality.", status: "pending" }));
    render(<AccountReviews sessionToken="customer-token" adapter={adapter({ submitReview })} />);
    fireEvent.change(screen.getByLabelText("Product ID"), { target: { value: "prod-1" } });
    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "order-1" } });
    fireEvent.change(screen.getByLabelText("Order line ID"), { target: { value: "line-1" } });
    fireEvent.change(screen.getByLabelText(/Rating/), { target: { value: "5" } });
    fireEvent.change(screen.getByLabelText("Review"), { target: { value: "Excellent quality." } });
    fireEvent.click(screen.getByRole("button", { name: "Submit review" }));
    await waitFor(() => expect(submitReview).toHaveBeenCalledWith("prod-1", {
      order_id: "order-1",
      order_line_id: "line-1",
      rating: 5,
      body: "Excellent quality.",
    }));
    expect(await screen.findByText("Review submitted for moderation.")).toBeInTheDocument();
  });

  it("reports submit failures without inventing a result", async () => {
    const submitReview = vi.fn(async () => { throw new CustomerRequestError(422, "Reviews require a delivered order"); });
    render(<AccountReviews sessionToken="customer-token" adapter={adapter({ submitReview })} />);
    fireEvent.change(screen.getByLabelText("Product ID"), { target: { value: "prod-1" } });
    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "order-1" } });
    fireEvent.change(screen.getByLabelText("Order line ID"), { target: { value: "line-1" } });
    fireEvent.change(screen.getByLabelText(/Rating/), { target: { value: "4" } });
    fireEvent.change(screen.getByLabelText("Review"), { target: { value: "Good." } });
    fireEvent.click(screen.getByRole("button", { name: "Submit review" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not submit this review.");
  });

  it("maps an expired session to the sign-in state", async () => {
    const submitReview = vi.fn(async () => { throw new CustomerRequestError(401, "Unauthorized"); });
    render(<AccountReviews sessionToken="stale-token" adapter={adapter({ submitReview })} />);
    fireEvent.change(screen.getByLabelText("Product ID"), { target: { value: "prod-1" } });
    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "order-1" } });
    fireEvent.change(screen.getByLabelText("Order line ID"), { target: { value: "line-1" } });
    fireEvent.change(screen.getByLabelText(/Rating/), { target: { value: "4" } });
    fireEvent.change(screen.getByLabelText("Review"), { target: { value: "Good." } });
    fireEvent.click(screen.getByRole("button", { name: "Submit review" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Sign in to write a review.");
  });
});

describe("account loyalty", () => {
  afterEach(() => cleanup());

  it("asks signed-out customers to sign in instead of loading a balance", () => {
    const getLoyalty = vi.fn(async () => ({ balance_points: 240 }));
    render(<AccountLoyalty sessionToken="" adapter={adapter({ getLoyalty })} />);
    expect(screen.getByRole("heading", { name: "Loyalty" })).toBeInTheDocument();
    expect(screen.getByText("Sign in to see your points.")).toBeInTheDocument();
    expect(getLoyalty).not.toHaveBeenCalled();
  });

  it("loads the live points balance and states that history is not exposed", async () => {
    render(<AccountLoyalty sessionToken="customer-token" adapter={adapter()} />);
    expect(screen.getByText("Loading your points balance...")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText("240")).toBeInTheDocument());
    expect(screen.getByText("Points balance")).toBeInTheDocument();
    expect(screen.getByText(/exposes the points balance only/)).toBeInTheDocument();
  });

  it("shows the empty state for a zero balance", async () => {
    render(<AccountLoyalty sessionToken="customer-token" adapter={adapter({ getLoyalty: vi.fn(async () => ({ balance_points: 0, state: "active" })) })} />);
    await waitFor(() => expect(screen.getByText("0")).toBeInTheDocument());
    expect(screen.getByText(/No points yet/)).toBeInTheDocument();
  });

  it("recovers from load errors with an explicit retry", async () => {
    const getLoyalty = vi.fn(async () => ({ balance_points: 240, state: "active" }));
    getLoyalty.mockRejectedValueOnce(new CustomerRequestError(503, "unavailable"));
    render(<AccountLoyalty sessionToken="customer-token" adapter={adapter({ getLoyalty })} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not load your points balance.");
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(screen.getByText("240")).toBeInTheDocument());
  });

  it("confirms before redeeming and refreshes the balance", async () => {
    const redeemLoyalty = vi.fn(async () => ({ operation: "redeem", points: -100 }));
    const getLoyalty = vi.fn(async () => ({ balance_points: 240, state: "active" }));
    getLoyalty.mockResolvedValueOnce({ balance_points: 240, state: "active" }).mockResolvedValue({ balance_points: 140, state: "active" });
    render(<AccountLoyalty sessionToken="customer-token" adapter={adapter({ getLoyalty, redeemLoyalty })} />);
    await waitFor(() => expect(screen.getByText("240")).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText("Points to redeem"), { target: { value: "100" } });
    fireEvent.click(screen.getByRole("button", { name: "Redeem" }));
    expect(screen.getByText("Redeem 100 points?")).toBeInTheDocument();
    expect(redeemLoyalty).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Confirm redemption" }));
    await waitFor(() => expect(redeemLoyalty).toHaveBeenCalledWith(100));
    expect(await screen.findByText("100 points redeemed.")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText("140")).toBeInTheDocument());
  });

  it("rejects non-positive redemptions before any API call", async () => {
    const redeemLoyalty = vi.fn(async () => ({}));
    render(<AccountLoyalty sessionToken="customer-token" adapter={adapter({ redeemLoyalty })} />);
    await waitFor(() => expect(screen.getByText("240")).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText("Points to redeem"), { target: { value: "0" } });
    fireEvent.click(screen.getByRole("button", { name: "Redeem" }));
    expect(await screen.findByText("Enter at least 1 point.")).toBeInTheDocument();
    expect(redeemLoyalty).not.toHaveBeenCalled();
  });

  it("earns points for a settled order through the live endpoint", async () => {
    const earnLoyalty = vi.fn(async () => ({ operation: "earn", points: 50 }));
    render(<AccountLoyalty sessionToken="customer-token" adapter={adapter({ earnLoyalty })} />);
    await waitFor(() => expect(screen.getByText("240")).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText("Order ID"), { target: { value: "order-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Earn points" }));
    await waitFor(() => expect(earnLoyalty).toHaveBeenCalledWith("order-1"));
    expect(await screen.findByText("Points earned for this order.")).toBeInTheDocument();
  });
});
