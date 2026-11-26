import { expect, test } from "@playwright/test";

test.describe("mobile-first critical journeys", () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "mobile-320", "These checks target the mobile viewport.");
    await page.goto("/");
  });

  test("keyboard navigation opens and closes the primary menu safely", async ({ page }) => {
    const menu = page.getByRole("button", { name: "Open navigation menu" });
    await menu.focus();
    await menu.press("Enter");
    await expect(page.getByRole("navigation", { name: "Primary navigation" }).getByRole("link", { name: "Apparel" })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(menu).toBeFocused();
    await expect(menu).toHaveAttribute("aria-expanded", "false");
  });

  test("catalog controls and empty search results remain actionable", async ({ page }) => {
    await page.goto("/search?q=does-not-exist");
    await expect(page.getByRole("status").filter({ hasText: "No products found" })).toBeVisible();
    await expect(page.getByLabel("Clear search")).toBeVisible();
    await page.goto("/category/apparel");
    const sort = page.getByRole("combobox", { name: "Sort products" });
    await expect(sort).toBeVisible();
    await expect(sort).toHaveCSS("min-height", "44px");
  });

  test("reduced motion disables transitions and preserves product purchase actions", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/product/linen-camp-shirt");
    await expect(page.getByRole("link", { name: "Sticky add to cart" })).toBeVisible();
    await expect(page.locator("html")).toHaveCSS("scroll-behavior", "auto");
    await expect(page.locator(".mobile-add .button")).toHaveCSS("min-height", "44px");
  });

  test("keeps account, cart, and quote pages inside the 320px viewport", async ({ page }) => {
    for (const path of ["/account", "/cart", "/quote"]) {
      await page.goto(path);
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
      await expect(page.locator("body")).toHaveCSS("overflow-x", "hidden");
    }
  });

  test("exposes clear shell controls and footer navigation", async ({ page }) => {
    await expect(page.getByRole("link", { name: /sirocommerce home/i })).toBeVisible();
    await expect(page.getByRole("button", { name: /open shopping bag/i })).toBeVisible();
    await expect(page.getByRole("combobox", { name: "Language" })).toBeVisible();
    await expect(page.getByRole("combobox", { name: "Currency" })).toBeVisible();
    await expect(page.getByRole("navigation", { name: "Explore" })).toBeVisible();
    await expect(page.getByRole("navigation", { name: "Help and policies" })).toBeVisible();
  });
});
