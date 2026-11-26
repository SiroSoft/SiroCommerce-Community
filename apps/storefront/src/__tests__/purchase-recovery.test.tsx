import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { PurchaseFlow } from "../components/PurchaseFlow";

describe("purchase recovery UX", () => {
  afterEach(() => cleanup());
  beforeEach(() => {
    window.history.replaceState({}, "", "/checkout");
    window.localStorage.clear();
  });

  it("validates checkout email and focuses the invalid field", async () => {
    render(<PurchaseFlow />);
    const email = screen.getByLabelText("Email");
    fireEvent.change(email, { target: { value: "not-an-email" } });
    fireEvent.click(screen.getByRole("button", { name: "Place order" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("valid email");
    await waitFor(() => expect(email).toHaveFocus());
  });

  it("does not retry an unknown payment with the same attempt", async () => {
    window.history.replaceState({}, "", "/checkout?payment-unknown=1");
    render(<PurchaseFlow />);
    fireEvent.click(screen.getByRole("button", { name: "Place order" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/status is unknown/i);
    fireEvent.click(screen.getByRole("button", { name: "Retry safely" }));
    await waitFor(() => expect(screen.getByTestId("order-number")).toHaveTextContent("SO-100001"));
  });

  it("supports guest lookup and keeps account access explicit", async () => {
    window.history.replaceState({}, "", "/account");
    render(<PurchaseFlow />);
    expect(screen.getByText(/Sign in to view your order history/i)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Guest order access token"), { target: { value: "guest-order-token-fixture" } });
    fireEvent.click(screen.getByRole("button", { name: "View guest order" }));
    await waitFor(() => expect(screen.getByRole("heading", { name: "Order details" })).toBeInTheDocument());
    expect(screen.getByText("SO-100001")).toBeInTheDocument();
    expect(window.location.search).not.toContain("guest_token");
  });

  it("shows buyer dashboard states without inventing unsupported operations", () => {
    window.history.replaceState({}, "", "/account");
    render(<PurchaseFlow />);
    expect(screen.getByRole("heading", { name: "Account dashboard" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Wishlist" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Reviews" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Loyalty" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /returns/i })).toBeInTheDocument();
    // All account tools now render real backend data; no fixture placeholders remain.
    expect(screen.queryByText("Saved products are not supported by the current storefront contract.")).not.toBeInTheDocument();
    expect(screen.queryAllByText(/Not available in this storefront/i)).toHaveLength(0);
  });

  it("loads fixture subscriptions after customer sign-in", async () => {
    window.history.replaceState({}, "", "/account");
    render(<PurchaseFlow />);
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "fixture-password" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByText("Siro monthly delivery")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /Siro monthly delivery/i }));
    expect(await screen.findByText(/Next charge: 2099-02-01/)).toBeInTheDocument();
  });
});
