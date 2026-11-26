import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import B2bPage from "@/app/(dashboard)/b2b/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

beforeEach(() => {
  vi.clearAllMocks();
  vi.spyOn(window, "confirm").mockReturnValue(true);
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/v2/admin/vendors") return ok([{ id: "vendor-1", name: "Acme Supply", status: "active" }]);
    if (path === "/api/admin/b2b/companies/c-1/credit") return ok({ company_id: "c-1", limit_minor: 100000 });
    if (path === "/api/v2/admin/commissions") return ok([{ id: "com-1", vendor_id: "vendor-1", gross_minor: 5000, rate_bps: 1000, currency: "USD" }]);
    return ok({});
  });
  apiMock.post.mockResolvedValue(ok({ id: "created-1" }));
  apiMock.put.mockResolvedValue(ok({ id: "terms-1" }));
});

afterEach(() => {
  cleanup();
});

describe("b2b commercial operations", () => {
  it("creates price lists and tiers through live contract routes", async () => {
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.change(screen.getByLabelText("Organization ID"), { target: { value: "c-1" } });
    fireEvent.change(screen.getByLabelText("Price list code"), { target: { value: "ENT-USD" } });
    fireEvent.click(screen.getByRole("button", { name: "Create price list" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/price-lists",
      expect.objectContaining({ code: "ENT-USD" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Price list ID (for tier)"), { target: { value: "pl-1" } });
    fireEvent.change(screen.getAllByLabelText("Variant ID")[1], { target: { value: "v-1" } });
    fireEvent.change(screen.getByLabelText("Unit price (minor)"), { target: { value: "999" } });
    fireEvent.click(screen.getByRole("button", { name: "Save price tier" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/price-lists/pl-1/tiers",
      expect.objectContaining({ variant_id: "v-1", unit_price_minor: 999 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("creates credit accounts, contracts, and commercial terms", async () => {
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.change(screen.getByLabelText("Organization ID"), { target: { value: "c-1" } });
    fireEvent.change(screen.getByLabelText("Credit limit in minor units"), { target: { value: "100000" } });
    fireEvent.click(screen.getByRole("button", { name: "Create credit account" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/credit-account",
      expect.objectContaining({ limit_minor: 100000 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Contract number"), { target: { value: "CTR-1" } });
    const contractButton = screen.getByRole("button", { name: "Create contract" });
    await waitFor(() => expect(contractButton).not.toBeDisabled());
    fireEvent.click(contractButton);
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/contracts",
      expect.objectContaining({ contract_number: "CTR-1" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    // Busy state disables actions until the previous mutation settles; wait for
    // the button to re-enable instead of racing the async resolution.
    const termsButton = screen.getByRole("button", { name: "Save commercial terms" });
    await waitFor(() => expect(termsButton).not.toBeDisabled());
    fireEvent.click(termsButton);
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/admin/b2b/companies/c-1/terms",
      expect.anything(),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("approves quotes through the idempotent quote contract", async () => {
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.click(screen.getByRole("tab", { name: "Quotes" }));
    fireEvent.change(screen.getByLabelText("Quote ID"), { target: { value: "q-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Approve quote" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/b2b/quotes/q-1/approve",
      undefined,
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});

describe("b2b vendor marketplace", () => {
  it("lists vendors and onboards a vendor through the live route", async () => {
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.click(screen.getByRole("tab", { name: "Vendors" }));
    expect(await screen.findByText("Acme Supply")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Vendor code"), { target: { value: "acme" } });
    fireEvent.change(screen.getByLabelText("Vendor name"), { target: { value: "Acme Supply Co." } });
    fireEvent.click(screen.getByRole("button", { name: "Onboard vendor" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/vendors",
      { code: "acme", name: "Acme Supply Co." },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("posts commission entries and creates/transitions payouts", async () => {
    render(<I18nProvider><B2bPage /></I18nProvider>);
    fireEvent.click(screen.getByRole("tab", { name: "Vendors" }));
    await screen.findByText("Acme Supply");
    fireEvent.change(screen.getAllByLabelText("Vendor ID")[1], { target: { value: "vendor-1" } });
    fireEvent.change(screen.getByLabelText("Gross (minor)"), { target: { value: "5000" } });
    fireEvent.change(screen.getByLabelText("Rate (bps)"), { target: { value: "1000" } });
    fireEvent.click(screen.getByRole("button", { name: "Post commission entry" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/commissions",
      expect.objectContaining({ vendor_id: "vendor-1", gross_minor: 5000, rate_bps: 1000 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    const payoutVendorInputs = screen.getAllByLabelText("Vendor ID");
    fireEvent.change(payoutVendorInputs[2], { target: { value: "vendor-1" } });
    fireEvent.change(screen.getByLabelText("Amount (minor)"), { target: { value: "2500" } });
    fireEvent.click(screen.getByRole("button", { name: "Create payout" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/payouts",
      expect.objectContaining({ vendor_id: "vendor-1", amount_minor: 2500 }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Payout ID"), { target: { value: "pay-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Transition payout" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/v2/admin/payouts/pay-1/transition",
      { state: "eligible" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });
});
