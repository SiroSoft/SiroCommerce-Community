import { expect, test } from "@playwright/test";

test("account exposes API-backed loyalty and return history", async ({ page }) => {
  await page.goto("/account");
  await expect(page.getByRole("heading", { name: "Ritual points" })).toBeVisible();
  await page.getByRole("button", { name: "Earn 50 points" }).click();
  await expect(page.getByText(/Earn points after an eligible order is delivered/i)).toBeVisible();
  await expect(page.getByRole("heading", { name: "Returns & refunds" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Subscriptions" }).first()).toBeVisible();
});
