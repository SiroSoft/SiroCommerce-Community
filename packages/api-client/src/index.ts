import type { CommerceScope } from "@sirocommerce/scope";
import { scopeHeaders } from "@sirocommerce/scope";
import type { ApiEnvelope, ApiError } from "./generated";

export type RequestMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
export interface RequestOptions {
  method?: RequestMethod;
  query?: Record<string, string | number | boolean | null | undefined | string[]>;
  body?: unknown;
  headers?: Record<string, string>;
  scope?: CommerceScope;
  accessToken?: string;
  idempotencyKey?: string;
  cartToken?: string;
  signal?: AbortSignal;
  retry?: boolean;
  correlationId?: string;
  credentials?: RequestCredentials;
  csrfToken?: string;
}
export interface RequestResult<T> {
  status: number;
  headers: Headers;
  body: T;
  correlationId?: string;
  metadata: Record<string, unknown>;
}
export interface ApiClientOptions {
  baseUrl: string;
  fetch?: typeof fetch;
  getAccessToken?: () => string | undefined;
  onUnauthorized?: () => Promise<boolean>;
  defaultHeaders?: Record<string, string>;
  getDefaultHeaders?: () => Record<string, string>;
  getCsrfToken?: () => string | undefined;
  version?: string;
  credentials?: RequestCredentials;
}

export class ApiClientError extends Error {
  readonly status: number;
  readonly errorCode?: string;
  readonly errors?: Record<string, string[]>;
  readonly correlationId?: string;
  readonly metadata: Record<string, unknown>;
  constructor(status: number, body: unknown, correlationId?: string) {
    const envelope = normalizeEnvelope<unknown>(body);
    super(envelope.ok ? `Request failed with status ${status}` : envelope.message);
    this.name = "ApiClientError";
    this.status = status;
    this.errorCode = envelope.ok ? undefined : envelope.errorCode;
    this.errors = envelope.ok ? undefined : envelope.errors;
    this.correlationId = correlationId;
    this.metadata = envelope.ok ? envelope.meta : envelope.meta;
  }
}

export type NormalizedEnvelope<T> =
  | { ok: true; data: T; meta: Record<string, unknown> }
  | { ok: false; message: string; errorCode?: string; errors?: Record<string, string[]>; meta: Record<string, unknown> };

export function normalizeEnvelope<T>(body: unknown): NormalizedEnvelope<T> {
  const value = body && typeof body === "object" ? body as { success?: unknown; data?: T; message?: unknown; meta?: unknown } : {};
  const meta = value.meta && typeof value.meta === "object" ? value.meta as Record<string, unknown> : {};
  const errors = meta.errors && typeof meta.errors === "object"
    ? Object.fromEntries(Object.entries(meta.errors).filter(([, messages]) => Array.isArray(messages) && messages.every(message => typeof message === "string"))) as Record<string, string[]>
    : undefined;
  if (value?.success === true) return { ok: true, data: value.data as T, meta };
  return { ok: false, message: typeof value.message === "string" ? value.message : "Request failed", errorCode: typeof meta.error_code === "string" ? meta.error_code : undefined, errors, meta };
}

export class RequestCoordinator {
  private readonly http: typeof fetch;
  private readonly options: ApiClientOptions;
  private refresh?: Promise<boolean>;
  constructor(options: ApiClientOptions) { this.options = options; this.http = options.fetch ?? globalThis.fetch.bind(globalThis); }

  async request<T>(path: string, request: RequestOptions = {}): Promise<RequestResult<ApiEnvelope<T>>> {
    const attempt = async (): Promise<RequestResult<ApiEnvelope<T>>> => {
      const headers: Record<string, string> = {
         Accept: `application/json; version=${this.options.version ?? "v1"}`,
        "X-Correlation-Id": request.correlationId ?? `req_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`,
        ...this.options.defaultHeaders,
        ...this.options.getDefaultHeaders?.(),
        ...scopeHeaders(request.scope),
        ...request.headers,
      };
      const token = request.accessToken ?? this.options.getAccessToken?.();
      if (token) headers.Authorization = `Bearer ${token}`;
      if (request.idempotencyKey) headers["Idempotency-Key"] = request.idempotencyKey;
      if (request.cartToken) headers["X-Cart-Token"] = request.cartToken;
      const credentials = request.credentials ?? this.options.credentials;
      const mutating = request.method !== undefined && request.method !== "GET";
      if (credentials === "include" && mutating) {
        const csrfToken = request.csrfToken ?? this.options.getCsrfToken?.();
        if (!csrfToken) throw new ApiClientError(0, { message: "CSRF protection is required for cookie-authenticated writes", meta: { error_code: "CSRF_REQUIRED" } });
        headers["X-CSRF-Token"] = csrfToken;
      }
      const isForm = typeof FormData !== "undefined" && request.body instanceof FormData;
      if (request.body !== undefined && !isForm) headers["Content-Type"] = "application/json";
      const requestBody = request.body === undefined ? undefined : isForm ? request.body : JSON.stringify(request.body);
      const url = new URL(path, `${this.options.baseUrl.replace(/\/$/, "")}/`);
      for (const [key, value] of Object.entries(request.query ?? {})) {
        if (value === null || value === undefined) continue;
        for (const item of Array.isArray(value) ? value : [value]) url.searchParams.append(key, String(item));
      }
       const response = await this.http(url.toString(), { method: request.method ?? "GET", headers, body: requestBody as BodyInit | undefined, signal: request.signal, ...(credentials ? { credentials } : {}) });
      const correlationId = response.headers.get("X-Correlation-Id") ?? undefined;
       let body: ApiEnvelope<T>;
       try { body = response.status === 204 ? ({ success: true, message: "", data: undefined, meta: {} } as ApiEnvelope<T>) : await response.json() as ApiEnvelope<T>; } catch { throw new ApiClientError(response.status, undefined, correlationId); }
        const envelope = normalizeEnvelope<T>(body);
        if (!response.ok || !envelope.ok) throw new ApiClientError(response.status, body, correlationId);
       return { status: response.status, headers: response.headers, body, correlationId, metadata: envelope.meta };
    };
    try { return await attempt(); } catch (error) {
      if (error instanceof ApiClientError && error.status === 401 && request.retry !== false && this.options.onUnauthorized) {
        this.refresh ??= this.options.onUnauthorized().finally(() => { this.refresh = undefined; });
         if (await this.refresh) return this.request(path, { ...request, retry: false, correlationId: request.correlationId ?? error.correlationId });
      }
      throw error;
    }
  }
}

export type { ApiEnvelope, ApiError } from "./generated";
export type { HeadlessSchemaContract, AiSuggestionRequest, AiSuggestionResult } from "./native-contracts";
