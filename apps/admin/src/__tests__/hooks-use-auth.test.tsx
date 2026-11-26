import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const authServiceMock = vi.hoisted(() => ({
  login: vi.fn(), logout: vi.fn(), me: vi.fn(),
}));
const pushMock = vi.hoisted(() => vi.fn());
vi.mock("@/services/auth.service", () => ({ authService: authServiceMock }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: pushMock }) }));

import { useAuth } from "@/hooks/use-auth";
import { useAuthStore } from "@/store/auth.store";

function jwt(): string {
  const payload = btoa(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 3600 }));
  return `header.${payload}.signature`;
}

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return { client, wrapper };
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  useAuthStore.setState({ user: null, isAuthenticated: false, isLoading: false });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("useAuth", () => {
  it("logs in with a valid token payload and routes home", async () => {
    const token = jwt();
    authServiceMock.login.mockResolvedValueOnce({ data: { user: { id: 1, email: "a@b.c" }, token, refresh_token: "refresh-1" } });
    authServiceMock.me.mockResolvedValue({ id: 1, email: "a@b.c" });
    const { wrapper } = setup();
    const { result } = renderHook(() => useAuth(), { wrapper });

    await act(async () => { result.current.login({ email: "a@b.c", password: "pw" } as never); });
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
    expect(authServiceMock.login).toHaveBeenCalledWith({ email: "a@b.c", password: "pw" });
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });

  it("emits a toast and keeps the session logged out for malformed login responses", async () => {
    authServiceMock.login.mockResolvedValueOnce({ data: { user: null } });
    const listener = vi.fn();
    window.addEventListener("app:toast", listener);
    const { wrapper } = setup();
    const { result } = renderHook(() => useAuth(), { wrapper });

    await act(async () => { result.current.login({ email: "a@b.c", password: "pw" } as never); });
    await waitFor(() => expect(listener).toHaveBeenCalled());
    expect(pushMock).not.toHaveBeenCalled();
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    window.removeEventListener("app:toast", listener);
  });

  it("logs out, clears the session and routes to login", async () => {
    useAuthStore.setState({ user: { id: 1 } as never, isAuthenticated: true, isLoading: false });
    authServiceMock.logout.mockResolvedValueOnce(undefined);
    const { wrapper } = setup();
    const { result } = renderHook(() => useAuth(), { wrapper });

    await act(async () => { result.current.logout(); });
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/login"));
    expect(authServiceMock.logout).toHaveBeenCalled();
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });

  it("loads the session user when authenticated", async () => {
    useAuthStore.setState({ user: { id: 1 } as never, isAuthenticated: true, isLoading: false });
    authServiceMock.me.mockResolvedValueOnce({ id: 1, email: "a@b.c" });
    const { wrapper } = setup();
    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.user).toMatchObject({ id: 1 }));
    expect(authServiceMock.me).toHaveBeenCalled();
  });

  it("clears the session only on a 401 session check", async () => {
    useAuthStore.setState({ user: { id: 1 } as never, isAuthenticated: true, isLoading: false });
    authServiceMock.me.mockRejectedValueOnce({ status: 401, message: "expired" });
    const { wrapper } = setup();
    const first = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(false));
    first.unmount();

    useAuthStore.setState({ user: { id: 2 } as never, isAuthenticated: true, isLoading: false });
    authServiceMock.me.mockRejectedValueOnce({ status: 429, message: "busy" });
    renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(authServiceMock.me).toHaveBeenCalledTimes(2));
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });

  it("does not fetch a session while logged out but exposes refetch", async () => {
    const { wrapper } = setup();
    const { result } = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(authServiceMock.me).not.toHaveBeenCalled();
    authServiceMock.me.mockResolvedValueOnce({ id: 9 });
    await act(async () => { await result.current.refetchSession(); });
    expect(authServiceMock.me).toHaveBeenCalled();
  });
});
