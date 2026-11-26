import { NextResponse, type NextRequest } from "next/server";

function publicUrl(request: NextRequest, path: string): URL {
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  const proto = request.headers.get("x-forwarded-proto") ?? "https";
  return host ? new URL(path, `${proto}://${host}`) : new URL(path, request.url);
}

export async function GET(request: NextRequest) {
  const response = NextResponse.redirect(publicUrl(request, "/preview"));
  response.cookies.set("siro_theme_preview", "", { httpOnly: true, sameSite: "lax", path: "/", maxAge: 0 });
  return response;
}
