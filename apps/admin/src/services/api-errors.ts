import { ApiClientError } from "@sirocommerce/api-client";
import type { QueryClient } from "@tanstack/react-query";
import { useAuthStore } from "@/store/auth.store";

export type ApiErrorKind =
  | "auth"
  | "permission"
  | "conflict"
  | "validation"
  | "rate_limited"
  | "server"
  | "unknown_outcome";

export class AdminApiError extends Error {
  readonly status?: number;
  readonly errorCode?: string;
  readonly errors?: Record<string, string[]>;
  readonly correlationId?: string;
  readonly retryAfterMs?: number;
  readonly kind: ApiErrorKind;

  constructor(input: {
    message: string;
    status?: number;
    errorCode?: string;
    errors?: Record<string, string[]>;
    correlationId?: string;
    retryAfterMs?: number;
    kind?: ApiErrorKind;
  }) {
    super(input.message);
    this.name = "AdminApiError";
    this.status = input.status;
    this.errorCode = input.errorCode;
    this.errors = input.errors;
    this.correlationId = input.correlationId;
    this.retryAfterMs = input.retryAfterMs;
    this.kind = input.kind ?? classifyStatus(input.status);
  }
}

function classifyStatus(status?: number): ApiErrorKind {
  if (status === 401) return "auth";
  if (status === 403) return "permission";
  if (status === 409) return "conflict";
  if (status === 422) return "validation";
  if (status === 429) return "rate_limited";
  if (status !== undefined && status >= 500) return "server";
  return "unknown_outcome";
}

function retryAfterMs(error: unknown): number | undefined {
  const value = (error as { retryAfter?: string | number; retryAfterMs?: number })?.retryAfterMs
    ?? (error as { retryAfter?: string | number })?.retryAfter;
  if (typeof value === "number") return value < 1000 ? value * 1000 : value;
  if (typeof value !== "string" || !value.trim()) return undefined;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return seconds * 1000;
  const date = Date.parse(value);
  return Number.isFinite(date) ? Math.max(0, date - Date.now()) : undefined;
}

export function toAdminApiError(error: unknown): AdminApiError {
  if (error instanceof AdminApiError) return error;
  const candidate = error as {
    status?: number;
    response?: { status?: number; headers?: Headers };
    errorCode?: string;
    errors?: Record<string, string[]>;
    correlationId?: string;
    message?: string;
    meta?: { error_code?: string; errors?: Record<string, string[]> };
    body?: { message?: string; meta?: { error_code?: string; errors?: Record<string, string[]> } };
  };
  const envelope = candidate?.body ?? (error as { data?: typeof candidate.body })?.data;
  const meta = candidate?.meta ?? envelope?.meta;
  const status = candidate?.status ?? candidate?.response?.status;
  const retryHeader = candidate?.response?.headers?.get("Retry-After");
  const retryAfter = retryAfterMs(retryHeader ? { retryAfter: retryHeader } : error);
  const message = candidate?.message || envelope?.message || (error instanceof Error ? error.message : "Request returned an unknown outcome");
  return new AdminApiError({
    message,
    status,
    errorCode: candidate?.errorCode ?? meta?.error_code,
    errors: candidate?.errors ?? meta?.errors,
    correlationId: candidate?.correlationId,
    retryAfterMs: retryAfter,
  });
}

export function isRetryableQueryError(failureCount: number, error: unknown): boolean {
  if (failureCount >= 2) return false;
  if (error instanceof DOMException && error.name === "AbortError") return false;
  if (error instanceof Error && error.name === "AbortError") return false;
  const normalized = toAdminApiError(error);
  return normalized.kind === "rate_limited" || normalized.kind === "server" || normalized.status === undefined;
}

export function retryDelay(attempt: number, error: unknown): number {
  const retryAfter = toAdminApiError(error).retryAfterMs;
  return retryAfter ?? Math.min(1000 * 2 ** attempt, 5000);
}

export function reconcileMutationError(queryClient: QueryClient, resource: string, error: unknown): AdminApiError {
  const normalized = toAdminApiError(error);
  if (normalized.status === 401) useAuthStore.getState().logout();
  if (normalized.status === 409) {
    // The server is authoritative after a conflict; do not leave an optimistic/stale list visible.
    void queryClient.invalidateQueries({ queryKey: [resource] });
  }
  return normalized;
}

export function isApiClientError(error: unknown): error is ApiClientError {
  return error instanceof ApiClientError;
}
