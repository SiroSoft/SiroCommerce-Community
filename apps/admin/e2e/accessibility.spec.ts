import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "./fixtures";

const TOKEN = "eyJhbGciOiJub25lIn0.eyJleHAiOjk5OTk5OTk5OTl9.signature";
const USER = { id: 1, name: "Admin User", email: "admin@example.test", role: "admin", status: "active" };
const dashboard = {
  success: true,
  data: {
    total_users: 4,
    active_users: 3,
    total_orders: 8,
    total_products: 6,
    total_revenue: 120,
    recent_activity: [],
    monthly_revenue: [],
    orders_by_status: { pending: 2, completed: 6 },
    api_status: { status: "healthy", version: "1.0.1", uptime: 1, response_time: 1 },
  },
};

const surfaces = [
  { path: "/", heading: /^Good (morning|afternoon|evening),/ },
  { path: "/action-center", heading: "Action Center" },
  { path: "/users", heading: "Users" },
  { path: "/products", heading: "Products" },
  { path: "/orders", heading: "Orders" },
  { path: "/categories", heading: "Categories" },
  { path: "/tags", heading: "Tags" },
  { path: "/posts", heading: "Posts" },
  { path: "/settings", heading: "Settings" },
  { path: "/profile", heading: "Profile" },
  { path: "/reporting", heading: "Reporting" },
  { path: "/fulfillment", heading: "Fulfillment" },
  { path: "/inventory", heading: "Inventory" },
];

async function mockOperationalApi(page: Page) {
  await page.route("**/api/auth/login", async (route) => {
    await route.fulfill({ json: { success: true, data: { user: USER, token: TOKEN, refresh_token: TOKEN } } });
  });
  await page.route("**/api/auth/refresh", async (route) => {
    await route.fulfill({ json: { success: true, data: { user: USER, token: TOKEN, refresh_token: TOKEN } } });
  });
  await page.route("**/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/login") || path.endsWith("/auth/refresh")) {
      await route.fulfill({ json: { success: true, data: { user: USER, token: TOKEN, refresh_token: TOKEN } } });
    } else if (path.endsWith("/auth/me")) {
      await route.fulfill({ json: { success: true, data: USER } });
    } else if (path.endsWith("/dashboard/stats")) {
      await route.fulfill({ json: dashboard });
    } else if (path.endsWith("/settings")) {
      await route.fulfill({ json: { success: true, data: { app_name: "Siro Admin", locale: "en" } } });
    } else {
      await route.fulfill({ json: { success: true, data: [], meta: { total: 0, page: 1, last_page: 1 } } });
    }
  });
  await page.goto("/login");
  await page.getByLabel(/email/i).fill(USER.email);
  await page.getByRole("textbox", { name: /password/i }).fill("password123");
  await page.getByRole("button", { name: "Login" }).click();
  await expect(page).toHaveURL(/\/$/);
}

test.describe("Admin operational surface accessibility", () => {
  test.setTimeout(120_000);

  test("all operational surfaces have no axe violations", async ({ page }) => {
    await mockOperationalApi(page);
    for (const surface of surfaces) {
      await page.goto(surface.path);
      await expect(page.locator("main")).toBeVisible({ timeout: 15000 });
      await expect(page.locator("main h1")).toHaveText(surface.heading, { timeout: 15000 });
      const results = await new AxeBuilder({ page }).disableRules(["color-contrast", "empty-table-header"]).analyze();
      expect(results.violations, `axe violations on ${surface.path}`).toEqual([]);
    }
  });

  test("mobile navigation supports keyboard close and restores focus path", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockOperationalApi(page);
    await page.goto("/");
    const menu = page.getByRole("button", { name: /open navigation/i });
    await menu.click();
    await expect(page.locator('aside[aria-label*="mobile navigation" i]')).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.locator('aside[aria-label*="mobile navigation" i]')).toHaveClass(/-translate-x-full/);
    await expect(menu).toBeFocused();
  });
});
