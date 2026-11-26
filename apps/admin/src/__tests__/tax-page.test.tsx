import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import TaxPage from "@/app/(dashboard)/tax/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));

const envelope = (body: unknown) => Promise.resolve({ data: body, status: 200, headers: new Headers() });
const rule = {
  id: "t-1",
  code: "vn-standard",
  destination_country: "VN",
  destination_region: "HCM",
  destination_postal_code: null,
  tax_class: "standard",
  rate_basis_points: 1000,
  priority: 1,
  state: "active",
  compound: 0,
  effective_at: "2026-01-01 00:00:00",
  expires_at: null,
};

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "finance" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation(() => envelope({ data: [rule] }));
  apiMock.post.mockResolvedValue({ data: { data: { ...rule, id: "t-2", code: "us-reduced" } }, status: 201, headers: new Headers() });
  apiMock.put.mockResolvedValue({ data: { data: { ...rule, compound: 1 } }, status: 200, headers: new Headers() });
  apiMock.delete.mockResolvedValue({ data: { data: null }, status: 200, headers: new Headers() });
});

afterEach(() => {
  cleanup();
  useAuthStore.setState({ user: null, isAuthenticated: false });
});

describe("tax rules admin page", () => {
  it("renders tax rules with destination, rate, compound, state, and window", async () => {
    renderWithProviders(<TaxPage />);
    expect(await screen.findByText("vn-standard")).toBeVisible();
    expect(screen.getByText("VN / HCM")).toBeVisible();
    expect(screen.getByText("10%")).toBeVisible();
    expect(screen.getByText("Single")).toBeVisible();
    expect(screen.getByText("Active")).toBeVisible();
  });

  it("creates a tax rule through the admin contract", async () => {
    renderWithProviders(<TaxPage />);
    expect(await screen.findByText("vn-standard")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "New tax rule" }));
    const dialog = await screen.findByRole("dialog");
    fireEvent.change(within(dialog).getByLabelText("Code *"), { target: { value: "us-reduced" } });
    fireEvent.change(within(dialog).getByLabelText("Country (ISO2)"), { target: { value: "us" } });
    fireEvent.change(within(dialog).getByLabelText("Rate (basis points) *"), { target: { value: "500" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create rule" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/tax-rules",
      expect.objectContaining({ code: "us-reduced", destination_country: "US", rate_basis_points: 500, compound: false }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("deletes a tax rule only after confirmation", async () => {
    renderWithProviders(<TaxPage />);
    expect(await screen.findByText("vn-standard")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Delete vn-standard" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/stop participating in quotes/i)).toBeVisible();
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith(
      "/api/admin/tax-rules/t-1",
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("renders the empty state when no rules match", async () => {
    apiMock.get.mockImplementation(() => envelope({ data: [] }));
    renderWithProviders(<TaxPage />);
    expect(await screen.findByText("No tax rules configured.")).toBeVisible();
  });
});
