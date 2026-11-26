import { test, expect } from "@playwright/test";

test("fixture auth and API guard states are explicit", async ({ page }) => {
  await page.goto("/checkout");
  await page.getByRole("button", { name: "Test customer authentication" }).click();
  await expect(page.locator('p[role="status"]').filter({ hasText: /authenticated/i })).toBeVisible();
  await page.getByRole("button", { name: "Show unauthorized API state" }).click();
  await expect(page.locator('p[role="status"]').filter({ hasText: /401 unauthorized/i })).toBeVisible();
});
