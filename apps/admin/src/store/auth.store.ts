import { create } from "zustand";
import type { components } from "@/types/api";
import { STORAGE_KEYS } from "@/lib/constants";
import { isValidJwt } from "@sirocommerce/auth";
import { authStorage } from "@/services/auth-storage";

type User = components["schemas"]["User"];

interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  setUser: (user: User | null) => void;
  setLoading: (loading: boolean) => void;
  login: (user: User, accessToken: string, refreshToken: string) => boolean;
  logout: () => void;
  restoreSession: () => User | null;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  isAuthenticated: false,
  isLoading: true,

  setUser: (user) =>
    set({ user, isAuthenticated: !!user }),

  setLoading: (isLoading) =>
    set({ isLoading }),

  login: (user, accessToken, refreshToken) => {
    if (!isValidJwt(accessToken) || typeof refreshToken !== "string" || !refreshToken.trim()) {
      clearPersistedSession();
      set({ user: null, isAuthenticated: false, isLoading: false });
      return false;
    }
    try {
      authStorage.setTokens({ token: accessToken, refresh_token: refreshToken });
      localStorage.setItem(STORAGE_KEYS.USER, JSON.stringify(user));
    } catch {
      // A quota/private-mode failure must not leave a partial persisted session.
      clearPersistedSession();
    }
    set({ user, isAuthenticated: true, isLoading: false });
    return true;
  },

  logout: () => {
    authStorage.clear();
    clearPersistedSession();
    set({ user: null, isAuthenticated: false, isLoading: false });
  },

  restoreSession: () => {
    try {
      const userStr = localStorage.getItem(STORAGE_KEYS.USER);
      const persistedAccess = localStorage.getItem(STORAGE_KEYS.ACCESS_TOKEN);
      if (persistedAccess && !isValidJwt(persistedAccess)) throw new Error("Invalid persisted access token");
      if (userStr) {
        const user = JSON.parse(userStr) as User;
        if (!user || typeof user !== "object" || !user.id || typeof user.email !== "string") throw new Error("Invalid user session");
      }
      if (authStorage.getAccessToken() && authStorage.getRefreshToken() && userStr) {
        const user = JSON.parse(userStr) as User;
        set({ user, isAuthenticated: true, isLoading: false });
        return user;
      }
      if (authStorage.getRefreshToken() && userStr) {
        // AuthProvider exchanges the refresh token before protected routes redirect.
        set({ user: null, isAuthenticated: false, isLoading: true });
        return null;
      }
      // A reload cannot recover the memory-only access token. Do not keep a
      // partial session when there is no user record to restore.
      clearPersistedSession();
      set({ user: null, isAuthenticated: false });
    } catch {
      clearPersistedSession();
      set({ user: null, isAuthenticated: false });
    }
    set({ isLoading: false });
    return null;
  },
}));

function clearPersistedSession() {
  try {
    localStorage.removeItem(STORAGE_KEYS.ACCESS_TOKEN);
    localStorage.removeItem(STORAGE_KEYS.REFRESH_TOKEN);
    localStorage.removeItem(STORAGE_KEYS.USER);
  } catch { /* Storage can be unavailable or full. */ }
}
