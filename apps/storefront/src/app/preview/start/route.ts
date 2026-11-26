import { NextResponse, type NextRequest } from "next/server";

const COOKIE = "siro_theme_preview";

function publicUrl(request: NextRequest, path: string): URL {
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  const proto = request.headers.get("x-forwarded-proto") ?? "https";
  return host ? new URL(path, `${proto}://${host}`) : new URL(path, request.url);
}

export async function GET(request: NextRequest) {
  const theme = request.nextUrl.searchParams.get("theme") ?? "";
  if (!/^[a-z0-9][a-z0-9-]{1,39}$/.test(theme)) {
    return NextResponse.redirect(publicUrl(request, "/preview"));
  }
  const response = NextResponse.redirect(publicUrl(request, "/"));
  response.cookies.set(COOKIE, theme, { httpOnly: true, sameSite: "lax", path: "/", maxAge: 3600 });
  return response;
}
