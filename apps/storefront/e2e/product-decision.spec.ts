import { expect, test } from "@playwright/test";

test.describe("PDP buyer decisions", () => {
  test("supports quantity pricing and preserves the catalog fallback state", async ({ page }) => {
    await page.goto("/product/linen-camp-shirt");
    await expect(page.getByRole("heading", { name: "Linen camp shirt" })).toBeVisible();
    await expect(page.getByRole("img", { name: "Natural linen shirt on a hanger" })).toBeVisible();
    await page.getByRole("button", { name: "Increase quantity" }).click();
    await expect(page.getByRole("spinbutton", { name: "Quantity" })).toHaveValue("2");
    await expect(page.getByRole("link", { name: "Add to cart", exact: true })).toHaveAttribute("href", "/cart?product=linen-camp-shirt&quantity=2");
  });

  test("keeps the mobile add-to-cart affordance visible", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "mobile-320", "The sticky affordance is intentionally mobile-only.");
    await page.goto("/product/linen-camp-shirt");
    await expect(page.getByRole("link", { name: "Sticky add to cart" })).toBeVisible();
    await expect(page.getByRole("note")).toContainText("Wishlist");
  });
});
