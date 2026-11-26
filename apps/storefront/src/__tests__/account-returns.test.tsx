import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AccountReturns } from "@/components/account/AccountReturns";
import { CustomerRequestError, type CustomerAdapter, type CustomerReturn } from "@/services/customer";

const orders = [{ id: "o-1", number: "SO-1001" }];

const listItem: CustomerReturn = {
  id: "r-1",
  order_id: "o-1",
  fulfillment_id: "f-1",
  state: "requested",
  reason: "defective",
  created_at: "2026-01-01",
  refund_state: "pending",
  refund_amount_minor: 500,
  refund_currency: "USD",
  label_state: "label_created",
  label_tracking: "TRK1",
  lines: [{ id: "rl-1", return_id: "r-1", order_line_id: "l-1", qty: 2, reason: "defective", disposition: "restock", condition: "good" }],
};

function adapter(overrides: Partial<CustomerAdapter> = {}): CustomerAdapter {
  return {
    getReturns: vi.fn(async () => [listItem]),
    getReturn: vi.fn(async () => listItem),
    requestReturn: vi.fn(async () => ({ id: "r-9", order_id: "o-1", state: "requested" })),
    ...overrides,
  } as CustomerAdapter;
}

describe("account returns", () => {
  afterEach(() => { cleanup(); });

  it("asks signed-out customers to sign in without calling the API", () => {
    const getReturns = vi.fn(async () => [listItem]);
    render(<AccountReturns orders={orders} adapter={adapter({ getReturns })} />);
    expect(screen.getByText("Sign in to see your returns.")).toBeInTheDocument();
    expect(getReturns).not.toHaveBeenCalled();
  });

  it("renders a loading state before the returns list resolves", () => {
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter({ getReturns: vi.fn(() => new Promise<CustomerReturn[]>(() => {})) })} />);
    expect(screen.getByText("Loading returns…")).toBeInTheDocument();
  });

  it("lists returns with status, refund, and tracking", async () => {
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter()} />);
    expect(await screen.findByText("Return r-1")).toBeInTheDocument();
    expect(screen.getByText(/Refund pending/)).toBeInTheDocument();
    expect(screen.getByText(/Tracking TRK1/)).toBeInTheDocument();
  });

  it("renders an empty state when there are no returns", async () => {
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter({ getReturns: vi.fn(async () => []) })} />);
    expect(await screen.findByText("No returns on this account yet.")).toBeInTheDocument();
  });

  it("renders an error state with retry", async () => {
    const getReturns = vi.fn(async () => { throw new Error("boom"); });
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter({ getReturns })} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("Returns could not be loaded.");
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(getReturns).toHaveBeenCalledTimes(2));
  });

  it("maps a 401 load failure to the sign-in message", async () => {
    const getReturns = vi.fn(async () => { throw new CustomerRequestError(401, "unauthorized"); });
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter({ getReturns })} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("Sign in to see your returns.");
  });

  it("opens a return detail with items and the exposed timeline", async () => {
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter()} />);
    fireEvent.click(await screen.findByRole("button", { name: "View details" }));
    expect(await screen.findByText("Timeline")).toBeInTheDocument();
    expect(screen.getByText("Created")).toBeInTheDocument();
    expect(screen.getByText("defective")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to returns" }));
    expect(await screen.findByText("Return r-1")).toBeInTheDocument();
  });

  it("validates the request form before submitting", async () => {
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter()} />);
    await screen.findByText("Return r-1");
    fireEvent.click(screen.getByRole("button", { name: "Request a return" }));
    fireEvent.click(screen.getByRole("button", { name: "Submit return request" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Select an order for this return.");
  });

  it("submits a return request and shows the created case", async () => {
    const requestReturn = vi.fn(async () => ({ id: "r-9", order_id: "o-1", state: "requested" }));
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter({ requestReturn })} />);
    await screen.findByText("Return r-1");
    fireEvent.click(screen.getByRole("button", { name: "Request a return" }));
    fireEvent.change(screen.getByLabelText("Order"), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText("Fulfillment ID"), { target: { value: "f-1" } });
    fireEvent.change(screen.getByLabelText("Order line ID"), { target: { value: "l-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Submit return request" }));
    await waitFor(() => expect(requestReturn).toHaveBeenCalledWith("o-1", {
      fulfillment_id: "f-1",
      lines: [{ order_line_id: "l-1", qty: 1 }],
    }));
    expect(await screen.findByText("Return r-9 submitted. The case updates as it is reviewed.")).toBeInTheDocument();
    expect(screen.getByText("Return r-9")).toBeInTheDocument();
  });

  it("shows a localized error when the return request fails", async () => {
    const requestReturn = vi.fn(async () => { throw new CustomerRequestError(422, "Only delivered fulfillment can be returned"); });
    render(<AccountReturns orders={orders} sessionToken="token" adapter={adapter({ requestReturn })} />);
    await screen.findByText("Return r-1");
    fireEvent.click(screen.getByRole("button", { name: "Request a return" }));
    fireEvent.change(screen.getByLabelText("Order"), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText("Fulfillment ID"), { target: { value: "f-9" } });
    fireEvent.change(screen.getByLabelText("Order line ID"), { target: { value: "l-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Submit return request" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("The return could not be submitted.");
  });

  it("preselects the order coming from the order-detail entry point", async () => {
    render(<AccountReturns orders={[{ id: "o-2", number: "SO-1002" }]} sessionToken="token" requestOrderId="o-2" adapter={adapter()} />);
    await screen.findByText("Return r-1");
    expect((screen.getByLabelText("Order") as HTMLSelectElement).value).toBe("o-2");
  });
});
