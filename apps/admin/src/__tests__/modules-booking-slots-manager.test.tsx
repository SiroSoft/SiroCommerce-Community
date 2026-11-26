import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
vi.mock("@/services/merchant.service", () => ({ idempotencyKey: () => "idem" }));

import { BookingSlotsManager } from "@/modules/products/components/booking-slots-manager";

const variants = [
  { id: "v-1", sku: "SKU-1", price_minor: 5000 },
  { id: "v-2", sku: "SKU-2", price_minor: 7000 },
];
const slots = [
  { id: "s-1", variant_id: "v-1", starts_at: "2026-12-01T09:00:00Z", ends_at: "2026-12-01T10:00:00Z", capacity: 10, price_minor: 5000, currency: "VND" },
  { id: "s-2", variant_id: "v-1", starts_at: "2026-12-01T10:00:00Z", ends_at: "2026-12-01T11:00:00Z", capacity: 8 },
  { id: "s-3", variant_id: "v-1", starts_at: "2026-12-02T09:00:00Z", ends_at: "2026-12-02T10:00:00Z", capacity: 5 },
];

beforeEach(() => {
  vi.clearAllMocks();
  apiMock.get.mockImplementation((path: string) => {
    if (path.endsWith("/variants")) return Promise.resolve({ data: { data: variants } });
    if (path.endsWith("/booking-slots")) return Promise.resolve({ data: { data: slots } });
    return Promise.resolve({ data: {} });
  });
  apiMock.post.mockResolvedValue({ data: { data: { id: "s-new" } } });
});

afterEach(() => {
  cleanup();
});

describe("BookingSlotsManager", () => {
  it("loads variants and groups slots by day", async () => {
    render(<BookingSlotsManager productId={1} />);
    expect(await screen.findByText(/SKU-1/)).toBeTruthy();
    expect(await screen.findByText(/2026-12-01/)).toBeTruthy();
    expect(await screen.findByText(/2026-12-02/)).toBeTruthy();
    expect(screen.getByText(/3 slots total/)).toBeTruthy();
    expect(screen.getByText(/capacity 10/)).toBeTruthy();
  });

  it("creates a slot with idempotency and the expected payload", async () => {
    const { container } = render(<BookingSlotsManager productId={1} />);
    await screen.findByText(/SKU-1/);
    const inputs = container.querySelectorAll('input[type="datetime-local"]');
    fireEvent.change(inputs[0], { target: { value: "2026-12-05T09:00" } });
    fireEvent.change(inputs[1], { target: { value: "2026-12-05T10:00" } });
    const numbers = container.querySelectorAll('input[type="number"]');
    fireEvent.change(numbers[0], { target: { value: "4" } });
    fireEvent.change(numbers[1], { target: { value: "9000" } });
    await waitFor(() => expect(screen.getByRole("button", { name: "Create slot" })).toBeEnabled());
    fireEvent.click(screen.getByRole("button", { name: "Create slot" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/products/v-1/booking-slots",
      expect.objectContaining({ capacity: 4, price_minor: 9000 }),
      { idempotencyKey: "idem" },
    ));
  });

  it("requires variant, start and end before creating", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path.endsWith("/variants")) return Promise.resolve({ data: { data: [] } });
      return Promise.resolve({ data: { data: [] } });
    });
    render(<BookingSlotsManager productId={1} />);
    const variantInput = screen.getByPlaceholderText(/variant id/);
    fireEvent.change(variantInput, { target: { value: "v-9" } });
    await waitFor(() => expect(screen.getByRole("button", { name: "Create slot" })).toBeEnabled());
    fireEvent.click(screen.getByRole("button", { name: "Create slot" }));
    expect(await screen.findByText("Variant, start and end required")).toBeTruthy();
    expect(apiMock.post).not.toHaveBeenCalled();
  });

  it("bulk imports valid CSV lines and reports failures", async () => {
    render(<BookingSlotsManager productId={1} />);
    await screen.findByText(/SKU-1/);
    fireEvent.change(screen.getByPlaceholderText(/2026-10-06T09:00/), {
      target: { value: "2026-12-06T09:00,2026-12-06T10:00,6,1000\nbad-line" },
    });
    await waitFor(() => expect(screen.getByRole("button", { name: "Import CSV" })).toBeEnabled());
    fireEvent.click(screen.getByRole("button", { name: "Import CSV" }));
    expect(await screen.findByText("Bulk: 1 created, 1 failed")).toBeTruthy();
    expect(apiMock.post).toHaveBeenCalledTimes(1);
  });

  it("surfaces slot load errors and retries", async () => {
    apiMock.get.mockImplementation((path: string) => {
      if (path.endsWith("/variants")) return Promise.resolve({ data: { data: variants } });
      return Promise.reject({ status: 500 });
    });
    render(<BookingSlotsManager productId={1} />);
    expect(await screen.findByText("Service unavailable")).toBeTruthy();
    apiMock.get.mockImplementation((path: string) => {
      if (path.endsWith("/variants")) return Promise.resolve({ data: { data: variants } });
      return Promise.resolve({ data: { data: slots } });
    });
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByText(/2026-12-01/)).toBeTruthy();
  });

  it("disables reload and shows the empty variant prompt without variants", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    render(<BookingSlotsManager productId={1} />);
    expect(await screen.findByPlaceholderText(/variant id/)).toBeTruthy();
    expect(screen.getByRole("button", { name: "Reload" })).toBeDisabled();
  });
});
