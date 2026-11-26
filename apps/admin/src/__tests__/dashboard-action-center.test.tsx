import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import ActionCenterPage from "@/app/(dashboard)/action-center/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/admin/smart/action-center") return body([{ id: "a-1", title: "Failed payment", description: "Retry capture" }]);
    if (path === "/api/admin/smart/search") return body({ orders: [{ id: 1 }] });
    return body({});
  });
  apiMock.post.mockImplementation((path: string) => {
    if (path === "/api/admin/smart/rescue/a-1") return body({ id: "a-1", fixed: true });
    return body({ ok: true });
  });
});

afterEach(() => {
  cleanup();
});

describe("action center page", () => {
  it("renders pending actions and runs a rescue after confirmation", async () => {
    renderWithProviders(<ActionCenterPage />);
    expect(await screen.findByText("Failed payment")).toBeVisible();
    expect(screen.getByText("Retry capture")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Run rescue" }));
    const dialog = await screen.findByRole("dialog", { name: "Run rescue" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/smart/rescue/a-1"));
    expect(await screen.findByText("Rescue accepted for a-1.")).toBeVisible();
    expect(await screen.findByText(/"fixed": true/)).toBeVisible();
  });

  it("executes a smart command and runs an operation search", async () => {
    renderWithProviders(<ActionCenterPage />);
    await screen.findByText("Failed payment");
    fireEvent.change(screen.getByLabelText("Smart command"), { target: { value: "reconcile-payments" } });
    fireEvent.click(screen.getByRole("button", { name: "Execute" }));
    const dialog = await screen.findByRole("dialog", { name: "Execute command" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Confirm" }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/admin/smart/execute", { command: "reconcile-payments" }));
    expect(await screen.findByText("Command executed.")).toBeVisible();
    fireEvent.change(screen.getByLabelText("Search query"), { target: { value: "order" } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith("/api/admin/smart/search", { params: { q: "order" } }));
    expect(await screen.findByText(/"orders"/)).toBeVisible();
  });

  it("surfaces rescue and command failures as notices", async () => {
    apiMock.post.mockRejectedValueOnce(new Error("rescue denied"));
    renderWithProviders(<ActionCenterPage />);
    await screen.findByText("Failed payment");
    fireEvent.click(screen.getByRole("button", { name: "Run rescue" }));
    const rescue = await screen.findByRole("dialog", { name: "Run rescue" });
    fireEvent.click(within(rescue).getByRole("button", { name: "Confirm" }));
    expect(await screen.findByText("rescue denied")).toBeVisible();
    apiMock.post.mockRejectedValueOnce(new Error("command denied"));
    fireEvent.change(screen.getByLabelText("Smart command"), { target: { value: "bad" } });
    fireEvent.click(screen.getByRole("button", { name: "Execute" }));
    const execute = await screen.findByRole("dialog", { name: "Execute command" });
    fireEvent.click(within(execute).getByRole("button", { name: "Confirm" }));
    expect(await screen.findByText("command denied")).toBeVisible();
    apiMock.get.mockRejectedValueOnce(new Error("search denied"));
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    expect(await screen.findByText("search denied")).toBeVisible();
  });

  it("renders the empty state, retryable error and role gate", async () => {
    apiMock.get.mockResolvedValueOnce(body([]));
    const { unmount } = renderWithProviders(<ActionCenterPage />);
    expect(await screen.findByText("No actions require attention.")).toBeVisible();
    unmount();
    apiMock.get.mockRejectedValueOnce(Object.assign(new Error("down"), { status: 422 }));
    renderWithProviders(<ActionCenterPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
    cleanup();
    useAuthStore.setState({ user: { role: "viewer" } as never, isAuthenticated: true });
    renderWithProviders(<ActionCenterPage />);
    expect(await screen.findByText(/read-only access/i)).toBeVisible();
  });
});
