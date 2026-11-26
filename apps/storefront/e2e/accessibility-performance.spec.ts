import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

test.describe("Storefront release accessibility and performance", () => {
  test("home has no serious or critical axe violations", async ({ page }) => {
    await page.goto("/", { waitUntil: "load" });
    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter((violation) =>
      violation.impact === "serious" || violation.impact === "critical",
    );
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test("product navigation records valid performance timings", async ({ page }) => {
    await page.goto("/product/linen-camp-shirt", { waitUntil: "load" });
    const navigation = await page.evaluate(() => performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming);
    expect(navigation.domContentLoadedEventEnd).toBeGreaterThanOrEqual(navigation.fetchStart);
    expect(navigation.loadEventEnd).toBeGreaterThanOrEqual(navigation.domContentLoadedEventEnd);
    expect(navigation.domContentLoadedEventEnd - navigation.fetchStart).toBeLessThan(2_000);
    expect(navigation.loadEventEnd - navigation.fetchStart).toBeLessThan(3_000);
  });
});
