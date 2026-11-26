import { describe, it, expect, beforeEach } from "vitest";
import { useAuthStore } from "@/store/auth.store";
import { authStorage } from "@/services/auth-storage";

describe("auth.store", () => {
  const mockUser = { id: 1, name: "Test", email: "test@test.com", role: "admin", status: 1 };
  const validToken = `eyJhbGciOiJub25lIn0.${btoa(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 3600 })).replace(/=+$/, "")}.signature`;

  beforeEach(() => {
    localStorage.clear();
    authStorage.clear();
    useAuthStore.setState({ user: null, isAuthenticated: false, isLoading: true });
  });

  it("starts unauthenticated", () => {
    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
  });

  it("sets user on login", () => {
    useAuthStore.getState().login(mockUser, validToken, "refresh-token");
    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(true);
    expect(state.user?.name).toBe("Test");
  });

  it("clears user on logout", () => {
    useAuthStore.getState().login(mockUser, validToken, "rt");
    useAuthStore.getState().logout();
    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.user).toBeNull();
  });

  it("keeps access tokens in memory and only persists the refresh fallback", () => {
    useAuthStore.getState().login(mockUser, validToken, "my-refresh-token");
    expect(localStorage.getItem("siro_access_token")).toBeNull();
    expect(localStorage.getItem("siro_refresh_token")).toBe("my-refresh-token");
    expect(authStorage.getAccessToken()).toBe(validToken);
  });

  it("leaves a refresh-backed session pending for startup refresh", () => {
    localStorage.setItem("siro_access_token", validToken);
    localStorage.setItem("siro_refresh_token", "rt");
    localStorage.setItem("siro_user", JSON.stringify(mockUser));
    useAuthStore.getState().restoreSession();
    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.isLoading).toBe(true);
    expect(localStorage.getItem("siro_refresh_token")).toBe("rt");
  });

  it("rejects malformed persisted access tokens instead of restoring the user", () => {
    localStorage.setItem("siro_access_token", "not-a-jwt");
    localStorage.setItem("siro_refresh_token", "rt");
    localStorage.setItem("siro_user", JSON.stringify(mockUser));

    useAuthStore.getState().restoreSession();

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(useAuthStore.getState().user).toBeNull();
    expect(localStorage.getItem("siro_access_token")).toBeNull();
    expect(localStorage.getItem("siro_user")).toBeNull();
  });

  it("rejects malformed tokens returned by login", () => {
    expect(useAuthStore.getState().login(mockUser, "not-a-jwt", "refresh-token")).toBe(false);
    expect(useAuthStore.getState()).toMatchObject({ user: null, isAuthenticated: false });
  });

  it("clears stale auth state when a persisted token is expired", () => {
    const expiredToken = `eyJhbGciOiJub25lIn0.${btoa(JSON.stringify({ exp: 1 })).replace(/=+$/, "")}.signature`;
    useAuthStore.setState({ user: mockUser, isAuthenticated: true, isLoading: true });
    localStorage.setItem("siro_access_token", expiredToken);
    localStorage.setItem("siro_refresh_token", "rt");
    localStorage.setItem("siro_user", JSON.stringify(mockUser));

    useAuthStore.getState().restoreSession();

    expect(useAuthStore.getState()).toMatchObject({ user: null, isAuthenticated: false, isLoading: false });
    expect(localStorage.getItem("siro_refresh_token")).toBeNull();
  });

  it("clears corrupted persisted user data instead of restoring it", () => {
    localStorage.setItem("siro_access_token", validToken);
    localStorage.setItem("siro_refresh_token", "rt");
    localStorage.setItem("siro_user", "{not-json");

    useAuthStore.getState().restoreSession();

    expect(useAuthStore.getState()).toMatchObject({ user: null, isAuthenticated: false, isLoading: false });
    expect(localStorage.getItem("siro_access_token")).toBeNull();
  });
});
