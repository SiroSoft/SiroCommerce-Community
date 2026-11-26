import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import FulfillmentPage from "@/app/(dashboard)/fulfillment/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { role: "admin" }, isLoading: false, isAuthenticated: true }) }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/fulfillment/f-1/track") return body({ id: "f-1", state: "processing", carrier: "dhl" });
    if (path === "/api/v2/shipments/sh-1/tracking") return body({ id: "sh-1", status: "in_transit" });
    return body({});
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/admin/fulfillment/allocate") return body({ id: "ff-1" });
    if (path === "/api/admin/returns") return body({ id: "ret-1" });
    if (path === "/api/v2/shipping/rates") return body({ rates: [{ carrier: "dhl", amount: 5000 }] });
    if (path === "/api/v2/shipments") return body({ id: "sh-1" });
    return body({ id: "ok" });
  });
});

afterEach(() => {
  cleanup();
});

describe("fulfillment page", () => {
  it("allocates fulfillment lines after validation and confirmation", async () => {
    renderWithProviders(<FulfillmentPage />);
    expect(await screen.findByText("Allocate fulfillment")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Order ID", { selector: "#allocate-order" }), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText("Lines JSON", { selector: "#allocate-lines" }), { target: { value: "[{\"variant_id\":\"v-1\",\"qty\":2}]" } });
    fireEvent.click(screen.getByRole("button", { name: "Allocate" }));
    const dialog = await screen.findByRole("dialog", { name: "Allocate fulfillment" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/fulfillment/allocate",
      { order_id: "o-1", lines: [{ variant_id: "v-1", qty: 2 }] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Fulfillment allocated.")).toBeVisible();
    expect(await screen.findByText(/"id": "ff-1"/)).toBeVisible();
  });

  it("rejects invalid allocation JSON and lines", async () => {
    renderWithProviders(<FulfillmentPage />);
    await screen.findByText("Allocate fulfillment");
    fireEvent.change(screen.getByLabelText("Order ID", { selector: "#allocate-order" }), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText("Lines JSON", { selector: "#allocate-lines" }), { target: { value: "bad-json" } });
    fireEvent.click(screen.getByRole("button", { name: "Allocate" }));
    expect(await screen.findByText("Lines must be valid JSON.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Lines JSON", { selector: "#allocate-lines" }), { target: { value: "[]" } });
    fireEvent.click(screen.getByRole("button", { name: "Allocate" }));
    expect(await screen.findByText(/Provide at least one line/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Lines JSON", { selector: "#allocate-lines" }), { target: { value: "[{\"variant_id\":\"\",\"qty\":0}]" } });
    fireEvent.click(screen.getByRole("button", { name: "Allocate" }));
    expect(await screen.findByText(/Each line needs/)).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("tracks a fulfillment and advances it through every state", async () => {
    renderWithProviders(<FulfillmentPage />);
    await screen.findByText("Allocate fulfillment");
    fireEvent.change(screen.getByPlaceholderText("Enter fulfillment ID"), { target: { value: "f-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Refresh" }));
    expect(await screen.findByText(/"state": "processing"/)).toBeVisible();
    const actions = [
      ["Ship", "/api/admin/fulfillment/f-1/ship", undefined],
      ["Deliver", "/api/admin/fulfillment/f-1/deliver", undefined],
      ["Mark ready for pickup", "/api/admin/fulfillment/f-1/ready", {}],
      ["Complete pickup", "/api/admin/fulfillment/f-1/collect", undefined],
      ["Cancel", "/api/admin/fulfillment/f-1/cancel", undefined],
    ] as const;
    for (const [label, path, payload] of actions) {
      const button = await screen.findByRole("button", { name: label });
      await waitFor(() => expect(button).not.toBeDisabled());
      fireEvent.click(button);
      const advance = await screen.findByRole("dialog", { name: "Advance fulfillment" });
      fireEvent.click(within(advance).getByRole("button", { name: "Confirm" }));
      await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(path, payload, expect.objectContaining({ idempotencyKey: expect.any(String) })));
    }
    expect(await screen.findByText(/"state": "processing"/)).toBeVisible();
  });

  it("requests a return and walks the return lifecycle", async () => {
    renderWithProviders(<FulfillmentPage />);
    await screen.findByText("Allocate fulfillment");
    fireEvent.change(screen.getByLabelText("Order ID", { selector: "#return-order" }), { target: { value: "o-1" } });
    fireEvent.change(screen.getByLabelText("Fulfillment ID", { selector: "#return-fulfillment" }), { target: { value: "f-1" } });
    fireEvent.change(screen.getByLabelText("Reason (optional)", { selector: "#return-reason" }), { target: { value: "damaged" } });
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    const request = await screen.findByRole("dialog", { name: "Request return" });
    fireEvent.click(within(request).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/returns",
      { order_id: "o-1", fulfillment_id: "f-1", lines: [{ line_id: "line-1", qty: 1 }], reason: "damaged" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Return requested.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Note (optional)"), { target: { value: "ok" } });
    const lifecycle = [
      ["Approve", "/api/admin/returns/ret-1/approve", undefined],
      ["Inspect", "/api/admin/returns/ret-1/inspect", { disposition: "restock", note: "ok" }],
      ["Complete", "/api/admin/returns/ret-1/complete", undefined],
      ["Approve refund", "/api/admin/returns/ret-1/approve-refund", undefined],
      ["Reject", "/api/admin/returns/ret-1/reject", { note: "ok" }],
    ] as const;
    for (const [label, path, payload] of lifecycle) {
      const button = await screen.findByRole("button", { name: label });
      await waitFor(() => expect(button).not.toBeDisabled());
      fireEvent.click(button);
      const dialog = await screen.findByRole("dialog", { name: label === "Approve" ? "Return approved" : label === "Inspect" ? "Return inspected" : label === "Complete" ? "Return completed" : label === "Approve refund" ? "Return refund approved" : "Return rejected" });
      fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
      await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(path, payload, expect.objectContaining({ idempotencyKey: expect.any(String) })));
    }
    fireEvent.change(screen.getByLabelText("Label shipping JSON"), { target: { value: "{\"carrier\":\"dhl\"}" } });
    fireEvent.click(screen.getByRole("button", { name: "Create label" }));
    const label = await screen.findByRole("dialog", { name: "Create return label" });
    fireEvent.click(within(label).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/returns/ret-1/label",
      { shipping: { carrier: "dhl" } },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Return label created.")).toBeVisible();
  });

  it("validates return lines, identifiers and label shipping JSON", async () => {
    renderWithProviders(<FulfillmentPage />);
    await screen.findByText("Allocate fulfillment");
    fireEvent.change(screen.getByLabelText("Return lines JSON"), { target: { value: "bad" } });
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    expect(await screen.findByText("Return lines must be valid JSON.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Return lines JSON"), { target: { value: "[]" } });
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    expect(await screen.findByText(/Provide at least one return line/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Return lines JSON"), { target: { value: "[{\"line_id\":\"\",\"qty\":0}]" } });
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    expect(await screen.findByText(/Each return line needs/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Return lines JSON"), { target: { value: "[{\"line_id\":\"l-1\",\"qty\":1}]" } });
    fireEvent.click(screen.getByRole("button", { name: "Request return" }));
    expect(await screen.findByText(/Order ID and fulfillment ID are required/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Approve" }));
    expect(await screen.findByText(/Return ID is required/)).toBeVisible();
    fireEvent.change(screen.getByLabelText("Label shipping JSON"), { target: { value: "123" } });
    fireEvent.click(screen.getByRole("button", { name: "Create label" }));
    expect(await screen.findByText("Shipping must be a JSON object.")).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("quotes, queues, tracks and cancels shipments", async () => {
    renderWithProviders(<FulfillmentPage />);
    await screen.findByText("Shipments and labels");
    fireEvent.click(screen.getByRole("button", { name: "Quote rates" }));
    const quote = await screen.findByRole("dialog", { name: "Quote shipping rates" });
    fireEvent.click(within(quote).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/v2/shipping/rates", { origin: {}, destination: {}, packages: [] }));
    expect(await screen.findByText("Quote shipping rates accepted.")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Queue shipment" }));
    const queue = await screen.findByRole("dialog", { name: "Queue shipment" });
    fireEvent.click(within(queue).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/shipments",
      { shipment: {} },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Queue shipment accepted.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Shipment ID"), { target: { value: "sh-1" } });
    const tracking = screen.getByRole("button", { name: "Refresh tracking" });
    await waitFor(() => expect(tracking).not.toBeDisabled());
    fireEvent.click(tracking);
    const trackingDialog = await screen.findByRole("dialog", { name: "Refresh shipment tracking" });
    fireEvent.click(within(trackingDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/v2/shipments/sh-1/tracking"));
    expect(await screen.findByText(/"status": "in_transit"/)).toBeVisible();
    const cancel = screen.getByRole("button", { name: "Cancel shipment" });
    await waitFor(() => expect(cancel).not.toBeDisabled());
    fireEvent.click(cancel);
    const cancelDialog = await screen.findByRole("dialog", { name: "Cancel shipment" });
    fireEvent.click(within(cancelDialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/shipments/sh-1/cancel",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("shows a read-only state for roles without inventory access", async () => {
    useAuthStore.setState({ user: { role: "finance" } as never, isAuthenticated: true });
    renderWithProviders(<FulfillmentPage />);
    expect(await screen.findByText(/read-only access/i)).toBeVisible();
  });
});
