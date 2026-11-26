import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  grep: process.env.PLAYWRIGHT_RELEASE
    ? /shows login page|shows validation errors for empty form|has forgot password link|password visibility toggle works|shows error with invalid credentials|shows forgot password page|shows validation error for empty form|shows register page|validates and submits with turnstile token/
    : undefined,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: "html",
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || "http://localhost:3005",
    trace: "on-first-retry",
  },
  webServer: process.env.PLAYWRIGHT_BASE_URL
    ? undefined
      : {
        command: "node ../../node_modules/next/dist/bin/next dev --hostname 127.0.0.1 --port 3005",
        url: "http://localhost:3005",
       timeout: 60000,
       reuseExistingServer: false,
       gracefulShutdown: { signal: "SIGTERM", timeout: 5000 },
     },
});
