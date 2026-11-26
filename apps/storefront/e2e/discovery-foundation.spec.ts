import { test, expect } from "@playwright/test";

test.describe("DISC-BROWSER discovery foundation", () => {
  test("DISC-B001 Home to category exposes headings and links", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await page.getByRole("link", { name: /shop the collection/i }).click();
    await expect(page).toHaveURL(/\/category\/apparel/);
    await expect(page.getByRole("heading", { level: 1, name: "Apparel" })).toBeVisible();
  });

  test("DISC-B002 search and product detail preserve image and CTA semantics", async ({ page }) => {
    await page.goto("/search?q=linen");
    await expect(page.getByRole("heading", { name: "Linen camp shirt" })).toBeVisible();
    const productLink = page.getByRole("link", { name: /linen camp shirt/i }).first();
    await expect(productLink).toHaveAttribute("href", "/product/linen-camp-shirt");
    await productLink.click();
    await expect(page).toHaveURL(/\/product\/linen-camp-shirt/);
    await expect(page.getByRole("heading", { level: 1, name: "Linen camp shirt" })).toBeVisible();
    await expect(page.getByRole("img", { name: /linen shirt/i })).toBeVisible();
    await expect(page.getByRole("link", { name: "Add to cart", exact: true })).toHaveAttribute("href", /\/cart\?product=linen-camp-shirt/);
  });

  test("DISC-B003 320px and desktop layouts remain visible", async ({ page }) => {
    for (const width of [320, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto("/");
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
      await expect(page.locator("body")).toHaveCSS("overflow-x", "hidden");
    }
  });
});
