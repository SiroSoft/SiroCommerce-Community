import { QueryClient } from "@tanstack/react-query";
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  AdminApiError,
  isRetryableQueryError,
  reconcileMutationError,
  retryDelay,
  toAdminApiError,
} from "@/services/api-errors";
import { useAuthStore } from "@/store/auth.store";

beforeEach(() => {
  useAuthStore.setState({ user: null, isAuthenticated: false, isLoading: false });
});

describe("admin API error classification", () => {
  it.each([
    [401, "auth"],
    [403, "permission"],
    [409, "conflict"],
    [422, "validation"],
    [429, "rate_limited"],
    [500, "server"],
    [503, "server"],
    [404, "unknown_outcome"],
  ])("maps HTTP %s to %s", (status, kind) => {
    const error = toAdminApiError({ status, message: "failure" });
    expect(error).toBeInstanceOf(AdminApiError);
    expect(error.kind).toBe(kind);
    expect(error.status).toBe(status);
  });

  it("normalizes nested response status, envelope body and response headers", () => {
    const error = toAdminApiError({
      response: { status: 422, headers: new Headers({ "Retry-After": "4" }) },
      body: { message: "Validation failed", meta: { error_code: "SKU_DUPLICATE", errors: { sku: ["taken"] } } },
      correlationId: "corr-body",
    });
    expect(error).toMatchObject({
      status: 422,
      kind: "validation",
      message: "Validation failed",
      errorCode: "SKU_DUPLICATE",
      errors: { sku: ["taken"] },
      correlationId: "corr-body",
      retryAfterMs: 4000,
    });
  });

  it("supports data envelopes, numeric retry values and HTTP-date retry values", () => {
    const dataError = toAdminApiError({ data: { message: "From data", meta: { error_code: "DATA_CODE" } } });
    expect(dataError.message).toBe("From data");
    expect(dataError.errorCode).toBe("DATA_CODE");

    expect(toAdminApiError({ status: 429, retryAfter: 2 }).retryAfterMs).toBe(2000);
    expect(toAdminApiError({ status: 429, retryAfter: 1500 }).retryAfterMs).toBe(1500);
    expect(toAdminApiError({ status: 429, retryAfterMs: 1500 }).retryAfterMs).toBe(1500);
    expect(toAdminApiError({ status: 429, retryAfter: "not-a-date" }).retryAfterMs).toBeUndefined();
    const future = new Date(Date.now() + 60_000).toUTCString();
    expect(toAdminApiError({ status: 429, retryAfter: future }).retryAfterMs).toBeGreaterThan(0);
  });

  it("falls back to a stable message for unknown errors", () => {
    const error = toAdminApiError({});
    expect(error.message).toBe("Request returned an unknown outcome");
    expect(error.kind).toBe("unknown_outcome");
  });

  it("returns the same AdminApiError instance without re-wrapping", () => {
    const original = new AdminApiError({ message: "same", status: 403 });
    expect(toAdminApiError(original)).toBe(original);
  });
});

describe("retry policy", () => {
  it("never retries aborts, auth or validation failures and caps attempts", () => {
    expect(isRetryableQueryError(0, new DOMException("aborted", "AbortError"))).toBe(false);
    expect(isRetryableQueryError(0, { status: 403 })).toBe(false);
    expect(isRetryableQueryError(0, { status: 422 })).toBe(false);
    expect(isRetryableQueryError(0, Object.assign(new Error("boom"), { status: 503 }))).toBe(true);
    expect(isRetryableQueryError(1, Object.assign(new Error("boom"), { status: 503 }))).toBe(true);
    expect(isRetryableQueryError(2, Object.assign(new Error("boom"), { status: 503 }))).toBe(false);
  });

  it("caps exponential backoff and honours Retry-After", () => {
    expect(retryDelay(0, { status: 500 })).toBe(1000);
    expect(retryDelay(2, { status: 500 })).toBe(4000);
    expect(retryDelay(10, { status: 500 })).toBe(5000);
    expect(retryDelay(0, new AdminApiError({ message: "busy", status: 429, retryAfterMs: 7500 }))).toBe(7500);
  });
});

describe("reconcileMutationError", () => {
  it("logs out on a 401 response", () => {
    useAuthStore.setState({ user: { id: 1 } as never, isAuthenticated: true, isLoading: false });
    const client = new QueryClient();
    reconcileMutationError(client, "orders", { status: 401, message: "expired" });
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(useAuthStore.getState().user).toBeNull();
  });

  it("invalidates the resource on a 409 conflict", () => {
    const client = new QueryClient();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const error = reconcileMutationError(client, "vendors", { status: 409, message: "conflict" });
    expect(error.kind).toBe("conflict");
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["vendors"] });
  });

  it("returns normalized errors without side effects for other statuses", () => {
    const client = new QueryClient();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const error = reconcileMutationError(client, "orders", { status: 422, message: "invalid" });
    expect(error.kind).toBe("validation");
    expect(invalidate).not.toHaveBeenCalled();
  });
});
