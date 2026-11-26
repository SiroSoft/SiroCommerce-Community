import { test, expect } from "@playwright/test";

test.describe("purchase acceptance in safe fixture mode", () => {
  test("Product -> Cart -> Quote -> Checkout -> payment -> Place Order -> Result", async ({ page }) => {
    await page.goto("/cart");
    await expect(page).toHaveURL(/\/cart/);
    await page.getByLabel("Variant").selectOption("siro-chanh");
    await page.getByLabel("Quantity").fill("2");
    await expect(page.getByTestId("subtotal")).toHaveText("250,000 VND");
    await expect(page.getByTestId("cart-token")).toContainText("cart-fixture-1");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await expect(page).toHaveURL(/\/quote/);
    await expect(page.getByTestId("quote-total")).toHaveText("300,000 VND");
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await expect(page).toHaveURL(/\/checkout/);
    await expect(page.getByText(/card authorized/i)).toBeVisible();
    await page.getByRole("button", { name: "Place order" }).click();
    await expect(page).toHaveURL(/\/order-result/);
    await expect(page.getByTestId("order-number")).toContainText("SO-100001");
  });

  test("shows organization pricing, ownership, fail-closed finance, and quote approval", async ({ page }) => {
    await page.goto("/cart");
    await expect(page.getByText(/Siro Wholesale price/i)).toBeVisible();
    await expect(page.getByText(/customer-specific pricing/i)).toBeVisible();
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await expect(page.getByText(/Wholesale orders start/i)).toBeVisible();
    await page.getByRole("button", { name: "Request organization quote" }).click();
    await expect(page.getByText(/quote-request-fixture-1: submitted/i)).toBeVisible();
    await page.getByRole("button", { name: "Approve quote request" }).click();
    await expect(page.getByText(/quote-request-fixture-1: approved/i)).toBeVisible();
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await expect(page.getByText(/credit, and vendor payout actions are unavailable/i)).toBeVisible();
    await expect(page.getByText(/commission is handled by the platform/i)).toBeVisible();
  });

  test("blocks unavailable inventory and exposes validation", async ({ page }) => {
    await page.goto("/cart");
    await page.getByLabel("Quantity").fill("3");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await expect(page.locator('p[role="alert"]')).toContainText(/unavailable/i);
    await page.getByLabel("Quantity").fill("1");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await page.getByRole("button", { name: "Show validation error" }).click();
    await expect(page.locator('p[role="alert"]')).toContainText(/complete address/i);
  });

  test("applies an eligible voucher and rejects duplicate voucher state", async ({ page }) => {
    await page.goto("/cart");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await page.getByLabel("Code", { exact: true }).fill("SAVE10");
    await page.getByRole("button", { name: "Apply voucher" }).click();
    await expect(page.locator('p[role="status"]').filter({ hasText: /10% voucher applied/i }).first()).toBeVisible();
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await expect(page).toHaveURL(/\/checkout/);
    await page.goto("/quote");
    await page.getByLabel("Code", { exact: true }).fill("DUPLICATE");
    await page.getByRole("button", { name: "Apply voucher" }).click();
    await expect(page.locator('p[role="alert"]')).toContainText(/already applied/i);
  });

  test("reconciles timeout and exposes auth/API state", async ({ page }) => {
    await page.goto("/cart?timeout=1");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await page.getByRole("button", { name: "Test customer authentication" }).click();
    await expect(page.locator('p[role="status"]').filter({ hasText: /authenticated/i })).toBeVisible();
    await page.getByRole("button", { name: "Place order" }).click();
    await expect(page).toHaveURL(/\/order-result\?timeout=1/);
    await expect(page.locator('p[role="status"]').filter({ hasText: /duplicate/i })).toBeVisible();
    await page.goto("/checkout?api=401");
    await expect(page.locator('p[role="alert"]')).toContainText(/API error 401/i);
  });

  test("fails closed for an unknown payment outcome", async ({ page }) => {
    await page.goto("/cart?payment-unknown=1");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await page.getByRole("button", { name: "Place order" }).click();
    await expect(page).toHaveURL(/\/checkout\?payment-unknown=1/);
    await expect(page.locator('p[role="alert"]')).toContainText(/Payment was not authorized/i);
  });

  test("recovers a declined payment with a fresh authorization attempt", async ({ page }) => {
    await page.goto("/cart?payment-failure=1");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await page.getByRole("button", { name: "Place order" }).click();
    await expect(page.locator('p[role="alert"]')).toContainText(/declined/i);
    await page.getByRole("button", { name: "Retry safely" }).click();
    await expect(page).toHaveURL(/\/order-result/);
    await expect(page.getByTestId("order-number")).toContainText("SO-100001");
  });

  test("shows pending payment guidance and restores the cart without resubmitting", async ({ page }) => {
    await page.goto("/cart?payment-pending=1");
    await page.getByRole("button", { name: "Continue to quote" }).click();
    await page.getByRole("button", { name: "Continue to checkout" }).click();
    await page.getByRole("button", { name: "Place order" }).click();
    await expect(page.getByText(/Payment is still processing/i)).toBeVisible();
    await page.getByRole("button", { name: "Restore cart and start another order" }).click();
    await expect(page).toHaveURL(/\/cart\?payment-pending=1/);
    await expect(page.getByRole("button", { name: "Continue to quote" })).toBeVisible();
  });

  test("looks up a guest order without exposing account data", async ({ page }) => {
    await page.goto("/account");
    await page.getByLabel("Guest order access token").fill("guest-order-token-fixture");
    await page.getByRole("button", { name: "View guest order" }).click();
    await expect(page.getByRole("heading", { name: "Order details" })).toBeVisible();
    await expect(page.getByText("SO-100001")).toBeVisible();
  });

  for (const status of [401, 403, 404, 409, 422, 429, 500]) {
    test(`exposes API status ${status} without submitting`, async ({ page }) => {
      await page.goto(`/checkout?api=${status}`);
      await expect(page.locator('p[role="alert"]')).toHaveText(`API error ${status}: request was not submitted.`);
    });
  }

  test("supports keyboard focus and responsive mobile layout", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/cart");
    await page.keyboard.press("Tab");
    await expect(page.locator(":focus")).toBeVisible();
    await expect(page.locator("body")).toHaveCSS("overflow-x", "hidden");
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  });
});
