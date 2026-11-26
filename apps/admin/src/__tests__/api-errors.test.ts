import { describe, expect, it } from "vitest";
import { AdminApiError, isRetryableQueryError, retryDelay, toAdminApiError } from "@/services/api-errors";

describe("admin API error contract", () => {
  it("preserves generated error metadata and classifies validation errors", () => {
    const error = toAdminApiError({
      status: 422,
      message: "Invalid product",
      errorCode: "VALIDATION_FAILED",
      errors: { sku: ["Already exists"] },
      correlationId: "corr-1",
    });

    expect(error).toMatchObject({
      status: 422,
      kind: "validation",
      errorCode: "VALIDATION_FAILED",
      errors: { sku: ["Already exists"] },
      correlationId: "corr-1",
    });
  });

  it("supports Retry-After seconds and HTTP-date values", () => {
    expect(toAdminApiError({ status: 429, retryAfter: "3" }).retryAfterMs).toBe(3000);
    const error = new AdminApiError({ message: "busy", status: 429, retryAfterMs: 1200 });
    expect(retryDelay(0, error)).toBe(1200);
  });

  it("only retries bounded server, rate-limit, and network failures", () => {
    expect(isRetryableQueryError(0, { status: 500 })).toBe(true);
    expect(isRetryableQueryError(1, { status: 429 })).toBe(true);
    expect(isRetryableQueryError(0, new TypeError("network down"))).toBe(true);
    expect(isRetryableQueryError(0, { status: 401 })).toBe(false);
    expect(isRetryableQueryError(2, { status: 500 })).toBe(false);
    expect(isRetryableQueryError(0, Object.assign(new Error("cancelled"), { name: "AbortError" }))).toBe(false);
  });
});
