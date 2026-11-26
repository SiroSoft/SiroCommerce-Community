import { expect, test } from "@playwright/test";

test.describe("shared storefront quality contract", () => {
  test("keeps the mobile shell usable and images named", async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto("/", { waitUntil: "load" });

    await expect(page.locator("body")).toHaveCSS("overflow-x", "hidden");
    await expect(page.getByRole("link", { name: "SiroCommerce home" })).toBeVisible();
    await expect(page.getByRole("img")).not.toHaveCount(0);
    for (const image of await page.getByRole("img").all()) {
      await expect(image).toHaveAttribute("alt", /.+/);
    }
  });

  test("supports reduced motion without hiding the primary action", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/", { waitUntil: "load" });
    await expect(page.getByRole("link", { name: /shop the collection/i })).toBeVisible();
    await expect(page.locator("html")).toHaveCSS("scroll-behavior", "auto");
  });

  test("does not overflow the 320px buyer shell", async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto("/search", { waitUntil: "load" });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  });
});
