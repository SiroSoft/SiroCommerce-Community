import { expect, test } from "@playwright/test";

test.describe("B2C launch readiness critical journey", () => {
  test("browse, search, product, cart, and guest checkout remain usable", async ({ page }) => {
    await page.goto("/", { waitUntil: "load" });
    await expect(page.getByRole("link", { name: /shop the collection/i })).toBeVisible();
    await page.goto("/search?q=linen", { waitUntil: "load" });
    await expect(page.getByRole("heading", { name: /search the collection/i })).toBeVisible();
    const productLink = page.getByRole("link", { name: /linen mandarin-collar shirt/i }).first();
    const productHref = await productLink.getAttribute("href").catch(() => null);
    await page.goto(productHref && productHref.startsWith("/product/") ? productHref : "/product/ao-so-mi-linen", { waitUntil: "load" });
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await page.getByRole("button", { name: /^add to cart$/i }).first().click();
    await expect(page).toHaveURL(/\/cart(\?|$)/);
    await expect(page.getByRole("button", { name: /continue to delivery/i })).toBeVisible();
    await expect(async () => {
      await page.getByRole("button", { name: /continue to delivery/i }).click();
      await expect(page.getByRole("button", { name: /continue to checkout/i })).toBeVisible({ timeout: 5000 });
    }).toPass({ timeout: 45000 });
    await page.getByRole("button", { name: /continue to checkout/i }).click();
    await expect(page).toHaveURL(/\/checkout/);
    await expect(page.getByRole("button", { name: /place order/i }).or(page.getByRole("button", { name: /unavailable/i }))).toBeVisible();
  });

  test("real guest checkout is enabled only against an explicitly configured environment", async ({ page }) => {
    test.skip(process.env.PLAYWRIGHT_REAL_CHECKOUT !== "1", "Set PLAYWRIGHT_REAL_CHECKOUT=1 with a seeded, disposable environment");
    await page.goto("/cart?product=linen-camp-shirt&quantity=1", { waitUntil: "load" });
    await page.getByRole("button", { name: /continue to quote/i }).click();
    await page.getByRole("button", { name: /continue to checkout/i }).click();
    await page.getByRole("button", { name: /place order/i }).click();
    await expect(page).toHaveURL(/\/order-result/);
    await expect(page.getByTestId("order-number")).toBeVisible();
  });
});
