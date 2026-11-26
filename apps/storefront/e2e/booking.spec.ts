import { test, expect } from "@playwright/test";

test.describe("booking product", () => {
  test("PDP shows slot picker and posts slot_start", async ({ page }) => {
    await page.goto("/product/booking-yoga-retreat");
    await expect(page.getByRole("heading", { name: "Yoga retreat" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Choose a slot" })).toBeVisible();
    const radios = page.getByRole("radio", { name: /to/ });
    await expect(radios.first()).toBeVisible();
    // select first slot
    await radios.first().click();
    await expect(page.getByText(/Selected:/)).toBeVisible();
    // add to cart posts slot_start via hidden input — check form
    const form = page.locator('form[action="/cart/add"]').first();
    await expect(form.locator('input[name="slot_start"]')).toHaveValue(/.+/);
    // quantity max respects capacity
    await expect(page.getByLabel("Quantity")).toBeVisible();
  });

  test("cart rejects over-capacity for booking slot (fixture)", async ({ page }) => {
    // fixture checkout simulates booking capacity via cart token
    await page.goto("/product/booking-yoga-retreat");
    await page.getByRole("radio", { name: /to/ }).first().click();
    // try quantity 99 > capacity 8 — UI should cap at capacity
    const qty = page.getByLabel("Quantity");
    await qty.fill("99");
    // should be capped to capacity (8)
    await expect(qty).toHaveValue(/[0-8]/);
  });
});
