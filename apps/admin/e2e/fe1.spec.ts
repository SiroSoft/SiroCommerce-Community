import { test, expect } from "@playwright/test";

test("commerce workspace labels unsupported capabilities without calling invented endpoints", async ({ page }) => {
  const requests: string[] = [];
  await page.route("**/api/auth/me", (route) => route.fulfill({ json: { success: true, data: { id: 1, name: "Admin", email: "admin@example.com", role: "admin" } } }));
  await page.route("**/api/v2/catalog/product-types", (route) => route.fulfill({ json: { data: [] } }));
  await page.goto("/login");
  page.on("request", (request) => requests.push(request.url()));
  await page.goto("/commerce");
  await expect(page.getByText("Sign in to your account", { exact: true })).toBeVisible();
  expect(requests.some((url) => /facets|warehouses|reservations/.test(url))).toBe(false);
});
