import type { NextConfig } from "next";

// Next's dev server and React refresh require eval. Production builds do not,
// so eval is scoped to development only. Inline scripts are still allowed in
// production until the app is migrated to nonce-based hydration scripts.
const isDevelopment = process.env.NODE_ENV !== "production";

const scriptSrc = [
  "'self'",
  "'unsafe-inline'",
  ...(isDevelopment ? ["'unsafe-eval'"] : []),
  "https://challenges.cloudflare.com",
  "https://static.cloudflareinsights.com",
];

const connectSrc = [
  "'self'",
  "https:",
  ...(isDevelopment ? ["http://localhost:*", "ws://localhost:*"] : []),
];

const contentSecurityPolicy = [
  "default-src 'self'",
  `script-src ${scriptSrc.join(" ")}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob:",
  "font-src 'self' data:",
  "frame-src 'self' https://challenges.cloudflare.com",
  `connect-src ${connectSrc.join(" ")}`,
  "object-src 'none'",
  "base-uri 'self'",
  "frame-ancestors 'none'",
  "form-action 'self'",
].join("; ");

const securityHeaders = [
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "X-XSS-Protection", value: "0" },
  {
    key: "Content-Security-Policy",
    value: contentSecurityPolicy,
  },
  {
    key: "Strict-Transport-Security",
    value: "max-age=63072000; includeSubDomains; preload",
  },
];

const nextConfig: NextConfig = {
  basePath: "/admin",
  async headers() {
    return [
      {
        source: "/(.*)",
        headers: securityHeaders,
      },
    ];
  },
};
export default nextConfig;
