import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

async function seriousOrCritical(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  return results.violations.filter(
    (violation) => violation.impact === "serious" || violation.impact === "critical",
  );
}

/**
 * Labels render after a client-side fetch; wait for the labels container
 * (card wrapper or PDP row) to appear, or fall back to a short settle wait
 * when no rules match (no badges rendered is a valid state).
 */
async function waitForLabelsSettled(page: Page) {
  await page
    .locator('[data-testid="product-labels-card"], [data-testid="product-labels-pdp"]')
    .first()
    .waitFor({ state: "attached", timeout: 15000 })
    .catch(() => page.waitForTimeout(3000));
}

test.describe("Product Labels accessibility (batch 1)", () => {
  test("listing with product-card badges has no serious or critical axe violations", async ({
    page,
  }) => {
    await page.goto("/", { waitUntil: "load", timeout: 60000 });
    await waitForLabelsSettled(page);
    const bad = await seriousOrCritical(page);
    expect(bad, JSON.stringify(bad, null, 2)).toEqual([]);
  });

  test("PDP with title badges has no serious or critical axe violations", async ({
    page,
  }) => {
    await page.goto("/product/linen-camp-shirt", { waitUntil: "load", timeout: 60000 });
    await waitForLabelsSettled(page);
    const bad = await seriousOrCritical(page);
    expect(bad, JSON.stringify(bad, null, 2)).toEqual([]);
  });
});
