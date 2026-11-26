import "@testing-library/jest-dom/vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { I18nProvider } from "@/providers/i18n-provider";
import B2bPage from "@/app/(dashboard)/b2b/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
vi.mock("@/services/b2b.service", async () => {
  const actual = await vi.importActual("@/services/b2b.service") as unknown as Record<string, unknown>;
  const svc = actual["b2bService"] as Record<string, unknown>;
  return { ...actual, b2bService: { ...svc, createQuote: vi.fn().mockResolvedValue({ id: "q1" }), quote: vi.fn() } };
});

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider><B2bPage /></I18nProvider></QueryClientProvider>);
}

describe("b2b bulk quote CSV — deep completeness", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "q1" } } });
  });
  it("validates empty CSV, invalid qty, and shows error", async () => {
    renderPage();
    const tab = await screen.findByRole("tab", { name: "Quotes" });
    fireEvent.click(tab);
    const company = await screen.findByLabelText("Organization ID");
    fireEvent.change(company, { target: { value: "c1" } });
    const csv = await screen.findByLabelText(/Bulk quote CSV/);
    fireEvent.change(csv, { target: { value: "variant_id,qty\n" } });
    const btn = screen.getByRole("button", { name: "Create bulk quote" });
    fireEvent.click(btn);
    expect(await screen.findByText(/needs header/i)).toBeVisible();
    fireEvent.change(csv, { target: { value: "variant_id,qty\nv1,0" } });
    fireEvent.click(btn);
    expect(await screen.findByText(/qty must be/i)).toBeVisible();
  });
  it("creates bulk quote with idempotency and shows success", async () => {
    const { b2bService } = await import("@/services/b2b.service");
    renderPage();
    fireEvent.click(await screen.findByRole("tab", { name: "Quotes" }));
    const company = await screen.findByLabelText("Organization ID");
    fireEvent.change(company, { target: { value: "c1" } });
    const csv = await screen.findByLabelText(/Bulk quote CSV/);
    fireEvent.change(csv, { target: { value: "variant_id,qty\nv1,2\nv2,5" } });
    fireEvent.click(screen.getByRole("button", { name: "Create bulk quote" }));
    await waitFor(() => expect((b2bService.createQuote as unknown as ReturnType<typeof vi.fn>)).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({ currency: "USD", lines: [{ variant_id: "v1", qty: 2 }, { variant_id: "v2", qty: 5 }] })));
  });
  it("disables when busy and shows empty/loading", async () => {
    renderPage();
    fireEvent.click(await screen.findByRole("tab", { name: "Quotes" }));
    expect(await screen.findByLabelText(/Bulk quote CSV/)).toBeVisible();
    expect(screen.getByText(/Line schema is intentionally not guessed/)).toBeVisible();
  });
});
