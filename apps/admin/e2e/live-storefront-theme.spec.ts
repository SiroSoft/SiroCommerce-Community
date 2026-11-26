import { test, expect } from "@playwright/test";
import { LIVE_API_URL, liveAnnotations } from "./live-guard";

const ADMIN_EMAIL = "demo-buyer-3@example.test";
const ADMIN_PASSWORD = "DemoPassword!123";
const TAG = `theme${Date.now() % 100000}`;

liveAnnotations(test, "storefront theme lifecycle");

test("Theme Builder API saves, previews, publishes, and exposes revisions", async ({ request }) => {
  const scope = { "X-Store-Id": "default", "X-Channel-Id": "default", "X-Locale": "en", "X-Currency": "VND" };
  const loginResponse = await request.post(`${LIVE_API_URL}/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
  expect(loginResponse.ok()).toBeTruthy();
  const loginBody = (await loginResponse.json()) as { data?: { access?: string } };
  const headers = { ...scope, Authorization: `Bearer ${loginBody.data!.access}`, Accept: "application/json" };
  const currentResponse = await request.get(`${LIVE_API_URL}/api/admin/storefront/config`, { headers });
  expect(currentResponse.ok()).toBeTruthy();
  const current = (await currentResponse.json()) as { data: { config: Record<string, any>; version: number } };
  const config = current.data.config;
  const body = { expected_version: current.data.version, theme_key: config.theme.key, vertical_key: config.vertical.key, brand: { ...config.brand, name: `Live Theme ${TAG}` }, tokens: config.theme.tokens, navigation: config.navigation, homepage: config.homepage, presentation: config.presentation, policies: config.policies };
  const save = await request.put(`${LIVE_API_URL}/api/admin/storefront/config`, { headers: { ...headers, "Idempotency-Key": `theme-save-${TAG}` }, data: body });
  expect(save.status()).toBeLessThan(300);
  const saved = (await save.json()) as { data: { version: number } };
  const preview = await request.post(`${LIVE_API_URL}/api/admin/storefront/config/preview`, { headers, data: { ...body, expected_version: saved.data.version } });
  expect(preview.status()).toBeLessThan(300);
  const publish = await request.post(`${LIVE_API_URL}/api/admin/storefront/config/publish`, { headers: { ...headers, "Idempotency-Key": `theme-publish-${TAG}` }, data: { expected_version: saved.data.version } });
  expect(publish.status()).toBeLessThan(300);
  const revisions = await request.get(`${LIVE_API_URL}/api/admin/storefront/config/revisions`, { headers });
  expect(revisions.ok()).toBeTruthy();
  expect(((await revisions.json()) as { data: unknown[] }).data.length).toBeGreaterThan(0);
});

test("Theme API rejects a stale optimistic-concurrency write", async ({ request }) => {
  const scope = { "X-Store-Id": "default", "X-Channel-Id": "default", "X-Locale": "en", "X-Currency": "VND" };
  const loginResponse = await request.post(`${LIVE_API_URL}/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
  expect(loginResponse.ok()).toBeTruthy();
  const loginBody = (await loginResponse.json()) as { data?: { access?: string } };
  expect(loginBody.data?.access).toBeTruthy();
  const headers = { ...scope, Authorization: `Bearer ${loginBody.data!.access}`, Accept: "application/json" };
  const currentResponse = await request.get(`${LIVE_API_URL}/api/admin/storefront/config`, { headers });
  expect(currentResponse.ok()).toBeTruthy();
  const current = (await currentResponse.json()) as { data: Record<string, unknown> };
  const version = Number(current.data.version);
  test.skip(version <= 1, "A stale version requires at least one existing write");
  const stale = await request.put(`${LIVE_API_URL}/api/admin/storefront/config`, {
    headers: { ...headers, "Idempotency-Key": `stale-theme-${TAG}` },
    data: { expected_version: Math.max(1, version - 1), theme_key: "editorial", vertical_key: "general" },
  });
  expect(stale.status()).toBe(409);
});

test("Seasonal theme publish exposes seasonal tokens via public config", async ({ request }) => {
  const scope = { "X-Store-Id": "default", "X-Channel-Id": "default", "X-Locale": "en", "X-Currency": "VND" };
  const loginResponse = await request.post(`${LIVE_API_URL}/api/auth/login`, { data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } });
  expect(loginResponse.ok()).toBeTruthy();
  const loginBody = (await loginResponse.json()) as { data?: { access?: string } };
  const headers = { ...scope, Authorization: `Bearer ${loginBody.data!.access}`, Accept: "application/json" };
  const currentResponse = await request.get(`${LIVE_API_URL}/api/admin/storefront/config`, { headers });
  expect(currentResponse.ok()).toBeTruthy();
  const current = (await currentResponse.json()) as { data: { config: Record<string, unknown>; version: number } };
  const config = current.data.config as Record<string, unknown>;
  const body = { expected_version: current.data.version, theme_key: "seasonal", vertical_key: (config.vertical as Record<string, unknown>).key ?? "general", brand: config.brand, tokens: (config.theme as Record<string, unknown>).tokens, navigation: config.navigation, homepage: config.homepage, presentation: config.presentation, policies: config.policies };
  const save = await request.put(`${LIVE_API_URL}/api/admin/storefront/config`, { headers: { ...headers, "Idempotency-Key": `seasonal-save-${TAG}` }, data: body });
  expect(save.status()).toBeLessThan(300);
  const saved = (await save.json()) as { data: { version: number } };
  const publish = await request.post(`${LIVE_API_URL}/api/admin/storefront/config/publish`, { headers: { ...headers, "Idempotency-Key": `seasonal-publish-${TAG}` }, data: { expected_version: saved.data.version } });
  expect(publish.status()).toBeLessThan(300);
  const pub = await request.get(`${LIVE_API_URL}/api/storefront/config`, { headers: scope });
  expect(pub.ok()).toBeTruthy();
  const pubBody = (await pub.json()) as { data: { theme: { key: string; tokens: { colors: Record<string, string> } } } };
  expect(pubBody.data.theme.key).toBe("seasonal");
  expect(pubBody.data.theme.tokens.colors.accent.toLowerCase()).toBe("#7a3b2e");
});
