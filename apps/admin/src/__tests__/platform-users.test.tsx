import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import UserDetailPage from "@/app/(dashboard)/users/[id]/page";
import NewUserPage from "@/app/(dashboard)/users/new/page";
import { useAuthStore } from "@/store/auth.store";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next/link", () => ({ default: ({ children, href, ...props }: { children: React.ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));

const routeParams = vi.hoisted(() => ({ id: "1" }));
const pushMock = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock, replace: vi.fn() }),
  useParams: () => ({ id: routeParams.id }),
  usePathname: () => "/users/1",
}));

const ok = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const user = { id: 1, name: "Ada Admin", email: "ada@example.test", role: "admin", status: "active", created_at: "2026-09-23T10:00:00Z" };

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  routeParams.id = "1";
  useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/users/1") return ok(user);
    return ok({});
  });
  apiMock.post.mockResolvedValue(ok(user));
  apiMock.put.mockResolvedValue(ok(user));
  apiMock.delete.mockResolvedValue({ data: {}, status: 204, headers: new Headers() });
});

afterEach(() => {
  cleanup();
});

describe("users detail page", () => {
  it("loads the real user instead of redirecting", async () => {
    renderWithProviders(<UserDetailPage />);
    expect((await screen.findAllByText("Ada Admin"))[0]).toBeVisible();
    expect(screen.getByText("ada@example.test")).toBeVisible();
    expect(apiMock.get).toHaveBeenCalledWith("/api/users/1");
  });

  it("opens the edit dialog and saves through the update contract", async () => {
    renderWithProviders(<UserDetailPage />);
    expect((await screen.findAllByText("Ada Admin")).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole("button", { name: /edit/i }));
    expect(await screen.findByRole("dialog")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: /^save$/i }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith("/api/users/1", expect.objectContaining({ name: "Ada Admin" }), expect.objectContaining({ idempotencyKey: expect.any(String) })));
  });

  it("deletes the user and returns to the directory", async () => {
    renderWithProviders(<UserDetailPage />);
    expect((await screen.findAllByText("Ada Admin")).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole("button", { name: /^delete$/i }));
    const confirmButtons = await screen.findAllByRole("button", { name: /^delete$/i });
    fireEvent.click(confirmButtons[confirmButtons.length - 1]);
    await waitFor(() => expect(apiMock.delete).toHaveBeenCalledWith("/api/users/1", expect.objectContaining({ idempotencyKey: expect.any(String) })));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/users"));
  });

  it("fails closed for an invalid user id", async () => {
    routeParams.id = "not-a-user";
    renderWithProviders(<UserDetailPage />);
    expect(await screen.findByText(/not valid/i)).toBeVisible();
    expect(apiMock.get).not.toHaveBeenCalled();
  });
});

describe("users new page", () => {
  it("creates a user through the create contract and returns to the directory", async () => {
    renderWithProviders(<NewUserPage />);
    fireEvent.change(screen.getByLabelText(/^Name/, { selector: "input" }), { target: { value: "New Member" } });
    fireEvent.change(screen.getByLabelText(/^Email/, { selector: "input" }), { target: { value: "member@example.test" } });
    fireEvent.change(screen.getByLabelText(/^Password \*/, { selector: "input" }), { target: { value: "Password123!" } });
    fireEvent.change(screen.getByLabelText(/^Confirm/, { selector: "input" }), { target: { value: "Password123!" } });
    fireEvent.click(screen.getByRole("button", { name: /create/i }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/users", expect.objectContaining({ name: "New Member", email: "member@example.test" }), expect.objectContaining({ idempotencyKey: expect.any(String) })));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/users"));
  });
});
