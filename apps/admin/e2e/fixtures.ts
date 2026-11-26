import { expect, test as base } from "@playwright/test";

export type { Page, Route } from "@playwright/test";

export const test = base.extend({
  page: async ({ page }, use) => {
    const messages: string[] = [];
    const apiFallback = async (route: import("@playwright/test").Route) => {
      await route.fulfill({
        json: { success: true, data: [], meta: { total: 0, page: 1, last_page: 1 } },
      });
    };
    page.on("console", (message) => {
      const text = message.text();
      const expectedResourceFailure = text.startsWith("Failed to load resource:") || text.includes("server responded with a status of");
      if (message.type() === "warning" || (message.type() === "error" && !expectedResourceFailure)) {
        messages.push(`[console.${message.type()}] ${text}`);
      }
    });
    page.on("pageerror", (error) => messages.push(`[pageerror] ${error.message}`));
    await page.route("**/api/**", apiFallback);

    try {
      await use(page);
      expect(messages, "browser console must be free of warnings and errors").toEqual([]);
    } finally {
      await page.unroute("**/api/**", apiFallback);
      await page.context().clearCookies();
      await page.evaluate(() => {
        try { window.localStorage.clear(); } catch {}
        try { window.sessionStorage.clear(); } catch {}
      }).catch(() => {});
    }
  },
});

export { expect };
