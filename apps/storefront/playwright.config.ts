import { defineConfig } from "@playwright/test";
import { existsSync } from "node:fs";

const systemBrowser = [
  process.env.PLAYWRIGHT_EXECUTABLE_PATH,
  "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
].find((path) => path && existsSync(path));

export default defineConfig({
  testDir: "./e2e",
  grep: process.env.PLAYWRIGHT_RELEASE
    ? /purchase acceptance in safe fixture mode|account exposes API-backed loyalty and return history|Storefront release accessibility and performance|B2C launch readiness critical journey|real guest checkout|fixture auth and API guard states are explicit|DISC-BROWSER/
    : undefined,
  fullyParallel: false,
  workers: 1,
  timeout: 90_000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:3100",
    ...(systemBrowser ? { executablePath: systemBrowser } : {}),
    trace: "on-first-retry",
    actionTimeout: 10_000,
    navigationTimeout: 15_000,
  },
  webServer: process.env.PLAYWRIGHT_BASE_URL ? undefined : {
    command: "node ../../node_modules/next/dist/bin/next dev --hostname 127.0.0.1 --port 3100",
    cwd: ".",
    url: "http://127.0.0.1:3100",
    reuseExistingServer: false,
    timeout: 60_000,
    gracefulShutdown: { signal: "SIGTERM", timeout: 5_000 },
    env: { ...process.env, NEXT_PUBLIC_STOREFRONT_TEST_MODE: "true", STOREFRONT_CATALOG_SOURCE: process.env.STOREFRONT_CATALOG_SOURCE ?? "fixture" },
  },
  projects: [
    { name: "mobile-320", use: { viewport: { width: 320, height: 900 }, isMobile: true } },
    { name: "desktop", use: { viewport: { width: 1440, height: 900 } } },
  ],
  reporter: "line",
});
