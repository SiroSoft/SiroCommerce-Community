import { expect, test, type Page } from "@playwright/test";

async function visit(page: Page, path: string) {
  const response = await page.goto(path, { waitUntil: "load" });
  expect(response, `Expected a response for ${path}`).not.toBeNull();
  return response!;
}

test.describe("DISC-BROWSER Home -> Category -> Search -> PDP", () => {
  test("navigates the primary discovery journey", async ({ page }) => {
    await visit(page, "/");
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Useful things, beautifully considered.");
    await page.getByRole("link", { name: /shop the collection/i }).click();
    await expect(page).toHaveURL(/\/category\/apparel$/);
    await expect(page.getByRole("heading", { level: 1, name: "Apparel" })).toBeVisible();

    await page.getByRole("combobox", { name: "Sort products" }).selectOption("price-desc");
    await expect(page.getByRole("heading", { level: 3 }).first()).toHaveText("Cloud knit cardigan");

    await page.getByRole("link", { name: "Search products" }).click();
    await expect(page).toHaveURL(/\/search$/);
    await page.getByRole("textbox", { name: "Search the collection" }).fill("linen");
    await page.getByRole("button", { name: "Go" }).click();
    await expect(page).toHaveURL(/\/search\?q=linen$/);
    await page.getByRole("link", { name: /linen camp shirt/i }).click();
    await expect(page).toHaveURL(/\/product\/linen-camp-shirt$/);
    await expect(page.getByRole("heading", { level: 1, name: "Linen camp shirt" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Add to cart", exact: true })).toHaveAttribute("href", /\/cart\?product=linen-camp-shirt/);
  });

  test("keeps responsive layouts, landmarks, keyboard focus, and image alternatives usable", async ({ page }) => {
    await visit(page, "/");
    await expect(page.getByRole("banner")).toBeVisible();
    await expect(page.getByRole("main")).toBeVisible();
    await expect(page.getByRole("contentinfo")).toBeVisible();
    await expect(page.getByRole("navigation", { name: "Primary navigation" })).toBeAttached();

    const images = page.locator("img");
    for (let index = 0; index < await images.count(); index += 1) {
      await expect(images.nth(index)).toHaveAttribute("alt", /.+/);
    }

    await page.keyboard.press("Tab");
    await expect(page.locator(":focus")).toBeVisible();
    const focusBox = await page.locator(":focus").boundingBox();
    expect(focusBox?.width).toBeGreaterThan(0);
    expect(focusBox?.height).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.documentElement.lang)).toBe("en");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  });

  test("exposes empty and not-found states without a broken response", async ({ page }) => {
    const emptyResponse = await visit(page, "/search?q=missing");
    expect(emptyResponse.status()).toBe(200);
    await expect(page.getByRole("status").filter({ hasText: "No products found." })).toHaveText("No products found.");
    await expect(page.getByRole("link", { name: "Browse apparel" })).toHaveAttribute("href", "/category/apparel");
    await expect(page.getByRole("link", { name: "Browse home" })).toHaveAttribute("href", "/category/home");

    const missingCategory = await visit(page, "/category/not-a-real-category");
    expect([200, 404]).toContain(missingCategory.status());
    await expect(page.locator("body")).toContainText(/not found|404/i);

    const missingProduct = await visit(page, "/product/not-a-real-product");
    expect([200, 404]).toContain(missingProduct.status());
    await expect(page.locator("body")).toContainText(/not found|404/i);
  });

  test("publishes canonical, social, and structured product metadata", async ({ page }) => {
    await visit(page, "/product/linen-camp-shirt");
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", /\/product\/linen-camp-shirt$/);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute("content", /relaxed linen layer/i);
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute("content", /\/product\/linen-camp-shirt$/);

  const jsonLd = page.locator('script[type="application/ld+json"]');
  await expect(jsonLd).toHaveCount(1);
  const productJsonLd = await jsonLd.textContent();
  expect(productJsonLd).toContain('"@type":"Product"');
  expect(productJsonLd).toContain('"price":68');
  });

  test("honors reduced motion and reports stable navigation timing invariants", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    const response = await visit(page, "/");
    expect(response.status()).toBe(200);
    expect(await page.evaluate(() => matchMedia("(prefers-reduced-motion: reduce)").matches)).toBe(true);
    expect(await page.locator(".product-image img").first().evaluate((image) => getComputedStyle(image).transitionDuration)).toBe("0s");

    const navigation = await page.evaluate(() => performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming);
    expect(navigation).toBeTruthy();
    expect(navigation.domContentLoadedEventEnd).toBeGreaterThanOrEqual(navigation.fetchStart);
    expect(navigation.loadEventEnd).toBeGreaterThanOrEqual(navigation.domContentLoadedEventEnd);
  });
});
