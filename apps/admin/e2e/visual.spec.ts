import { test, expect } from "@playwright/test";
test.describe("visual regression — admin", () => {
  test("parity diagnostics has stable layout", async ({ page }) => {
    await page.goto("/parity");
    await expect(page.locator("main")).toHaveScreenshot("admin-parity.png", { maxDiffPixels: 150 });
  });
  test("finance and booking slots visual", async ({ page }) => {
    await page.goto("/finance");
    await expect(page.locator("main")).toHaveScreenshot("admin-finance.png", { maxDiffPixels: 150 });
  });
});
