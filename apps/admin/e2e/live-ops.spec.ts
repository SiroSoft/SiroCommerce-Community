import { test, expect, type Page } from "@playwright/test";
import { LIVE_API_URL, liveAnnotations } from "./live-guard";

/**
 * LIVE ops journey against the real backend + seeded MySQL demo data.
 * Run: LIVE_BACKEND=1 E2E_API_URL=http://localhost:8090 npx playwright test e2e/live-ops.spec.ts
 * Requires: backend on E2E_API_URL, admin frontend on baseURL, demo seed
 * (demo-buyer-3@example.test / DemoPassword!123 as admin, member of
 * demo-company-2; demo-quote-2, demo-variant-1-1, demo-fulfillment-10).
 */
const ADMIN_EMAIL = "demo-buyer-3@example.test";
const ADMIN_PASSWORD = "DemoPassword!123";
const TAG = `ops${Date.now() % 100000}`;
const API = LIVE_API_URL;

liveAnnotations(test, "ops journey");

async function login(page: Page) {
  await page.goto("/login");
  await page.getByLabel(/email/i).fill(ADMIN_EMAIL);
  await page.getByRole("textbox", { name: /password/i }).fill(ADMIN_PASSWORD);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/(\?.*)?$/, { timeout: 20000 });
}

test.describe("LIVE ops journey (MySQL demo)", () => {
  test("b2b: create organization and quote request", async ({ page }) => {
    await login(page);
    await page.goto("/b2b");
    const org = `Live Org ${TAG}`;
    await page.locator("#company-name").fill(org);
    await page.getByRole("button", { name: "Create organization" }).click();
    await expect(page.locator("body")).toContainText("Accepted by the backend", { timeout: 20000 });
    await page.getByRole("tab", { name: "Quotes" }).click();
    await page.locator("#quote-company").fill("demo-company-2");
    const quoteResp = page.waitForResponse((r) => r.url().includes("/api/v2/b2b/companies/demo-company-2/quotes"), { timeout: 20000 });
    await page.getByRole("button", { name: "Create quote request" }).click();
    const resp = await quoteResp;
    expect(resp.status()).toBeLessThan(300);
    const createdId = ((await resp.json()) as { data?: { id?: string } }).data?.id;
    expect(createdId).toBeTruthy();
    await page.locator("#quote-id").fill(createdId as string);
    await page.getByRole("button", { name: "Refresh quote" }).click();
    await expect(page.locator("body")).toContainText(createdId as string, { timeout: 20000 });
    await page.locator("#quote-id").fill("demo-quote-2");
    await page.getByRole("button", { name: "Refresh quote" }).click();
    await expect(page.locator("body")).toContainText("demo-quote-2", { timeout: 20000 });
  });

  test("inventory: adjust on-hand and transfer stock", async ({ page }) => {
    await login(page);
    await page.goto("/inventory");
    await page.getByLabel("Variant ID").fill("demo-variant-1-1");
    await expect(page.locator("body")).toContainText("Available", { timeout: 20000 });
    await page.getByLabel("New on-hand quantity").fill("120");
    await page.getByRole("button", { name: "Apply adjustment" }).click();
    await expect(page.locator("body")).toContainText("Adjustment accepted", { timeout: 20000 });
    await page.getByRole("textbox", { name: "Source ID", exact: true }).fill("demo-warehouse-1");
    await page.getByRole("textbox", { name: "Destination source ID", exact: true }).fill("demo-warehouse-2");
    await page.getByLabel("Transfer quantity").fill("1");
    await page.getByRole("button", { name: "Transfer stock" }).click();
    await expect(page.locator("body")).not.toContainText("Failed to transfer", { timeout: 20000 });
  });

  test("fulfillment: track seeded fulfillment", async ({ page }) => {
    await login(page);
    await page.goto("/fulfillment");
    await page.getByLabel("Fulfillment ID").fill("demo-fulfillment-10");
    await page.getByRole("button", { name: "Refresh" }).click();
    await expect(page.locator("body")).toContainText("demo-fulfillment-10", { timeout: 20000 });
  });

  test("reporting: overview loads real numbers", async ({ page }) => {
    await login(page);
    await page.goto("/reporting");
    await expect(page.locator("body")).toContainText("150", { timeout: 20000 });
  });

  test("recovery: forgot, reset via UI, login with new password, verify email", async ({ page, request }) => {
    const email = `live-recover-${TAG}@example.test`;
    const oldPassword = "Recover1!x";
    const newPassword = "Recover2!y";
    const adminSession = await request.post(`${API}/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
    expect(adminSession.ok()).toBeTruthy();
    const adminToken = ((await adminSession.json()) as { data?: { access?: string } }).data?.access;
    expect(adminToken).toBeTruthy();
    const created = await request.post(`${API}/api/users`, {
      headers: { Authorization: `Bearer ${adminToken}`, Accept: "application/json" },
      data: { name: `Live Recover ${TAG}`, email, password: oldPassword, role: "customer" },
    });
    expect(created.ok()).toBeTruthy();

    const forgot = await request.post(`${API}/api/auth/forgot-password`, { data: { email } });
    expect(forgot.ok()).toBeTruthy();
    const resetToken = ((await forgot.json()) as { data?: { reset_token?: string } }).data?.reset_token;
    expect(resetToken).toBeTruthy();

    await page.goto(`/reset-password?token=${resetToken}`);
    await page.locator("#password").fill(newPassword);
    await page.locator("#password_confirmation").fill(newPassword);
    await page.locator("#password_confirmation").press("Enter");
    await expect(page).toHaveURL(/\/login(\?.*)?$/, { timeout: 20000 });

    await page.getByLabel(/email/i).fill(email);
    await page.getByRole("textbox", { name: /password/i }).fill(newPassword);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/(\?.*)?$/, { timeout: 20000 });

    const session = await request.post(`${API}/api/auth/login`, { data: { email, password: newPassword } });
    expect(session.ok()).toBeTruthy();
    const access = ((await session.json()) as { data?: { access?: string } }).data?.access;
    expect(access).toBeTruthy();
    const resend = await request.post(`${API}/api/auth/verify-email/resend`, { headers: { Authorization: `Bearer ${access}`, Accept: "application/json" } });
    expect(resend.ok()).toBeTruthy();
    const verifyToken = ((await resend.json()) as { data?: { verification_token?: string } }).data?.verification_token;
    expect(verifyToken).toBeTruthy();
    const verify = await request.post(`${API}/api/auth/verify-email`, { data: { token: verifyToken } });
    expect(verify.ok()).toBeTruthy();
    const me = await request.get(`${API}/api/auth/me`, { headers: { Authorization: `Bearer ${access}`, Accept: "application/json" } });
    expect(((await me.json()) as { data?: { email_verified_at?: string } }).data?.email_verified_at).toBeTruthy();
  });
});
