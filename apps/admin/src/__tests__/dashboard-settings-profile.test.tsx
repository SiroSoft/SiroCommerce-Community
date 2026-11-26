import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/providers/i18n-provider";
import { useAuthStore } from "@/store/auth.store";
import SettingsPage from "@/app/(dashboard)/settings/page";
import ProfilePage from "@/app/(dashboard)/profile/page";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }));
const themeMock = vi.hoisted(() => ({ theme: "light", setTheme: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  session: { accessToken: undefined },
  scopeCoordinator: { value: null },
}));
vi.mock("next-themes", () => ({ useTheme: () => ({ theme: themeMock.theme, setTheme: themeMock.setTheme }) }));

const authMock = vi.hoisted(() => ({ user: { id: 1, name: "Owner One", email: "owner@example.test", role: "owner", avatar: "", email_verified_at: null as string | null, created_at: "2026-01-02T03:04:05Z" } }));
const authUser = authMock.user;
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: authMock.user, isLoading: false, isAuthenticated: true }) }));

const body = (data: unknown) => Promise.resolve({ data: { data }, status: 200, headers: new Headers() });
const settingsPayload = {
  app_name: "Siro Admin",
  app_description: "Commerce control plane",
  language: "en",
  timezone: "UTC",
  currency: "USD",
  pagination_per_page: 15,
  maintenance_mode: false,
  email_notifications: true,
};

function renderWithProviders(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><I18nProvider>{ui}</I18nProvider></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  themeMock.theme = "light";
  authMock.user = { ...authMock.user, name: "Owner One", email: "owner@example.test", email_verified_at: null };
  useAuthStore.setState({ user: { role: "owner", name: "Owner One", email: "owner@example.test" } as never, isAuthenticated: true });
  apiMock.get.mockImplementation((path: string) => {
    if (path === "/api/settings") return body(settingsPayload);
    if (path === "/api/server/info") return body({ php_version: "8.3.1", server: "nginx", db_driver: "pgsql", current_time: "2026-02-03 10:00:00" });
    return body({});
  });
  apiMock.put.mockImplementation((path: string) => {
    if (path === "/api/settings") return body({ ...settingsPayload, app_name: "Shop Admin" });
    if (path === "/api/profile") return body({ ...authUser, name: "Owner Two" });
    if (path === "/api/profile/password") return body(null);
    return body({});
  });
  apiMock.post.mockResolvedValue(body(null));
});

afterEach(() => {
  cleanup();
});

describe("settings page", () => {
  it("loads settings, server info and changes the theme", async () => {
    renderWithProviders(<SettingsPage />);
    expect(await screen.findByDisplayValue("Siro Admin")).toBeVisible();
    expect(await screen.findByText("8.3.1")).toBeVisible();
    expect(screen.getByText("nginx")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: /Dark/ }));
    expect(themeMock.setTheme).toHaveBeenCalledWith("dark");
    fireEvent.click(screen.getByRole("button", { name: /System/ }));
    expect(themeMock.setTheme).toHaveBeenCalledWith("system");
  });

  it("saves general settings after confirming maintenance mode", async () => {
    renderWithProviders(<SettingsPage />);
    await screen.findByDisplayValue("Siro Admin");
    const nameInput = screen.getByLabelText("App Name");
    fireEvent.change(nameInput, { target: { value: "Shop Admin" } });
    fireEvent.change(screen.getByLabelText("Items Per Page"), { target: { value: "30" } });
    fireEvent.click(screen.getByRole("switch", { name: /Maintenance Mode/ }));
    const maintenance = await screen.findByRole("alertdialog");
    fireEvent.click(within(maintenance).getByRole("button", { name: "Confirm" }));
    fireEvent.click(screen.getByRole("switch", { name: /Email Notifications/ }));
    fireEvent.submit(nameInput.closest("form") as HTMLFormElement);
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/settings",
      expect.objectContaining({ app_name: "Shop Admin", pagination_per_page: 30, maintenance_mode: true, email_notifications: false }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("updates the profile and changes the password from the settings forms", async () => {
    renderWithProviders(<SettingsPage />);
    await screen.findByDisplayValue("Siro Admin");
    const nameInput = screen.getByLabelText("Name");
    fireEvent.change(nameInput, { target: { value: "Owner Two" } });
    fireEvent.submit(nameInput.closest("form") as HTMLFormElement);
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/profile",
      expect.objectContaining({ name: "Owner Two", email: "owner@example.test" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
    fireEvent.change(screen.getByLabelText("Password", { selector: "#s-current" }), { target: { value: "old-password" } });
    fireEvent.change(screen.getByLabelText("Password", { selector: "#s-new" }), { target: { value: "new-password-123" } });
    fireEvent.change(screen.getByLabelText("Confirm"), { target: { value: "new-password-123" } });
    fireEvent.submit(screen.getByLabelText("Confirm").closest("form") as HTMLFormElement);
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/profile/password",
      { current_password: "old-password", new_password: "new-password-123", new_password_confirmation: "new-password-123" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("fails closed for non-admin roles and shows a retryable settings error", async () => {
    useAuthStore.setState({ user: { role: "viewer" } as never, isAuthenticated: true });
    const { unmount } = renderWithProviders(<SettingsPage />);
    expect(await screen.findByText(/read-only access/i)).toBeVisible();
    unmount();
    useAuthStore.setState({ user: { role: "admin" } as never, isAuthenticated: true });
    apiMock.get.mockImplementation((path: string) => path === "/api/settings" ? Promise.reject(Object.assign(new Error("settings down"), { status: 422 })) : body({}));
    renderWithProviders(<SettingsPage />);
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(screen.getByRole("button", { name: /Try again/ })).toBeVisible();
  });
});

describe("profile page", () => {
  it("renders identity, resends verification and updates the profile", async () => {
    renderWithProviders(<ProfilePage />);
    expect(await screen.findByText("Owner One")).toBeVisible();
    expect(screen.getByText("owner@example.test")).toBeVisible();
    expect(screen.getByText("owner")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: /Resend/i }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith("/api/auth/verify-email/resend"));
    const nameInput = screen.getByLabelText("Name");
    fireEvent.change(nameInput, { target: { value: "Owner Two" } });
    fireEvent.submit(nameInput.closest("form") as HTMLFormElement);
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/profile",
      expect.objectContaining({ name: "Owner Two", email: "owner@example.test" }),
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("validates password mismatch and submits a valid password change", async () => {
    renderWithProviders(<ProfilePage />);
    await screen.findByText("Owner One");
    fireEvent.change(screen.getByLabelText("Password", { selector: "#current_password" }), { target: { value: "old" } });
    fireEvent.change(screen.getByLabelText("Password", { selector: "#new_password" }), { target: { value: "long-enough-1" } });
    fireEvent.change(screen.getByLabelText("Confirm"), { target: { value: "different-1" } });
    fireEvent.submit(screen.getByLabelText("Confirm").closest("form") as HTMLFormElement);
    expect(await screen.findByText(/match/i)).toBeVisible();
    expect(apiMock.put).not.toHaveBeenCalledWith("/api/profile/password", expect.anything(), expect.anything());
    fireEvent.change(screen.getByLabelText("Confirm"), { target: { value: "long-enough-1" } });
    fireEvent.submit(screen.getByLabelText("Confirm").closest("form") as HTMLFormElement);
    await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith(
      "/api/profile/password",
      { current_password: "old", new_password: "long-enough-1", new_password_confirmation: "long-enough-1" },
      expect.objectContaining({ idempotencyKey: expect.any(String) }),
    ));
  });

  it("shows a verified badge and cancels pending form edits", async () => {
    authMock.user = { ...authMock.user, email_verified_at: "2026-01-02T00:00:00Z", name: "Owner One" };
    renderWithProviders(<ProfilePage />);
    expect(await screen.findByText(/verified/i)).toBeVisible();
    const nameInput = screen.getByLabelText("Name") as HTMLInputElement;
    fireEvent.change(nameInput, { target: { value: "Changed" } });
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    await waitFor(() => expect(nameInput.value).toBe("Owner One"));
  });
});
