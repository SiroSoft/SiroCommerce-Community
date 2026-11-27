import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";

const DEMO_URL = "/dev/ui-primitives";

async function seriousOrCritical(page: Page) {
  const results = await new AxeBuilder({ page }).analyze();
  return results.violations.filter(
    (violation) => violation.impact === "serious" || violation.impact === "critical",
  );
}

/**
 * The demo page is server-rendered; the sticky bar's space reservation
 * (`ui-has-sticky-bar` on <html>) is added by a client effect, so its
 * presence proves hydration finished. Every test waits for it after goto
 * instead of asserting on pre-hydration HTML.
 */
async function waitForHydrated(page: Page) {
  await page.waitForFunction(
    () => document.documentElement.classList.contains("ui-has-sticky-bar"),
    { timeout: 30000 },
  );
}

test.describe("UI primitives accessibility (batch 1)", () => {
  test("demo page has no serious or critical axe violations", async ({ page }) => {
    await page.goto(DEMO_URL, { waitUntil: "load", timeout: 60000 });
    await waitForHydrated(page);
    const bad = await seriousOrCritical(page);
    expect(bad, JSON.stringify(bad, null, 2)).toEqual([]);
  });

  test("open modal has no serious or critical axe violations", async ({ page }) => {
    await page.goto(DEMO_URL, { waitUntil: "load", timeout: 60000 });
    await waitForHydrated(page);
    await page.getByRole("button", { name: "Open demo modal" }).click();
    await expect(page.getByRole("dialog", { name: "Demo modal" })).toBeVisible();
    const bad = await seriousOrCritical(page);
    expect(bad, JSON.stringify(bad, null, 2)).toEqual([]);
  });

  test("modal is keyboard operable: focus trap, Escape, focus return", async ({ page }) => {
    await page.goto(DEMO_URL, { waitUntil: "load", timeout: 60000 });
    await waitForHydrated(page);
    const trigger = page.getByRole("button", { name: "Open demo modal" });
    await trigger.focus();
    await page.keyboard.press("Enter");
    const dialog = page.getByRole("dialog", { name: "Demo modal" });
    await expect(dialog).toBeVisible();
    // Focus lands on the first field, not the close button.
    await expect(page.getByLabel("Email")).toBeFocused();
    // Tab cycles inside the dialog: email -> submit -> close -> email …
    await page.keyboard.press("Tab");
    await expect(page.getByRole("button", { name: "Subscribe" })).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(page.getByRole("button", { name: "Close" })).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(page.getByLabel("Email")).toBeFocused();
    // Escape closes and focus returns to the trigger.
    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    // Body scroll is unlocked after close.
    expect(await page.evaluate(() => document.body.style.overflow)).toBe("");
  });

  test("popover opens from the keyboard and closes on Escape", async ({ page }) => {
    await page.goto(DEMO_URL, { waitUntil: "load", timeout: 60000 });
    await waitForHydrated(page);
    const trigger = page.getByRole("button", { name: "Size guide" });
    await trigger.focus();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("dialog", { name: "Size guide" })).toBeVisible();
    await expect(trigger).toHaveAttribute("aria-expanded", "true");
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog", { name: "Size guide" })).toBeHidden();
    await expect(trigger).toHaveAttribute("aria-expanded", "false");
  });

  test("interactive controls meet the 44px tap target on mobile", async ({ page, isMobile }) => {
    test.skip(!isMobile, "tap-target check runs on the mobile project");
    await page.goto(DEMO_URL, { waitUntil: "load", timeout: 60000 });
    await waitForHydrated(page);
    for (const name of ["Open demo modal", "Size guide", "Add to cart"]) {
      const box = await page.getByRole("button", { name }).first().boundingBox();
      expect(box, `${name} has no bounding box`).not.toBeNull();
      expect(box!.width).toBeGreaterThanOrEqual(44);
      expect(box!.height).toBeGreaterThanOrEqual(44);
    }
    await page.getByRole("button", { name: "Open demo modal" }).click();
    const closeBox = await page.getByRole("button", { name: "Close" }).boundingBox();
    expect(closeBox!.width).toBeGreaterThanOrEqual(44);
    expect(closeBox!.height).toBeGreaterThanOrEqual(44);
  });

  test("sticky bar never covers the end of page content", async ({ page }) => {
    await page.goto(DEMO_URL, { waitUntil: "load", timeout: 60000 });
    await waitForHydrated(page);
    const bar = page.getByRole("region", { name: "Linen camp shirt" });
    await expect(bar).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.classList.contains("ui-has-sticky-bar"))).toBe(true);
    const barBox = (await bar.boundingBox())!;
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const endBox = (await page.locator("#demo-end").boundingBox())!;
    const viewportHeight = page.viewportSize()!.height;
    expect(endBox.y + endBox.height).toBeLessThanOrEqual(viewportHeight - barBox.height + 1);
    // Hiding the bar frees the reservation.
    await page.getByLabel("Show sticky bar").uncheck();
    await expect(bar).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.classList.contains("ui-has-sticky-bar"))).toBe(false);
  });

  test("records LCP for the PDP and for the primitives demo page", async ({ page }) => {
    for (const url of ["/product/linen-camp-shirt", DEMO_URL]) {
      // Buffered PerformanceObserver: this headless Chromium does not expose
      // LCP via performance.getEntriesByType (returns []).
      await page.addInitScript(() => {
        (window as unknown as { __lcp: number[] }).__lcp = [];
        new PerformanceObserver((list) => {
          const samples = (window as unknown as { __lcp: number[] }).__lcp;
          for (const entry of list.getEntries()) samples.push(Math.round(entry.startTime));
        }).observe({ type: "largest-contentful-paint", buffered: true });
      });
      await page.goto(url, { waitUntil: "load", timeout: 60000 });
      await page.waitForTimeout(1500);
      const lcp = await page.evaluate(() => {
        const samples = (window as unknown as { __lcp: number[] }).__lcp ?? [];
        return samples.length > 0 ? samples[samples.length - 1]! : null;
      });
      console.log(`LCP ${url}: ${lcp}ms`);
      expect(lcp, `no LCP entry recorded for ${url}`).not.toBeNull();
      // Smoke bound only: dev-server LCP on a loaded VM is noisy (7–15s
      // observed). The binding before/after comparison lives in the lane
      // closing report, not in this assertion.
      expect(lcp!).toBeLessThan(25000);
    }
  });
});
