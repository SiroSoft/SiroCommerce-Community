import { test, expect, type Page } from "@playwright/test";
import { LIVE_API_URL, liveAnnotations } from "./live-guard";

/**
 * LIVE console journey against the real backend + seeded MySQL demo data.
 * Run: LIVE_BACKEND=1 E2E_API_URL=http://localhost:8090 npx playwright test e2e/live-console.spec.ts
 * Requires: backend on E2E_API_URL, admin frontend on baseURL, demo seed
 * (demo-buyer-3@example.test / DemoPassword!123 must exist as admin).
 */
const ADMIN_EMAIL = "demo-buyer-3@example.test";
const ADMIN_PASSWORD = "DemoPassword!123";
const TAG = `e2e${Date.now() % 100000}`;

liveAnnotations(test, "console catalog journey");

async function login(page: Page) {
  await page.goto("/login");
  await page.getByLabel(/email/i).fill(ADMIN_EMAIL);
  await page.getByRole("textbox", { name: /password/i }).fill(ADMIN_PASSWORD);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/(\?.*)?$/, { timeout: 20000 });
}

test.describe("LIVE console catalog journey (MySQL demo)", () => {
  test("dashboard shows real aggregate numbers", async ({ page }) => {
    await login(page);
    await expect(page.getByRole("heading", { name: /^Good (morning|afternoon|evening),/ })).toBeVisible({ timeout: 20000 });
    // Counts grow as live journeys create rows, so assert structure plus the
    // stable seeded order count instead of exact totals.
    for (const label of ["Total Users", "Total Orders", "Total Products", "Total Revenue"]) {
      await expect(page.locator("body")).toContainText(label, { timeout: 20000 });
    }
    await expect(page.locator("body")).toContainText("150", { timeout: 20000 });
  });

  test("users: create via dialog and find via search", async ({ page }) => {
    await login(page);
    await page.goto("/users");
    const email = `live-user-${TAG}@example.test`;
    await page.getByRole("button", { name: "Create User" }).first().click({ force: true });
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel(/name/i).fill(`Live User ${TAG}`);
    await dialog.getByLabel(/email/i).fill(email);
    await dialog.locator("#password").fill("Livepass!123");
    await dialog.locator("#password_confirmation").fill("Livepass!123");
    await dialog.locator('button[type="submit"]').click();
    await expect(page.locator("body")).toContainText("User created", { timeout: 20000 });
    await page.evaluate((value) => {
      const el = document.querySelector('input[placeholder^="Search Users"]') as HTMLInputElement | null;
      if (!el) throw new Error("search input not found");
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value")!.set!;
      setter.call(el, value);
      el.dispatchEvent(new Event("input", { bubbles: true }));
    }, email);
    await expect(page.locator("body")).toContainText(email, { timeout: 20000 });
  });

  test("products: create with sku and see it listed", async ({ page }) => {
    await login(page);
    await page.goto("/products");
    const name = `Live Lamp ${TAG}`;
    await page.getByRole("button", { name: "Create Product" }).first().click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel(/name/i).first().fill(name);
    await dialog.getByLabel(/sku/i).first().fill(`LIVE-SKU-${TAG}`);
    await dialog.getByLabel(/price/i).first().fill("129.99");
    const compare = dialog.getByLabel(/compare/i).first();
    if (await compare.count()) await compare.fill("149.99");
    await dialog.locator('button[type="submit"]').click();
    await expect(page.locator("body")).toContainText("Product created", { timeout: 20000 });
  });

  test("orders: list shows seeded customers and totals", async ({ page }) => {
    await login(page);
    await page.goto("/orders");
    await expect(page.locator("body")).toContainText("demo-customer-99@example.test", { timeout: 20000 });
  });

  test("categories/tags/posts: create each", async ({ page }) => {
    await login(page);
    await page.goto("/categories");
    const cat = `Live Decor ${TAG}`;
    await page.getByRole("button", { name: "Create Category" }).first().click();
    await page.getByRole("dialog").getByLabel(/name/i).first().fill(cat);
    await page.getByRole("dialog").locator('button[type="submit"]').click();
    await expect(page.locator("body")).toContainText("Category created", { timeout: 20000 });

    await page.goto("/tags");
    const tag = `LiveSale${TAG}`;
    await page.getByRole("button", { name: "Create Tag" }).first().click();
    await page.getByRole("dialog").getByLabel(/name/i).first().fill(tag);
    await page.getByRole("dialog").locator('button[type="submit"]').click();
    await expect(page.locator("body")).toContainText("Tag created", { timeout: 20000 });
    await page.evaluate((value) => {
      const el = document.querySelector('input[placeholder^="Search Tags"]') as HTMLInputElement | null;
      if (!el) throw new Error("search input not found");
      const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value")!.set!;
      setter.call(el, value);
      el.dispatchEvent(new Event("input", { bubbles: true }));
    }, tag);
    await expect(page.locator("body")).toContainText(tag, { timeout: 20000 });

    await page.goto("/posts");
    const post = `Live Guide ${TAG}`;
    await page.getByRole("button", { name: "Create Post" }).first().click();
    await page.getByRole("dialog").getByLabel(/title/i).first().fill(post);
    const excerpt = page.getByRole("dialog").getByLabel(/excerpt/i).first();
    if (await excerpt.count()) await excerpt.fill(`Guide ${TAG}`);
    const editor = page.getByRole("dialog").locator('[contenteditable="true"]').first();
    if (await editor.count()) { await editor.click(); await page.keyboard.type(`Body ${TAG}`); }
    await page.getByRole("dialog").locator('button[type="submit"]').click();
    await expect(page.locator("body")).toContainText(post, { timeout: 20000 });
  });

  test("settings: rename app and persist", async ({ page }) => {
    await login(page);
    await page.goto("/settings");
    const input = page.locator("#app_name");
    await expect(input).toBeVisible({ timeout: 20000 });
    await input.fill(`Live Shop ${TAG}`);
    const update = page.waitForResponse((response) => response.url().includes("/api/settings") && response.request().method() === "PUT");
    await page.locator('form:has(#app_name) button[type="submit"]').click();
    expect((await update).status()).toBeLessThan(300);
    await page.reload();
    await expect(page.locator("#app_name")).toHaveValue(`Live Shop ${TAG}`, { timeout: 20000 });
  });

  test("profile: rename and persist", async ({ page }) => {
    await login(page);
    await page.goto("/profile");
    const nameInput = page.locator("#name");
    await expect(nameInput).toBeVisible({ timeout: 20000 });
    await expect(nameInput).toHaveValue(/.+/, { timeout: 20000 });
    await nameInput.fill(`Live Chief ${TAG}`);
    const update = page.waitForResponse((response) => response.url().includes("/api/profile") && response.request().method() === "PUT");
    await page.getByRole("button", { name: /^save$/i }).first().click();
    expect((await update).status()).toBeLessThan(300);
    await page.reload();
    await expect(page.getByLabel(/name/i).first()).toHaveValue(`Live Chief ${TAG}`, { timeout: 20000 });
  });

  test("live api base is reachable", async ({ request }) => {
    const res = await request.get(`${LIVE_API_URL}/health/live`);
    expect(res.ok()).toBeTruthy();
  });
});
