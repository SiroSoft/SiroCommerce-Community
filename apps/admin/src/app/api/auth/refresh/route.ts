import { NextRequest, NextResponse } from "next/server";
import { z } from "zod";
import { tSchema } from "@/lib/i18n";
import { RequestCoordinator, ApiClientError } from "@sirocommerce/api-client";

const ALLOWED_ORIGINS = [
  "http://localhost:3005",
  "http://localhost:8090",
  ...(process.env.ALLOWED_ORIGINS?.split(",").map((origin) => origin.trim()).filter(Boolean) ?? []),
].map((origin) => {
  try { return new URL(origin).origin; } catch { return undefined; }
}).filter((origin): origin is string => Boolean(origin));

const ipRequests = new Map<string, { count: number; resetAt: number }>();

function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  const entry = ipRequests.get(ip);
  if (!entry || now > entry.resetAt) {
    ipRequests.set(ip, { count: 1, resetAt: now + 60_000 });
    return true;
  }
  if (entry.count >= 10) return false;
  entry.count++;
  return true;
}

const refreshSchema = z.object({
  refresh_token: z.string().trim().min(1, tSchema("validation.refreshTokenRequired")).max(4096),
});

function requestOrigin(req: NextRequest): string {
  const origin = req.headers.get("origin");
  if (origin) return origin;
  const referer = req.headers.get("referer");
  if (!referer) return "";
  try { return new URL(referer).origin; } catch { return ""; }
}

function errorEnvelope(message: string, code: string, errors?: Record<string, string[]>) {
  return { success: false, message, meta: { error_code: code, ...(errors ? { errors } : {}) } };
}

export async function POST(req: NextRequest) {
  try {
    const origin = requestOrigin(req);
    const isValidOrigin = ALLOWED_ORIGINS.includes(origin);
    if (!isValidOrigin) {
      return NextResponse.json(errorEnvelope("Forbidden", "FORBIDDEN"), { status: 403 });
    }

    const ip = req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ?? "unknown";
    if (!checkRateLimit(ip)) {
      return NextResponse.json(errorEnvelope("Too many requests", "RATE_LIMITED"), { status: 429 });
    }

    const body = await req.json().catch(() => undefined);
    const parsed = refreshSchema.safeParse(body);
    if (!parsed.success) {
      return NextResponse.json(
        errorEnvelope("Validation error", "VALIDATION_ERROR", parsed.error.flatten().fieldErrors),
        { status: 422 },
      );
    }

    const apiUrl = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8090";
    const { refresh_token } = parsed.data;

    const client = new RequestCoordinator({ baseUrl: apiUrl });
    const result = await client.request("/api/auth/refresh", {
      method: "POST",
      body: { refresh_token },
      retry: false,
    });
    return NextResponse.json(result.body, { status: result.status });
  } catch (err) {
    if (err instanceof ApiClientError) {
      return NextResponse.json(errorEnvelope("Token refresh failed", "REFRESH_FAILED"), { status: err.status });
    }
    return NextResponse.json(
      errorEnvelope("Internal server error", "INTERNAL_ERROR"),
      { status: 500 },
    );
  }
}
