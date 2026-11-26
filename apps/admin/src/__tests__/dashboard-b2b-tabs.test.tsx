import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import B2bPage from "@/app/(dashboard)/b2b/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

function renderB2b() {
  return render(<I18nProvider><B2bPage /></I18nProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/b2b/companies/c-1/credit") return body({ company_id: "c-1", limit_minor: 500000, currency: "USD" });
    if (path === "/api/v2/b2b/quotes/q-1") return body({ id: "q-1", state: "draft", lines: [] });
    if (path === "/api/admin/stores") return body([{ id: "st-1", name: "Main", status: "active" }]);
    if (path === "/api/admin/stores/st-1/channels") return body([{ id: "ch-1", name: "Web" }]);
    return body({});
  });
  apiMock.post.mockResolvedValue(body({ id: "created", ok: true }));
  apiMock.put.mockResolvedValue(body({ id: "terms-1" }));
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe("B2B account and catalog actions", () => {
  it("creates organizations, negotiated prices, catalog ownership and reads credit", async () => {
    renderB2b();
    fireEvent.change(screen.getByLabelText("Organization name"), { target: { value: "Acme Procurement" } });
    fireEvent.click(screen.getByRole("button", { name: "Create organization" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies",
      { name: "Acme Procurement", status: "active" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Organization ID"), { target: { value: "c-1" } });
    fireEvent.change(screen.getByLabelText("Variant ID", { selector: "#b2b-variant" }), { target: { value: "v-1" } });
    fireEvent.change(screen.getByLabelText("Unit price in minor units"), { target: { value: "9900" } });
    fireEvent.click(screen.getByRole("button", { name: "Save negotiated price" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/negotiated-prices",
      { variant_id: "v-1", unit_price_minor: 9900 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Catalog code"), { target: { value: "ENT" } });
    fireEvent.click(screen.getByRole("button", { name: "Assign catalog" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/catalogs",
      { code: "ENT", name: "ENT" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Add catalog item" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/catalogs/ENT/items",
      { variant_id: "v-1" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.click(screen.getByRole("button", { name: "Load credit account" }));
    expect(await screen.findByText(/"limit_minor": 500000/)).toBeVisible();
  });

  it("validates minor prices before calling the API", async () => {
    renderB2b();
    fireEvent.change(screen.getByLabelText("Organization ID"), { target: { value: "c-1" } });
    fireEvent.change(screen.getByLabelText("Variant ID", { selector: "#b2b-variant" }), { target: { value: "v-1" } });
    fireEvent.change(screen.getByLabelText("Unit price in minor units"), { target: { value: "-5" } });
    fireEvent.click(screen.getByRole("button", { name: "Save negotiated price" }));
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(apiMock.post).not.toHaveBeenCalled();
  });
});

describe("B2B quote and approval flows", () => {
  it("creates and validates bulk quotes from CSV", async () => {
    renderB2b();
    fireEvent.click(screen.getByRole("tab", { name: "Quotes" }));
    fireEvent.change(screen.getByLabelText("Organization ID"), { target: { value: "c-1" } });
    fireEvent.change(screen.getByDisplayValue("USD"), { target: { value: "EUR" } });
    fireEvent.click(screen.getByRole("button", { name: "Create bulk quote" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/companies/c-1/quotes",
      { currency: "EUR", lines: [{ variant_id: "v1", qty: 2 }, { variant_id: "v2", qty: 5 }] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    expect(await screen.findByText("Bulk quote created (2 lines).")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Validate bulk" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/companies/c-1/orders/validate-bulk",
      { lines: [{ variant_id: "v1", qty: 2 }, { variant_id: "v2", qty: 5 }] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText(/Bulk quote CSV/), { target: { value: "bad,header\nx,1" } });
    fireEvent.click(screen.getByRole("button", { name: "Create bulk quote" }));
    expect(await screen.findByText("CSV header must be variant_id,qty")).toBeVisible();
  });

  it("refreshes, rejects and converts quotes plus approves orders and contracts", async () => {
    renderB2b();
    fireEvent.click(screen.getByRole("tab", { name: "Quotes" }));
    fireEvent.change(screen.getByLabelText("Quote ID"), { target: { value: "q-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Refresh quote" }));
    expect(await screen.findByText(/"state": "draft"/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Reject quote" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/quotes/q-1/reject",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Convert quote" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Convert quote" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/quotes/q-1/convert",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("B2B order ID"), { target: { value: "o-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Approve order" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/orders/o-1/approve",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Approve for organization" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Approve for organization" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/orders/o-1/approve-company",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Contract ID"), { target: { value: "ctr-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Approve contract" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/contracts/ctr-1/approve",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});

describe("B2B sites and orders tabs", () => {
  it("loads stores and their channels", async () => {
    renderB2b();
    fireEvent.click(screen.getByRole("tab", { name: "Sites" }));
    expect(await screen.findByText(/Main/)).toBeVisible();
    expect(await screen.findByText("Web")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/stores");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/stores/st-1/channels");
  });

  it("approves orders, validates bulk lines and manages purchase orders", async () => {
    renderB2b();
    fireEvent.click(screen.getByRole("tab", { name: "Orders" }));
    const companyInputs = screen.getAllByPlaceholderText("company id");
    fireEvent.change(companyInputs[0], { target: { value: "c-1" } });
    fireEvent.change(screen.getByPlaceholderText("order id"), { target: { value: "o-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Approve" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/orders/o-1/approve",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Approve for company" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Approve for company" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/orders/o-1/approve-company",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "To draft" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "To draft" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/orders/o-1/transition",
      { state: "draft" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    const bulk = screen.getByDisplayValue(/variant_id/);
    fireEvent.change(bulk, { target: { value: "not-json" } });
    fireEvent.click(screen.getByRole("button", { name: "Validate bulk" }));
    expect(await screen.findByRole("alert")).toBeVisible();
    fireEvent.change(bulk, { target: { value: "[{\"variant_id\":\"v1\",\"qty\":2}]" } });
    fireEvent.click(screen.getByRole("button", { name: "Validate bulk" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/companies/c-1/orders/validate-bulk",
      { lines: [{ variant_id: "v1", qty: 2 }] },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    const allCompanyInputs = screen.getAllByPlaceholderText("company id");
    fireEvent.change(allCompanyInputs[allCompanyInputs.length - 1], { target: { value: "c-1" } });
    fireEvent.change(screen.getByPlaceholderText("PO-2026-001"), { target: { value: "PO-1" } });
    fireEvent.change(screen.getByPlaceholderText("100000"), { target: { value: "250000" } });
    fireEvent.click(screen.getByRole("button", { name: "Create PO" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/companies/c-1/purchase-orders",
      { po_number: "PO-1", total_minor: 250000 },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByPlaceholderText("po id"), { target: { value: "po-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Approve PO" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/purchase-orders/po-1/approve",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    await waitFor(() => expect(screen.getByRole("button", { name: "Reject PO" })).not.toBeDisabled());
    fireEvent.click(screen.getByRole("button", { name: "Reject PO" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/purchase-orders/po-1/reject",
      {},
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});
