import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

type Screen = {
  name: string;
  path: string;
};

const screens: Screen[] = [
  { name: "home", path: "/" },
  { name: "category-apparel", path: "/category/apparel" },
  { name: "product-linen-camp-shirt", path: "/product/linen-camp-shirt" },
  { name: "cart", path: "/cart" },
  { name: "checkout", path: "/checkout" },
];

const baselineScreens = new Set(["home", "category-apparel", "cart", "checkout"]);

const live = process.env.E2E_LIVE === "1";

async function settle(page: Page) {
  await page.addStyleTag({
    content: `
      *, *::before, *::after { caret-color: transparent !important; }
      nextjs-portal { display: none !important; }
    `,
  });

  await page.evaluate(async () => {
    const timeout = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

    for (const image of Array.from(document.images)) {
      image.loading = "eager";
    }

    window.scrollTo(0, document.body.scrollHeight);
    await timeout(100);
    window.scrollTo(0, 0);

    const pending = Array.from(document.images).filter((image) => !image.complete);
    await Promise.race([
      Promise.all(
        pending.map(
          (image) =>
            new Promise<void>((resolve) => {
              const done = () => resolve();
              image.addEventListener("load", done, { once: true });
              image.addEventListener("error", done, { once: true });
            }),
        ),
      ),
      timeout(5_000),
    ]);

    await Promise.race([document.fonts.ready.then(() => undefined), timeout(5_000)]);
  });
}

test.describe("storefront accessibility", () => {
  for (const screen of screens) {
    test(`axe ${screen.name} (${screen.path}) has no serious or critical violations`, async ({ page }, testInfo) => {
      await page.goto(screen.path, { waitUntil: "networkidle" });

      await expect(page.locator("#main-content"), `${screen.path} should render storefront layout`).toBeVisible();

      const results = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa"])
        .analyze();

      const blockers = results.violations.filter(
        (violation) => violation.impact === "serious" || violation.impact === "critical",
      );

      const detail = blockers.map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        help: violation.help,
        helpUrl: violation.helpUrl,
        nodes: violation.nodes.map((node) => ({
          target: node.target,
          html: node.html,
          failureSummary: node.failureSummary,
        })),
      }));

      await testInfo.attach("axe-violations.json", {
        body: JSON.stringify(detail, null, 2),
        contentType: "application/json",
      });

      if (detail.length > 0) {
        console.log(`[a11y] ${screen.path} serious/critical violations:\n${JSON.stringify(detail, null, 2)}`);
      }

      expect(
        blockers.map((violation) => violation.id),
        `serious/critical axe violations on ${screen.path}:\n${JSON.stringify(detail, null, 2)}`,
      ).toEqual([]);
    });
  }
});

test.describe("storefront visual baseline", () => {
  test.skip(live, "Visual baselines compare against local fixture data only; live runs axe-only.");

  for (const screen of screens) {
    const hasBaseline = baselineScreens.has(screen.name);

    test(`visual ${screen.name} ${hasBaseline ? "matches baseline" : "captures artifact"}`, async ({ page }, testInfo) => {
      await page.emulateMedia({ reducedMotion: "reduce" });
      await page.goto(screen.path, { waitUntil: "networkidle" });

      await expect(page.locator("#main-content"), `${screen.path} should render storefront layout`).toBeVisible();
      await settle(page);

      if (!hasBaseline) {
        const artifactPath = testInfo.outputPath(`${screen.name}.png`);
        await page.screenshot({ path: artifactPath, fullPage: true, animations: "disabled", caret: "hide" });
        await testInfo.attach(`${screen.name}.png`, { path: artifactPath, contentType: "image/png" });
        return;
      }

      await expect(page).toHaveScreenshot(`${screen.name}.png`, {
        fullPage: true,
        animations: "disabled",
        caret: "hide",
        maxDiffPixelRatio: 0.02,
      });
    });
  }
});
