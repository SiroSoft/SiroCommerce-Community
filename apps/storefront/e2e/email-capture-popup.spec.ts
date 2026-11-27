import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

/**
 * Email capture popup E2E (siro/email-capture-popup).
 *
 * The popup's public config comes from the backend extension endpoint, so
 * this spec stubs the extension API with page.route and requires
 * NEXT_PUBLIC_STOREFRONT_API_BASE_URL to be set when the suite runs
 * (e.g. NEXT_PUBLIC_STOREFRONT_API_BASE_URL=http://127.0.0.1:8094).
 * Without it the component intentionally renders nothing and the spec
 * skips instead of failing on a missing backend.
 *
 * Trigger used here: scroll past 50% (the time-delay trigger is 8s and
 * exit-intent is unreliable in automation).
 */
test.describe("email capture popup", () => {
  test.beforeEach(async ({ page }) => {
    test.skip(
      !process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL,
      "NEXT_PUBLIC_STOREFRONT_API_BASE_URL is required for the popup E2E",
    );
    await page.route("**/api/ext/siro/email-capture-popup/public", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          data: { active: true, delay_ms: 8000, coupon_enabled: true, coupon_percent: 10 },
        }),
      }),
    );
    await page.route("**/api/consent/marketing", (route) =>
      route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ success: true }) }),
    );
    await page.route("**/api/ext/siro/email-capture-popup/subscribe", (route) =>
      route.fulfill({
        status: 201,
        contentType: "application/json",
        body: JSON.stringify({
          data: { id: "sub-e2e", email: "e2e@example.com", coupon_code: "WELCOME-E2E1234", already_subscribed: false, mail_sent: true },
        }),
      }),
    );
  });

  test("opens on scroll, passes axe with the dialog open, and subscribes", async ({ page }) => {
    await page.goto("/", { waitUntil: "load", timeout: 60_000 });
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const dialog = page.getByRole("dialog", { name: /10% off/ });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const results = await new AxeBuilder({ page }).analyze();
    const serious = results.violations.filter(
      (violation) => violation.impact === "serious" || violation.impact === "critical",
    );
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await page.getByTestId("email-capture-popup-email").fill("e2e@example.com");
    await page.getByRole("button", { name: "Claim my discount" }).click();
    await expect(page.getByTestId("email-capture-popup-coupon")).toContainText("WELCOME-E2E1234");
  });

  test("closes on ESC", async ({ page }) => {
    await page.goto("/", { waitUntil: "load", timeout: 60_000 });
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const dialog = page.getByRole("dialog", { name: /10% off/ });
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
  });
});
