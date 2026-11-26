import { test, expect, type Page } from "./fixtures";

const TOKEN = "eyJhbGciOiJub25lIn0.eyJleHAiOjk5OTk5OTk5OTl9.signature";

async function seedSession(page: Page) {
  await page.route("**/api/auth/me", (route) => route.fulfill({ json: { success: true, data: { id: 1, email: "admin@example.test", role: "admin" } } }));
  await page.route("**/api/auth/refresh", (route) => route.fulfill({ json: { success: true, data: { token: TOKEN, refresh_token: TOKEN } } }));
  await page.addInitScript(({ token }) => {
    localStorage.setItem("siro_access_token", token);
    localStorage.setItem("siro_refresh_token", token);
    localStorage.setItem("siro_user", JSON.stringify({ id: 1, email: "admin@example.test", role: "admin" }));
  }, { token: TOKEN });
}

test("B2B workspace fails closed for unavailable financial operations", async ({ page }) => {
  await page.route("**/api/v2/admin/vendors", (route) => route.fulfill({ json: { data: [] } }));
  await seedSession(page);
  await page.goto("/b2b");
  await expect(page.getByRole("heading", { name: "B2B operations" })).toBeVisible();
  await page.getByRole("tab", { name: "Vendors" }).click();
  await expect(page.getByRole("heading", { name: "Commission ledger" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Payout provider" })).toBeVisible();
});
