import { expect, test, type Page } from "./fixtures";

const order = {
  id: 42,
  status: "pending",
  customer_email: "buyer@example.test",
  total: 129.5,
  created_at: "2026-09-23T10:00:00Z",
  items: [{ id: 1, product_id: 7, product_name: "Blue shirt", quantity: 2, subtotal: 129.5 }],
};
const TOKEN = "eyJhbGciOiJub25lIn0.eyJleHAiOjk5OTk5OTk5OTl9.signature";

async function mockLogin(page: Page) {
  await page.route("**/api/auth/login", async (route) => {
    await route.fulfill({ json: { success: true, data: { user: { id: 1, email: "admin@example.test", role: "admin" }, token: TOKEN, refresh_token: TOKEN } } });
  });
  await page.route("**/api/auth/me", async (route) => {
    await route.fulfill({ json: { success: true, data: { id: 1, email: "admin@example.test", role: "admin" } } });
  });
  await page.route("**/api/auth/refresh", async (route) => {
    await route.fulfill({ json: { success: true, data: { token: TOKEN, refresh_token: TOKEN } } });
  });
  await page.goto("/login");
  await page.getByLabel(/email/i).fill("admin@example.test");
  await page.getByRole("textbox", { name: /password/i }).fill("password123");
  await page.getByRole("button", { name: "Login" }).click();
  await expect(page).toHaveURL(/\/$/);
}

async function mockJourneyApi(page: Page) {
  await page.route("**/api/admin/smart/action-center**", async (route) => {
    await route.fulfill({ json: { success: true, data: { actions: [] } } });
  });
  await page.route("**/api/orders**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/42")) {
      await route.fulfill({ json: { success: true, data: order } });
      return;
    }
    await route.fulfill({ json: { success: true, data: [order], meta: {} } });
  });
  await page.route("**/api/admin/orders/42/transition", async (route) => {
    await route.fulfill({ json: { success: true, data: { ...order, status: "confirmed" } } });
  });
  await page.route("**/api/admin/fulfillment/allocate", async (route) => {
    await route.fulfill({ json: { success: true, data: { id: "f-1", status: "allocated" } } });
  });
  await page.route("**/api/admin/fulfillment/f-1/track", async (route) => {
    await route.fulfill({ json: { success: true, data: { id: "f-1", status: "allocated" } } });
  });
  await page.route("**/api/admin/inventory/sku-1**", async (route) => {
    await route.fulfill({ json: { success: true, data: { variant_id: "sku-1", source_id: "main", on_hand: 10, reserved: 2, available: 8 } } });
  });
}

test("Action Center loads the contract-backed empty state", async ({ page }) => {
  await mockLogin(page);
  await page.route("**/api/admin/smart/action-center", async (route) => {
    await route.fulfill({ json: { success: true, data: { actions: [] } } });
  });
  await page.goto("/action-center");
  await expect(page.getByRole("heading", { name: "Action Center" })).toBeVisible();
  await expect(page.getByText("No actions require attention.")).toBeVisible();
  await expect(page.getByRole("link", { name: /open orders/i })).toBeVisible();
});

test("Action Center uses the current API route", async ({ page }) => {
  await mockLogin(page);
  let requested = false;
  await page.route("**/api/admin/smart/action-center**", async (route) => {
    requested = true;
    await route.fulfill({ json: { success: true, data: { actions: [] } } });
  });
  await page.goto("/action-center");
  await expect(page.getByText("No actions require attention.")).toBeVisible();
  expect(requested).toBe(true);
});
