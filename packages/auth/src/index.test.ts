import { describe, expect, it, vi } from "vitest";
import { AuthSessionCoordinator, browserAuthStorage, isValidJwt, type AuthStorage, type AuthTokens } from "./index";

function storage(initial: Partial<{ access: string | null; refresh: string | null }> = {}): AuthStorage & { values: { access: string | null; refresh: string | null } } {
  const values = { access: initial.access ?? null, refresh: initial.refresh ?? null }; return { values, getAccessToken: () => values.access, setAccessToken: token => { values.access = token; }, getRefreshToken: () => values.refresh, setTokens: tokens => { values.access = tokens.token; values.refresh = tokens.refresh_token; }, clear: () => { values.access = null; values.refresh = null; } };
}
const tokens: AuthTokens = { token: "a.eyJleHAiOjk5OTk5OTk5OTl9.c", refresh_token: "new-refresh" };
describe("AuthSessionCoordinator", () => {
  it("shares one refresh flight across concurrent callers and stores tokens", async () => {
    const state = storage({ access: "old", refresh: "refresh" }); let resolve!: (value: AuthTokens) => void; const refresh = vi.fn(() => new Promise<AuthTokens>(r => { resolve = r; })); const auth = new AuthSessionCoordinator(state, { refresh });
    const calls = Promise.all(Array.from({ length: 10 }, () => auth.refresh())); resolve(tokens); expect(await calls).toEqual(Array(10).fill(true)); expect(refresh).toHaveBeenCalledTimes(1); expect(state.values).toEqual({ access: tokens.token, refresh: "new-refresh" });
  });
  it("handles absent and failed refresh tokens by logging out", async () => {
    const absent = storage(); expect(await new AuthSessionCoordinator(absent, { refresh: vi.fn() }).refresh()).toBe(false);
    const failed = storage({ access: "old", refresh: "refresh" }); const auth = new AuthSessionCoordinator(failed, { refresh: async () => { throw new Error("expired"); } }); expect(await auth.refresh()).toBe(false); expect(failed.values).toEqual({ access: null, refresh: null });
  });
  it("does not let a refresh response replay after logout or replace a newer session", async () => {
    const state = storage({ access: "old", refresh: "old-refresh" });
    let resolve!: (value: AuthTokens) => void;
    const auth = new AuthSessionCoordinator(state, { refresh: () => new Promise<AuthTokens>((r) => { resolve = r; }) });
    const pending = auth.refresh();
    await auth.logout();
    state.setTokens({ token: "new", refresh_token: "new-refresh" });
    resolve({ token: "stale", refresh_token: "stale-refresh" });
    expect(await pending).toBe(false);
    expect(state.values).toEqual({ access: "new", refresh: "new-refresh" });
  });
  it("rejects malformed and expired JWT payloads", () => {
    expect(isValidJwt("not.jwt")).toBe(false);
    expect(isValidJwt("a.eyJleHAiOjF9.c")).toBe(false);
    expect(isValidJwt("a.eyJleHAiOiJub3QtbnVtYmVyIn0.c")).toBe(false);
  });
  it("contains corrupted and unavailable browser storage", () => {
    const values = new Map<string, string>([["siro_access_token", "not-a-jwt"], ["siro_refresh_token", "refresh"]]);
    const localStorage = { getItem: (key: string) => values.get(key) ?? null, setItem: () => { throw new Error("quota"); }, removeItem: (key: string) => values.delete(key) };
    vi.stubGlobal("window", { localStorage, addEventListener: vi.fn(), removeEventListener: vi.fn() });
    const state = browserAuthStorage();
    expect(state.getAccessToken()).toBeNull();
    expect(() => state.setAccessToken("not-a-jwt")).not.toThrow();
    expect(() => state.clear()).not.toThrow();
    state.dispose?.();
    vi.unstubAllGlobals();
  });
  it("keeps access tokens out of browser storage and fails closed after reload", () => {
    const values = new Map<string, string>();
    const localStorage = { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => values.set(key, value), removeItem: (key: string) => values.delete(key) };
    vi.stubGlobal("window", { localStorage, addEventListener: vi.fn(), removeEventListener: vi.fn() });
    const state = browserAuthStorage();
    state.setTokens(tokens);
    expect(state.getAccessToken()).toBe(tokens.token);
    expect(values.get("siro_access_token")).toBeUndefined();
    const reloaded = browserAuthStorage();
    expect(reloaded.getAccessToken()).toBeNull();
    expect(reloaded.getRefreshToken()).toBe(tokens.refresh_token);
    state.dispose?.(); reloaded.dispose?.(); vi.unstubAllGlobals();
  });
  it("clears local state and calls remote logout even when it fails", async () => {
    const state = storage({ access: "access", refresh: "refresh" }); const logout = vi.fn(async () => { throw new Error("offline"); }); await expect(new AuthSessionCoordinator(state, { refresh: vi.fn(), logout }).logout()).rejects.toThrow("offline"); expect(logout).toHaveBeenCalledWith("access"); expect(state.values).toEqual({ access: null, refresh: null });
  });
  it("synchronizes login and logout storage events across browser tabs", () => {
    const values = new Map<string, string>(); const listeners = new Set<(event: StorageEvent) => void>();
    const localStorage = { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => values.set(key, value), removeItem: (key: string) => values.delete(key) };
    vi.stubGlobal("window", { localStorage, addEventListener: (_name: string, listener: (event: StorageEvent) => void) => listeners.add(listener), removeEventListener: (_name: string, listener: (event: StorageEvent) => void) => listeners.delete(listener) });
    const first = browserAuthStorage(); const second = browserAuthStorage();
    first.setTokens(tokens); expect(second.getAccessToken()).toBeNull();
    first.clear(); for (const listener of listeners) listener({ key: "siro_logout_event", newValue: "logout-1" } as StorageEvent); expect(second.getAccessToken()).toBeNull();
    first.dispose?.(); second.dispose?.(); vi.unstubAllGlobals();
  });
});
