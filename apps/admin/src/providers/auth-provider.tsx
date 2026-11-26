"use client";

import { useEffect, useRef } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useAuthStore } from "@/store/auth.store";
import { authStorage } from "@/services/auth-storage";
import { session } from "@/services/api";

const publicPaths = ["/login", "/forgot-password", "/register", "/reset-password", "/verify-email"];

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { isAuthenticated, isLoading, restoreSession } = useAuthStore();
  const refreshing = useRef(false);

  useEffect(() => {
    restoreSession();
  }, [restoreSession]);

  // Session restore must share the AuthSessionCoordinator single-flight with
  // the API client's 401 retry path. A direct authService.refresh() here can
  // race it: the backend rotates refresh tokens, so the loser replays a
  // revoked jti and logs out a perfectly valid session.
  useEffect(() => {
    if (isAuthenticated || refreshing.current) return;
    const refreshToken = authStorage.getRefreshToken();
    const userJson = typeof window !== "undefined" ? window.localStorage.getItem("siro_user") : null;
    if (!refreshToken || !userJson) {
      useAuthStore.getState().setLoading(false);
      return;
    }
    refreshing.current = true;
    // Hold the loading gate while the exchange is in flight so the route
    // guard below cannot bounce to /login before the session is restored.
    useAuthStore.getState().setLoading(true);
    // A false return means a concurrent flight already rotated the tokens;
    // storage then holds the fresh session, so never log out on false alone.
    session.refresh().then(() => {
      const user = JSON.parse(userJson);
      const accessToken = authStorage.getAccessToken();
      const nextRefreshToken = authStorage.getRefreshToken();
      if (!accessToken || !nextRefreshToken) throw new Error("Invalid refresh response");
      useAuthStore.getState().login(user, accessToken, nextRefreshToken);
    }).catch(() => {
      // Only wipe the session when no refresh token survives. A transient
      // failure (rate limit, outage) with a stored token must not log out;
      // the next mount retries the exchange.
      if (!authStorage.getRefreshToken()) useAuthStore.getState().logout();
      else useAuthStore.getState().setLoading(false);
    }).finally(() => {
      refreshing.current = false;
    });
  }, [isAuthenticated, isLoading]);

  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
       if (event.key === "siro_refresh_token" || event.key === "siro_logout_event" || event.key === "siro_user") restoreSession();
    };
    window.addEventListener("storage", onStorage);
    return () => window.removeEventListener("storage", onStorage);
  }, [restoreSession]);

  useEffect(() => {
    if (isLoading) return;
    if (!isAuthenticated && !publicPaths.includes(pathname)) {
      router.push("/login");
    }
    if (isAuthenticated && publicPaths.includes(pathname)) {
      router.push("/");
    }
  }, [isAuthenticated, isLoading, pathname, router]);

  return <>{children}</>;
}
