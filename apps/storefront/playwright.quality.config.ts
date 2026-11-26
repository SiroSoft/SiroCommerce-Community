import { defineConfig } from "@playwright/test";
import baseConfig from "./playwright.config";

const live = process.env.E2E_LIVE === "1";
const externalBaseUrl = process.env.E2E_BASE_URL;

if (live && !externalBaseUrl) {
  throw new Error("E2E_BASE_URL is required when E2E_LIVE=1 (example: E2E_BASE_URL=https://ecommerce.sirophp.com)");
}

const baseURL = externalBaseUrl ?? "http://127.0.0.1:3000";
const baseWebServer = baseConfig.webServer && !Array.isArray(baseConfig.webServer) ? baseConfig.webServer : undefined;

export default defineConfig({
  ...baseConfig,
  testMatch: /storefront-quality\.spec\.ts/,
  outputDir: "test-results/quality",
  snapshotPathTemplate: "{testDir}/__screenshots__/{projectName}-{platform}/{arg}{ext}",
  grep: undefined,
  use: {
    ...baseConfig.use,
    baseURL,
    reducedMotion: "reduce",
    navigationTimeout: 60_000,
    actionTimeout: 15_000,
  },
  expect: {
    toHaveScreenshot: {
      maxDiffPixelRatio: 0.02,
      animations: "disabled",
      caret: "hide",
    },
  },
  webServer: live
    ? undefined
    : baseWebServer
      ? {
          ...baseWebServer,
          command: "node ../../node_modules/next/dist/bin/next dev --hostname 127.0.0.1 --port 3000",
          url: baseURL,
          timeout: 120_000,
          reuseExistingServer: !process.env.CI,
          env: {
            ...baseWebServer.env,
            NEXT_PUBLIC_STOREFRONT_TEST_MODE: "true",
            STOREFRONT_CATALOG_SOURCE: process.env.STOREFRONT_CATALOG_SOURCE ?? "fixture",
          },
        }
      : undefined,
  projects: [
    {
      name: "desktop",
      use: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 },
    },
    {
      name: "mobile",
      use: {
        viewport: { width: 412, height: 915 },
        deviceScaleFactor: 1,
        isMobile: true,
        hasTouch: true,
      },
    },
  ],
  reporter: [["list"]],
});
