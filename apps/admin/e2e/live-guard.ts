import type { test as baseTest } from "@playwright/test";

/**
 * Live-backend guard: these suites exercise the real PHP+MySQL backend with
 * the seeded demo dataset (php siro commerce:seed-demo --reset --count=250).
 * They run only with LIVE_BACKEND=1, e.g.:
 *   LIVE_BACKEND=1 E2E_API_URL=http://localhost:8090 npx playwright test e2e/live-console.spec.ts
 * Otherwise they skip like the other BLOCKED_EXTERNAL suites.
 */
export const LIVE_BACKEND = process.env.LIVE_BACKEND === "1";
export const LIVE_API_URL = (process.env.E2E_API_URL || "http://localhost:8090").replace(/\/$/, "");

type TestFn = typeof baseTest;

export function liveAnnotations(test: TestFn, title: string) {
  test.beforeEach(async ({}, testInfo) => {
    testInfo.annotations.push({
      type: "LIVE_BACKEND",
      description: `${title}: requires a live backend with seeded demo data.`,
    });
    test.skip(!LIVE_BACKEND, "LIVE_BACKEND: set LIVE_BACKEND=1 with a live backend to run");
  });
}
