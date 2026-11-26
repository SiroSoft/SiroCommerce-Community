import { test, expect } from "@playwright/test";
test.describe("visual regression — storefront", () => {
  test("hero and product card have stable screenshots", async ({ page }) => {
    await page.goto("/");
    await expect(page.locator("main")).toHaveScreenshot("storefront-home.png", { maxDiffPixels: 100 });
  });
  test("product detail has booking slot picker visual", async ({ page }) => {
    await page.goto("/product/linen-camp-shirt");
    await expect(page.locator("article.detail")).toHaveScreenshot("storefront-product.png", { maxDiffPixels: 150 });
  });
});
