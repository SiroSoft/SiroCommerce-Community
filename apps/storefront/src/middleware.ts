import { NextResponse, type NextRequest } from "next/server";
import { normalizeLocale } from "@/lib/locale";
import { CURRENCY_COOKIE, LOCALE_COOKIE, SUPPORTED_CURRENCIES } from "@/lib/scope-cookie";

export const REDIRECT_CACHE_TTL_MS = 60_000;
export const REDIRECT_LOOKUP_TIMEOUT_MS = 1_500;

const SYSTEM_PREFIXES = ["/_next", "/api", "/admin"];
const SYSTEM_PATHS = new Set(["/robots.txt", "/sitemap.xml", "/manifest.webmanifest", "/favicon.ico", "/icon.svg", "/health", "/metrics"]);

interface RedirectLookupOptions {
  fetch?: typeof fetch;
  baseUrl?: string;
  scope?: { store: string; channel: string; locale: string; currency: string };
  now?: number;
  timeoutMs?: number;
}

interface CachedRedirect {
  expiresAt: number;
  value: { target: string; status: number } | null;
}

const redirectCache = new Map<string, CachedRedirect>();

export function clearRedirectCache(): void {
  redirectCache.clear();
}

export function shouldSkipRedirectLookup(pathname: string): boolean {
  if (!pathname || pathname === "/") return true;
  if (SYSTEM_PATHS.has(pathname)) return true;
  if (/\.[a-z0-9]+$/i.test(pathname)) return true;
  return SYSTEM_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

/** Reject protocol-relative, scheme, traversal and self-referential targets. */
export function safeRedirectTarget(value: unknown, currentPath: string): string | undefined {
  if (typeof value !== "string") return undefined;
  const target = value.trim();
  if (!target || target.length > 2048) return undefined;
  if (!target.startsWith("/") || target.startsWith("//") || target.includes("\\")) return undefined;
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f]/.test(target)) return undefined;
  const targetPath = target.split("?")[0] ?? "";
  if (targetPath === currentPath) return undefined;
  return target;
}

function apiBaseUrl(options: RedirectLookupOptions): string | undefined {
  const value = options.baseUrl ?? process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL;
  const trimmed = typeof value === "string" ? value.trim() : "";
  return trimmed ? trimmed.replace(/\/$/, "") : undefined;
}

function cacheKey(baseUrl: string, path: string, scope: RedirectLookupOptions["scope"]): string {
  return [baseUrl, scope?.store ?? "", scope?.channel ?? "", scope?.locale ?? "", scope?.currency ?? "", path].join("|");
}

export async function resolveRedirectPath(pathname: string, options: RedirectLookupOptions = {}): Promise<{ target: string; status: number } | null> {
  if (shouldSkipRedirectLookup(pathname)) return null;
  const baseUrl = apiBaseUrl(options);
  if (!baseUrl) return null;
  const key = cacheKey(baseUrl, pathname, options.scope);
  const now = options.now ?? Date.now();
  const cached = redirectCache.get(key);
  if (cached && cached.expiresAt > now) return cached.value;

  const request = options.fetch ?? fetch;
  const url = new URL(`${baseUrl}/api/redirects/resolve`);
  url.searchParams.set("path", pathname);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? REDIRECT_LOOKUP_TIMEOUT_MS);
  try {
    const response = await request(url.toString(), {
      method: "GET",
      headers: {
        Accept: "application/json",
        ...(options.scope ? {
          "X-Store-Id": options.scope.store,
          "X-Channel-Id": options.scope.channel,
          "X-Locale": options.scope.locale,
          "X-Currency": options.scope.currency,
        } : {}),
      },
      signal: controller.signal,
    });
    if (!response.ok) return null;
    const body = await response.json() as { data?: { target?: unknown; code?: unknown } } | null;
    const target = safeRedirectTarget(body?.data?.target, pathname);
    const value = target ? { target, status: Number(body?.data?.code) === 302 ? 302 : 308 } : null;
    redirectCache.set(key, { expiresAt: now + REDIRECT_CACHE_TTL_MS, value });
    return value;
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

function requestScope(request: NextRequest): { store: string; channel: string; locale: string; currency: string } {
  const rawCurrency = (request.cookies.get(CURRENCY_COOKIE)?.value ?? process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? process.env.STOREFRONT_CURRENCY ?? "USD").toUpperCase();
  return {
    store: process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? process.env.STOREFRONT_STORE_ID ?? "default",
    channel: process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? process.env.STOREFRONT_CHANNEL_ID ?? "web",
    locale: normalizeLocale(request.cookies.get(LOCALE_COOKIE)?.value) ?? normalizeLocale(process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? process.env.STOREFRONT_LOCALE) ?? "en",
    currency: (SUPPORTED_CURRENCIES as readonly string[]).includes(rawCurrency) ? rawCurrency : "USD",
  };
}

export async function middleware(request: NextRequest): Promise<NextResponse> {
  const previewTheme = request.cookies.get("siro_theme_preview")?.value;
  if (previewTheme && request.nextUrl.pathname === "/checkout") {
    return NextResponse.redirect(new URL("/cart", request.url));
  }
  const method = request.method.toUpperCase();
  if (method !== "GET" && method !== "HEAD") return NextResponse.next();
  const pathname = request.nextUrl.pathname;
  if (shouldSkipRedirectLookup(pathname)) return NextResponse.next();
  const redirect = await resolveRedirectPath(pathname, { scope: requestScope(request) });
  if (!redirect) return NextResponse.next();
  return NextResponse.redirect(new URL(redirect.target, request.url), redirect.status);
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
