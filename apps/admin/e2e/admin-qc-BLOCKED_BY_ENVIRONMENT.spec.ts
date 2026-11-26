import { expect, test } from "./fixtures";

test.describe("Admin QC live integration: BLOCKED_EXTERNAL", () => {
  test("BLOCKED_EXTERNAL: login to Action Center to Inventory requires a live backend", async ({ page }) => {
    test.info().annotations.push({
      type: "BLOCKED_EXTERNAL",
      description: "Requires an externally managed backend and live credentials.",
    });
    test.skip(true, "BLOCKED_EXTERNAL: live backend tests are excluded from local verification");
    await page.goto("/login");
    await page.getByLabel(/email/i).fill(process.env.E2E_ADMIN_EMAIL!);
    await page.getByLabel(/password/i).fill(process.env.E2E_ADMIN_PASSWORD!);
    await page.getByRole("button", { name: /log in/i }).click();
    await expect(page).toHaveURL(/\/$/);
    for (const route of ["/action-center", "/orders", "/fulfillment", "/inventory"]) {
      await page.goto(route);
      await expect(page.locator("main")).toBeVisible();
    }
  });
});
