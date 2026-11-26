"use client";

import { useRef } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { authService } from "@/services/auth.service";
import { useAuthStore } from "@/store/auth.store";
import type { components } from "@/types/api";
import { scopedQueryKey } from "@/services/query-keys";

export function useAuth() {
  const router = useRouter();
  const { user, isAuthenticated, isLoading, login: storeLogin, logout: storeLogout, setLoading } =
    useAuthStore();
  const authGeneration = useRef(0);

  const loginMutation = useMutation({
    mutationFn: ({ data }: { data: components["schemas"]["LoginRequest"]; generation: number }) => authService.login(data),
    onSuccess: (res, variables) => {
       if (variables.generation !== authGeneration.current) return;
      const payload = res.data;
      if (!payload?.user || !payload?.token || !payload?.refresh_token) {
        const toastEvent = new CustomEvent("app:toast", {
          detail: { title: "Login Error", description: "Invalid server response. Please try again.", variant: "destructive" },
        });
        if (typeof window !== "undefined") window.dispatchEvent(toastEvent);
        return;
      }
       if (storeLogin(payload.user, payload.token, payload.refresh_token)) router.push("/");
    },
  });

  const logoutMutation = useMutation({
    mutationFn: ({ generation }: { generation: number }) => authService.logout(),
    onSettled: (_data, _error, variables) => {
      if (variables.generation !== authGeneration.current) return;
      storeLogout();
      router.push("/login");
    },
  });

  const sessionQuery = useQuery({
    queryKey: scopedQueryKey("auth", "session"),
    queryFn: async () => {
      setLoading(true);
      try {
        const userData = await authService.me();
        useAuthStore.getState().setUser(userData ?? null);
        return userData;
      } catch (error) {
        // Only an authentication failure means the session is dead. Rate
        // limits, outages, or validation errors must not wipe a valid login.
        const status = (error as { status?: unknown })?.status;
        if (status === 401) useAuthStore.getState().logout();
        return null;
      } finally {
        setLoading(false);
      }
    },
    enabled: isAuthenticated,
    retry: false,
    staleTime: 0,
    refetchOnMount: true,
  });

  return {
    user,
    isAuthenticated,
    isLoading: isLoading || sessionQuery.isLoading,
    login: (data: components["schemas"]["LoginRequest"]) => {
      const generation = ++authGeneration.current;
      loginMutation.mutate({ data, generation });
    },
    loginError: loginMutation.error,
    isLoginPending: loginMutation.isPending,
    logout: () => {
      const generation = ++authGeneration.current;
      logoutMutation.mutate({ generation });
    },
    refetchSession: sessionQuery.refetch,
  };
}
