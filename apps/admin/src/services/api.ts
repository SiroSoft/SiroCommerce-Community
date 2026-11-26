import { RequestCoordinator } from "@sirocommerce/api-client";
import { AuthSessionCoordinator } from "@sirocommerce/auth";
import type { CommerceScope } from "@sirocommerce/scope";
import { ScopeCoordinator } from "@sirocommerce/scope";
import { API_URL } from "@/lib/constants";
import { AdminApiError, toAdminApiError } from "./api-errors";
import { authStorage } from "./auth-storage";

export interface ApiResponse<T = any> { data: T; status: number; headers: Headers; correlationId?: string; }
interface ApiOptions { signal?: AbortSignal; headers?: Record<string, string>; params?: Record<string, unknown>; idempotencyKey?: string; scope?: CommerceScope; cartToken?: string; credentials?: RequestCredentials; csrfToken?: string; }

const fetchCurrent: typeof fetch = (...args) => globalThis.fetch(...args);
let authGeneration = 0;
const refreshClient = new RequestCoordinator({ baseUrl: API_URL, version: "v1", fetch: fetchCurrent, credentials: "omit" });
export const session = new AuthSessionCoordinator(authStorage, {
  async refresh(refreshToken, signal) {
    const generation = authGeneration;
    const result = await refreshClient.request("/api/auth/refresh", { method: "POST", body: { refresh_token: refreshToken }, signal, retry: false });
    const tokens = (result.body as { data?: { token?: string; refresh_token?: string; token_type?: string; expires_in?: number } }).data;
    if (!tokens?.token || !tokens.refresh_token) throw new Error("Refresh response did not contain tokens");
    if (generation !== authGeneration) throw new Error("Refresh completed after logout");
    return { token: tokens.token, refresh_token: tokens.refresh_token, token_type: tokens.token_type, expires_in: tokens.expires_in };
  },
  async logout(accessToken) {
    authGeneration++;
    if (accessToken) await refreshClient.request("/api/auth/logout", { method: "POST", accessToken, retry: false });
  },
});

export const scopeCoordinator = new ScopeCoordinator();
export const setApiScope = (scope: CommerceScope) => scopeCoordinator.set(scope);
export const clearApiScope = () => scopeCoordinator.clear();

function defaultHeaders(): Record<string, string> {
  const headers: Record<string, string> = {};
  const feToken = typeof process !== "undefined" ? process.env.NEXT_PUBLIC_FE_TOKEN : undefined;
  if (feToken) headers["X-Siro-FE"] = feToken;
  headers["X-Locale"] = typeof window !== "undefined" ? localStorage.getItem("siro_locale") || "en" : "en";
  const scope = typeof window !== "undefined" ? {
    store_id: localStorage.getItem("siro_store_id") || "default",
    channel_id: localStorage.getItem("siro_channel_id") || "default",
    locale: localStorage.getItem("siro_locale") || "en",
    currency: localStorage.getItem("siro_currency") || "VND",
  } : { store_id: "default", channel_id: "default", locale: "en", currency: "VND" };
  headers["X-Store-Id"] = scope.store_id;
  headers["X-Channel-Id"] = scope.channel_id;
  headers["X-Locale"] = scope.locale;
  headers["X-Currency"] = scope.currency;
  return headers;
}

export async function fetchWithAuth(path: string, init: RequestInit = {}): Promise<Response> {
  const run = async (): Promise<Response> => {
    const headers = new Headers(init.headers);
    for (const [key, value] of Object.entries(defaultHeaders())) if (!headers.has(key)) headers.set(key, value);
    const token = session.accessToken;
    if (token) headers.set("Authorization", `Bearer ${token}`);
    const url = new URL(path, `${API_URL.replace(/\/$/, "")}/`).toString();
    return fetchCurrent(url, { ...init, headers });
  };
  let response = await run();
  if (response.status === 401) {
    const refreshed = await session.refresh();
    if (refreshed) response = await run();
  }
  return response;
}

const client = new RequestCoordinator({
  baseUrl: API_URL,
  version: "v1",
  fetch: fetchCurrent,
  credentials: "omit",
  getAccessToken: () => session.accessToken,
  getDefaultHeaders: defaultHeaders,
  onUnauthorized: async () => {
    try {
      const refreshed = await session.refresh();
      if (!refreshed) {
        const { useAuthStore } = await import("@/store/auth.store");
        useAuthStore.getState().logout();
      }
      return refreshed;
    } catch {
      // Refresh failures are terminal for this browser session.
      const { useAuthStore } = await import("@/store/auth.store");
      useAuthStore.getState().logout();
      return false;
    }
  },
});

async function request<T>(method: string, path: string, body?: unknown, options?: ApiOptions): Promise<ApiResponse<T>> {
  if (options?.params) {
    const query = new URLSearchParams();
    Object.entries(options.params).forEach(([key, value]) => { if (value !== undefined && value !== null) query.set(key, String(value)); });
    const suffix = query.toString();
    if (suffix) path += `${path.includes("?") ? "&" : "?"}${suffix}`;
  }
  const headers: Record<string, string> = { ...options?.headers };
  const captured = scopeCoordinator.capture();
  try {
    const response = await client.request<T>(path, {
      method: method as "GET" | "POST" | "PUT" | "PATCH" | "DELETE",
      body,
      signal: options?.signal,
      retry: true,
      headers,
      idempotencyKey: options?.idempotencyKey,
      scope: options?.scope ?? captured?.scope,
      cartToken: options?.cartToken,
      credentials: options?.credentials,
      csrfToken: options?.csrfToken,
    });
    if (captured && !scopeCoordinator.isCurrent(captured.version)) {
      throw new AdminApiError({ message: "The response belongs to an outdated commerce scope", kind: "unknown_outcome" });
    }
    return { data: response.body as unknown as T, status: response.status, headers: response.headers, correlationId: response.correlationId };
  } catch (error) {
    throw toAdminApiError(error);
  }
}

const api = {
  get<T = any>(path: string, options?: ApiOptions) { return request<T>("GET", path, undefined, options); },
  post<T = any>(path: string, body?: unknown, options?: ApiOptions) { return request<T>("POST", path, body, options); },
  put<T = any>(path: string, body?: unknown, options?: ApiOptions) { return request<T>("PUT", path, body, options); },
  patch<T = any>(path: string, body?: unknown, options?: ApiOptions) { return request<T>("PATCH", path, body, options); },
  delete<T = any>(path: string, options?: ApiOptions) { return request<T>("DELETE", path, undefined, options); },
};

export default api;
