import { beforeEach, describe, expect, it, vi } from "vitest";

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
const fetchWithAuthMock = vi.hoisted(() => vi.fn());
const sessionMock = vi.hoisted(() => ({ logout: vi.fn() }));
vi.mock("@/services/api", () => ({
  default: apiMock,
  fetchWithAuth: fetchWithAuthMock,
  session: sessionMock,
}));

import { adminConsoleService } from "@/services/admin-console.service";
import { authService } from "@/services/auth.service";
import { b2bService } from "@/services/b2b.service";
import { dashboardBiService, normalizeSalesBlocks } from "@/services/dashboard-bi.service";
import { dashboardService } from "@/services/dashboard.service";
import { extensionsService } from "@/services/extensions.service";
import { fe1Service } from "@/services/fe1.service";
import { launchService } from "@/services/launch.service";
import { mediaLibraryService } from "@/services/media-library.service";
import { productsService } from "@/services/products.service";
import { profileService } from "@/services/profile.service";
import { reportingService } from "@/services/reporting.service";
import { serverService } from "@/services/server.service";
import { settingsService } from "@/services/settings.service";
import { uploadService } from "@/services/upload.service";
import { vendorsService } from "@/services/vendors.service";

const envelope = (data: unknown) => ({ data: { data } });

const validVendor = {
  id: "v-1",
  name: "North Loom",
  slug: "north-loom",
  status: "active" as const,
  commission_bps: 500,
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe("vendorsService contract", () => {
  it("filters malformed vendors from list responses and tolerates non-array payloads", async () => {
    apiMock.get.mockResolvedValueOnce(envelope([validVendor, { id: "bad", name: 42 }, null]));
    await expect(vendorsService.list({ state: "active" })).resolves.toMatchObject({ data: [validVendor] });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/vendors", { params: { state: "active" } });

    apiMock.get.mockResolvedValueOnce({ data: { data: null } });
    await expect(vendorsService.list()).resolves.toMatchObject({ data: [] });
  });

  it("sends idempotency keys and encodes ids for writes", async () => {
    apiMock.post.mockResolvedValueOnce(envelope(validVendor));
    await expect(vendorsService.create({ name: "North Loom", slug: "north-loom" }, "key-1")).resolves.toEqual(validVendor);
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/vendors", { name: "North Loom", slug: "north-loom" }, { idempotencyKey: "key-1" });

    apiMock.put.mockResolvedValueOnce(envelope({ ...validVendor, status: "suspended" }));
    await vendorsService.update("vendor/1", { status: "suspended" }, "key-2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/vendors/vendor%2F1", { status: "suspended" }, { idempotencyKey: "key-2" });

    apiMock.delete.mockResolvedValueOnce({});
    await vendorsService.delete("vendor/1", "key-3");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/vendors/vendor%2F1", { idempotencyKey: "key-3" });
  });

  it("loads ledger, payouts and payout mutations", async () => {
    const ledger = { entries: [], totals: { gross: 0, commission: 0, net: 0, pending: 0, available: 0, paid: 0 } };
    apiMock.get.mockResolvedValueOnce(envelope(ledger));
    await expect(vendorsService.ledger("v-1", { state: "available" })).resolves.toEqual(ledger);
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/vendors/v-1/ledger", { params: { state: "available" } });

    apiMock.get.mockResolvedValueOnce(envelope([{ id: "p-1" }]));
    await expect(vendorsService.payouts("v-1")).resolves.toEqual([{ id: "p-1" }]);
    apiMock.get.mockResolvedValueOnce({ data: { data: null } });
    await expect(vendorsService.payouts("v-1")).resolves.toEqual([]);

    apiMock.post.mockResolvedValueOnce(envelope({ id: "p-2" }));
    await vendorsService.createPayout("v-1", { period_start: "2026-11-01", period_end: "2026-11-21" }, "pay-1");
    expect(apiMock.post).toHaveBeenCalledWith(
      "/api/admin/vendors/v-1/payouts",
      { period_start: "2026-11-01", period_end: "2026-11-21" },
      { idempotencyKey: "pay-1" },
    );

    apiMock.post.mockResolvedValueOnce(envelope({ id: "p-2", state: "paid" }));
    await vendorsService.markPayoutPaid("p/2");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/vendor-payouts/p%2F2/mark-paid", {});

    apiMock.post.mockResolvedValueOnce(envelope({ id: "p-3", state: "cancelled" }));
    await vendorsService.cancelPayout("p-3");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/vendor-payouts/p-3/cancel", {});
  });

  it("exports payout CSV with metadata and maps export failures", async () => {
    fetchWithAuthMock.mockResolvedValueOnce(new Response("a,b", {
      status: 200,
      headers: {
        "Content-Disposition": 'attachment; filename="payout-p-1.csv"',
        "X-Export-Rows": "3",
      },
    }));
    const result = await vendorsService.exportPayoutCsv("p-1");
    expect(result.filename).toBe("payout-p-1.csv");
    expect(result.rows).toBe(3);
    expect(result.blob.size).toBeGreaterThan(0);

    fetchWithAuthMock.mockResolvedValueOnce(new Response(JSON.stringify({
      message: "Payout is not exportable",
      meta: { error_code: "PAYOUT_CONFLICT", errors: { state: ["must be paid"] } },
    }), {
      status: 409,
      headers: { "Content-Type": "application/json", "X-Correlation-Id": "corr-9" },
    }));
    await expect(vendorsService.exportPayoutCsv("p-1")).rejects.toMatchObject({
      status: 409,
      kind: "conflict",
      errorCode: "PAYOUT_CONFLICT",
      errors: { state: ["must be paid"] },
      correlationId: "corr-9",
    });

    fetchWithAuthMock.mockResolvedValueOnce(new Response(null, { status: 500 }));
    await expect(vendorsService.exportPayoutCsv("p-1")).rejects.toMatchObject({
      status: 500,
      message: "Payout export failed (500)",
    });
  });
});

describe("productsService transfer and mapping contract", () => {
  it("dedupes catalog products by id and sku while rejecting invalid records", async () => {
    apiMock.get.mockResolvedValueOnce({
      data: {
        data: [
          { id: 1, name: "One", sku: "SKU-1", price: 10 },
          { id: 1, name: "One duplicate", sku: "SKU-1", price: 10 },
          { id: 2, name: "Two", sku: "SKU-1", price: 20 },
          { id: 3, name: "", price: 20 },
        ],
        meta: { page: 1, per_page: 20, total: 4, last_page: 1 },
      },
    });
    const result = await productsService.list({ page: 1 });
    expect(result.data.map((product) => product.id)).toEqual([1]);
  });

  it("rejects invalid product payloads and strips response-only fields on writes", async () => {
    apiMock.get.mockResolvedValueOnce(envelope({ id: 1, name: "", price: 1 }));
    await expect(productsService.get(1)).rejects.toThrow("invalid product data");

    apiMock.post.mockResolvedValueOnce(envelope({ id: 1, name: "", price: 1 }));
    await expect(productsService.create({ name: "One", price: 1 } as never, "k1")).rejects.toThrow("invalid product data");

    apiMock.put.mockResolvedValueOnce(envelope({ id: 1, name: "One", price: 10, image: "/x.jpg" }));
    const updated = await productsService.update(1, { name: "One", price: 10, image: "/x.jpg" } as never, "k2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/products/1", { name: "One", price: 10 }, { idempotencyKey: "k2" });
    expect(updated?.name).toBe("One");

    apiMock.delete.mockResolvedValueOnce({});
    await productsService.delete(1, "k3");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/products/1", { idempotencyKey: "k3" });
  });

  it("validates CSV import input and passes dry-run + idempotency", async () => {
    await expect(productsService.importCsv({}, "k1")).rejects.toThrow("Choose a CSV file or provide CSV text.");

    apiMock.post.mockResolvedValueOnce(envelope({ created: 1, updated: 0, skipped: 0, errors: [], dry_run: true, rows_seen: 1 }));
    await productsService.importCsv({ csv: "sku,name", dryRun: true }, "k2");
    const form = apiMock.post.mock.calls.at(-1)?.[1] as FormData;
    expect(form.get("csv")).toBe("sku,name");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/import", expect.any(FormData), {
      idempotencyKey: "k2",
      params: { dry_run: 1 },
    });
  });

  it("transitions a product lifecycle with version and idempotency", async () => {
    apiMock.post.mockResolvedValueOnce(envelope({ id: 5, name: "Five", price: 1, is_active: false }));
    await productsService.transition(5, "archived", 3, "k4");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/5/lifecycle", { state: "archived", version: 3 }, { idempotencyKey: "k4" });
  });

  it("maps product export failures to AdminApiError", async () => {
    fetchWithAuthMock.mockResolvedValueOnce(new Response(JSON.stringify({
      message: "Export forbidden",
      meta: { error_code: "EXPORT_FORBIDDEN" },
    }), { status: 403, headers: { "Content-Type": "application/json" } }));
    await expect(productsService.exportCsv()).rejects.toMatchObject({
      status: 403,
      kind: "permission",
      errorCode: "EXPORT_FORBIDDEN",
      message: "Export forbidden",
    });
  });
});

describe("mediaLibraryService contract", () => {
  it("lists without params, uploads without metadata and rejects empty uploads", async () => {
    apiMock.get.mockResolvedValueOnce({ data: { data: { data: [] } } });
    await expect(mediaLibraryService.list()).resolves.toEqual({ items: [], total: 0 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/media-library", undefined);

    const file = new File(["x"], "clip.mp4", { type: "video/mp4" });
    apiMock.post.mockResolvedValueOnce({ data: { data: null } });
    await expect(mediaLibraryService.upload(file)).rejects.toThrow("Upload failed");

    apiMock.post.mockResolvedValueOnce({ data: { data: { id: "m1", url: "/x.jpg", type: "image" } } });
    await expect(mediaLibraryService.upload(file)).resolves.toMatchObject({ id: "m1" });
  });
});

describe("launchService contract", () => {
  it("loads status and providers, then seeds the demo with a provided key", async () => {
    apiMock.get.mockResolvedValueOnce(envelope({ ready: true, score: 100, checks: [] }));
    await expect(launchService.status()).resolves.toMatchObject({ ready: true });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/launch/status");

    apiMock.get.mockResolvedValueOnce(envelope({ payment: { configured: true } }));
    await expect(launchService.providers()).resolves.toMatchObject({ payment: { configured: true } });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/providers/status");

    apiMock.post.mockResolvedValueOnce(envelope({ created: {}, skipped: {} }));
    await launchService.seedDemo("seed-key");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/launch/seed-demo", undefined, { idempotencyKey: "seed-key" });

    apiMock.post.mockResolvedValueOnce(envelope({ created: {}, skipped: {} }));
    await launchService.seedDemo();
    expect(apiMock.post.mock.calls.at(-1)?.[2]).toMatchObject({ idempotencyKey: expect.any(String) });
  });
});

describe("dashboard services contract", () => {
  it("loads dashboard stats and BI ranges", async () => {
    apiMock.get.mockResolvedValueOnce({ data: { success: true, data: { total_orders: 3 } } });
    await expect(dashboardService.getStats()).resolves.toMatchObject({ total_orders: 3 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/dashboard/stats");

    apiMock.get.mockResolvedValueOnce({ data: { success: true, data: { range: "30d" } } });
    await expect(dashboardBiService.getBi("30d")).resolves.toMatchObject({ range: "30d" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/dashboard/bi", { params: { range: "30d" } });
  });

  it("normalizes single and multi-currency sales blocks", () => {
    expect(normalizeSalesBlocks(undefined)).toEqual([]);
    expect(normalizeSalesBlocks({ currency: "VND" } as never)).toEqual([{ currency: "VND" }]);
    expect(normalizeSalesBlocks([{ currency: "VND" }] as never)).toEqual([{ currency: "VND" }]);
  });
});

describe("admin console and server contract", () => {
  it("searches with pagination and loads audit events", async () => {
    apiMock.get.mockResolvedValueOnce(envelope({ data: [], meta: {}, type: "orders" }));
    await adminConsoleService.search("acme", "orders", 2, 50);
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/search", {
      params: { q: "acme", type: "orders", page: 2, per_page: 50 },
    });

    apiMock.get.mockResolvedValueOnce(envelope([{ id: "a1" }]));
    await expect(adminConsoleService.audit(5, 10)).resolves.toEqual([{ id: "a1" }]);
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/audit-logs", { params: { limit: 5, offset: 10 } });
  });

  it("loads server info", async () => {
    apiMock.get.mockResolvedValueOnce(envelope({ version: "1.0.0" }));
    await expect(serverService.getInfo()).resolves.toEqual({ version: "1.0.0" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/server/info");
  });
});

describe("settings reporting profile upload and auth contracts", () => {
  it("reads and writes settings with idempotency", async () => {
    apiMock.get.mockResolvedValueOnce(envelope({ store_name: "Siro" }));
    await expect(settingsService.get()).resolves.toEqual({ store_name: "Siro" });
    apiMock.put.mockResolvedValueOnce(envelope({ store_name: "Next" }));
    await expect(settingsService.update({ store_name: "Next" } as never, "set-1")).resolves.toEqual({ store_name: "Next" });
    expect(apiMock.put).toHaveBeenCalledWith("/api/settings", { store_name: "Next" }, { idempotencyKey: "set-1" });
  });

  it("binds every reporting operation to its route and query", async () => {
    apiMock.get.mockResolvedValue(envelope({ ok: true }));
    await reportingService.overview({ from: "2026-01-01" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/overview", { params: { from: "2026-01-01" } });
    await reportingService.failures();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/failures", { params: undefined });
    await reportingService.advanced({ page: 2 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/advanced", { params: { page: 2 } });
    await reportingService.insights({ page: 3 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/reports/advanced", { params: { page: 3, dimension: "insights" } });
  });

  it("reads, updates and changes the profile password", async () => {
    apiMock.get.mockResolvedValueOnce(envelope({ id: 1, name: "A" }));
    await expect(profileService.get()).resolves.toEqual({ id: 1, name: "A" });
    apiMock.put.mockResolvedValueOnce(envelope({ id: 1, name: "B" }));
    await profileService.update({ name: "B" } as never, "prof-1");
    expect(apiMock.put).toHaveBeenCalledWith("/api/profile", { name: "B" }, { idempotencyKey: "prof-1" });
    apiMock.put.mockResolvedValueOnce({ data: {} });
    await profileService.changePassword({ current_password: "a", password: "b", password_confirmation: "b" } as never, "prof-2");
    expect(apiMock.put).toHaveBeenCalledWith("/api/profile/password", expect.any(Object), { idempotencyKey: "prof-2" });
  });

  it("uploads files through both multipart endpoints", async () => {
    apiMock.post.mockResolvedValueOnce(envelope({ url: "/storage/a.png" }));
    await expect(uploadService.upload(new File(["x"], "a.png"))).resolves.toBe("/storage/a.png");
    expect(apiMock.post).toHaveBeenCalledWith("/api/upload", expect.any(FormData), { headers: { "Content-Type": "multipart/form-data" } });

    apiMock.post.mockResolvedValueOnce(envelope({ url: "/storage/avatar.png" }));
    await expect(uploadService.uploadAvatar(new File(["x"], "a.png"))).resolves.toBe("/storage/avatar.png");
    const form = apiMock.post.mock.calls.at(-1)?.[1] as FormData;
    expect(form.get("avatar")).toBeInstanceOf(File);
  });

  it("covers auth endpoints, optional turnstile and session logout", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { token: "t" } }, status: 200, headers: new Headers() });
    await authService.login({ email: "a@b.c", password: "pw" } as never);
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/login", { email: "a@b.c", password: "pw" });

    await authService.refresh("refresh-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/refresh", { refresh_token: "refresh-1" });

    await authService.forgotPassword("a@b.c");
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/forgot-password", { email: "a@b.c" });
    await authService.forgotPassword("a@b.c", "turnstile-1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/forgot-password", { email: "a@b.c", "cf-turnstile-response": "turnstile-1" });

    await authService.register({ email: "a@b.c" } as never);
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/register", { email: "a@b.c" });
    await authService.resetPassword({ token: "t" } as never);
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/reset-password", { token: "t" });
    await authService.verifyEmail({ token: "t" } as never);
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/verify-email", { token: "t" });
    await authService.resendVerificationEmail();
    expect(apiMock.post).toHaveBeenCalledWith("/api/auth/verify-email/resend");

    await authService.logout();
    expect(sessionMock.logout).toHaveBeenCalled();

    apiMock.get.mockResolvedValueOnce({ data: { data: null } });
    await expect(authService.me()).resolves.toBeNull();
    apiMock.get.mockResolvedValueOnce(envelope({ id: 1 }));
    await expect(authService.me()).resolves.toEqual({ id: 1 });
  });
});

describe("extensionsService contract", () => {
  it("unwraps list payloads in both shapes", async () => {
    apiMock.get.mockResolvedValueOnce({ data: { data: [{ id: "vendor/one" }] } });
    await expect(extensionsService.list()).resolves.toEqual([{ id: "vendor/one" }]);
    apiMock.get.mockResolvedValueOnce({ data: [{ id: "vendor/two" }] });
    await expect(extensionsService.views()).resolves.toEqual([{ id: "vendor/two" }]);
  });

  it("validates panel endpoints against path traversal and absolute URLs", async () => {
    await expect(extensionsService.panel("vendor/one", "")).rejects.toThrow("Panel endpoint is invalid.");
    await expect(extensionsService.panel("vendor/one", "../secret")).rejects.toThrow("Panel endpoint is invalid.");
    await expect(extensionsService.panel("vendor/one", "https://evil.test")).rejects.toThrow("Panel endpoint is invalid.");

    apiMock.get.mockResolvedValueOnce(envelope({ rows: [] }));
    await expect(extensionsService.panel("vendor/one", "//stats")).resolves.toEqual({ rows: [] });
    expect(apiMock.get).toHaveBeenCalledWith("/api/ext/vendor/one/stats");
  });

  it("uses idempotent lifecycle writes and non-idempotent health probes", async () => {
    apiMock.post.mockResolvedValue({ data: { ok: true } });
    await extensionsService.install("vendor/one");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/install", { id: "vendor/one" }, { idempotencyKey: expect.any(String) });
    await extensionsService.enable("vendor/one");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/enable", { id: "vendor/one" }, { idempotencyKey: expect.any(String) });
    await extensionsService.disable("vendor/one");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/disable", { id: "vendor/one" }, { idempotencyKey: expect.any(String) });
    await extensionsService.uninstall("vendor/one", true);
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/uninstall", { id: "vendor/one", remove_data: true }, { idempotencyKey: expect.any(String) });
    await extensionsService.health("vendor/one");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/health", { id: "vendor/one" });
    await extensionsService.upgrade("vendor/one");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/extensions/upgrade", { id: "vendor/one" }, { idempotencyKey: expect.any(String) });
  });

  it("reads affiliate referrers and ledger and submits payout transitions", async () => {
    apiMock.get.mockResolvedValue({ data: [] });
    await extensionsService.affiliateReferrers();
    expect(apiMock.get).toHaveBeenCalledWith("/api/ext/siro/affiliate/referrals");
    await extensionsService.affiliateLedger();
    expect(apiMock.get).toHaveBeenCalledWith("/api/ext/siro/affiliate/ledger");
    apiMock.post.mockResolvedValueOnce({ data: { data: { ok: true } } });
    await extensionsService.affiliatePayout("l-1", "paid");
    expect(apiMock.post).toHaveBeenCalledWith(
      "/api/ext/siro/affiliate/ledger/payout",
      { ledger_id: "l-1", to: "paid" },
      { idempotencyKey: expect.any(String) },
    );
  });
});

describe("b2bService contract", () => {
  it("creates companies, members, scopes, catalogs and negotiated prices", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { id: "c1" } } });
    await b2bService.createCompany({ name: "Acme", status: "active" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/b2b/companies", { name: "Acme", status: "active" }, { idempotencyKey: "company_Acme_{}" });

    await b2bService.addMember("c/1", { user_id: 7, role: "buyer" });
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/admin/b2b/companies/c%2F1/members");
    await b2bService.setApprovalScope("c1", { scope: "global", approval_limit_minor: 100 });
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/admin/b2b/companies/c1/approval-scopes");
    await b2bService.createCatalog("c1", { code: "std", name: "Standard" });
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/admin/b2b/companies/c1/catalogs");
    await b2bService.addCatalogItem("c1", "cat/1", { variant_id: "v1" });
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/admin/b2b/companies/c1/catalogs/cat%2F1/items");
    await b2bService.setNegotiatedPrice("c1", { variant_id: "v1", unit_price_minor: 500 });
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/admin/b2b/companies/c1/negotiated-prices");
  });

  it("reads credit, quotes and vendors", async () => {
    apiMock.get.mockResolvedValue(envelope({ ok: true }));
    await b2bService.credit("c1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/b2b/companies/c1/credit");
    await b2bService.quote("q/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/b2b/quotes/q%2F1");
    apiMock.get.mockResolvedValueOnce({ data: { data: null } });
    await expect(b2bService.vendors()).resolves.toEqual([]);
  });

  it("creates quotes and performs quote/order/contract actions idempotently", async () => {
    apiMock.post.mockResolvedValue({ data: { data: { ok: true } } });
    await b2bService.createQuote("c1", { currency: "VND", lines: [] });
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/companies/c1/quotes");
    await b2bService.quoteAction("q1", "approve");
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/quotes/q1/approve");
    await b2bService.quoteAction("q1", "reject");
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/quotes/q1/reject");
    await b2bService.convertQuote("q1");
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/quotes/q1/convert");
    await b2bService.approveOrder("o1");
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/orders/o1/approve");
    await b2bService.approveOrder("o1", true);
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/orders/o1/approve-company");
    await b2bService.approveContract("ct1");
    expect(apiMock.post.mock.calls.at(-1)?.[0]).toBe("/api/v2/b2b/contracts/ct1/approve");
  });
});

describe("fe1Service contract", () => {
  it("binds catalog, coupon, booking, export and settings routes", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    apiMock.post.mockResolvedValue({ data: { data: { ok: true } } });
    apiMock.put.mockResolvedValue({ data: { data: { ok: true } } });
    apiMock.delete.mockResolvedValue({});

    await fe1Service.productTypes();
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/catalog/product-types");
    await fe1Service.options(5);
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products/5/options");
    await fe1Service.createOption(5, { name: "Size" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/5/options", { name: "Size" }, { idempotencyKey: "option_5_size" });
    await fe1Service.addOptionValue(5, "o1", { value: "M" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/5/options/o1/values", { value: "M" }, { idempotencyKey: "option-value_5_o1_m" });
    await fe1Service.media("p/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products/p%2F1/media");
    await fe1Service.addMedia(5, { url: "/a.jpg", type: "image" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/5/media", { url: "/a.jpg", type: "image" }, { idempotencyKey: "media_5_image_/a.jpg" });
    await fe1Service.reorderMedia(5, ["m1", "m2"]);
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/products/5/media/order", { media_ids: ["m1", "m2"] }, { idempotencyKey: "media-order_5_m1,m2" });
    await fe1Service.deleteMedia(5, "m/1");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/products/5/media/m%2F1");
    await fe1Service.variants(5);
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products/5/variants");
    await fe1Service.createVariant(5, { sku: "SKU-1", price_minor: 100 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/5/variants", { sku: "SKU-1", price_minor: 100 }, { idempotencyKey: "variant_5_SKU-1" });

    await fe1Service.coupons();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/coupons");
    await fe1Service.createCoupon({ code: "ten" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/coupons", { code: "ten" }, { idempotencyKey: "coupon_TEN" });
    await fe1Service.disableCoupon("c1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/coupons/c1/disable", undefined, { idempotencyKey: "coupon-disable_c1" });
    await fe1Service.enableCoupon("c1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/coupons/c1/enable", undefined, { idempotencyKey: "coupon-enable_c1" });
    await fe1Service.couponVersions("c/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/coupons/c%2F1/versions");
    await fe1Service.couponUsage("c/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/coupons/c%2F1/usage");
    await fe1Service.couponPnl();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/coupons/pnl");
    await fe1Service.bulkCoupons({ pattern: "PROMO-*", count: 10 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/coupons/bulk", { pattern: "PROMO-*", count: 10 }, { idempotencyKey: "coupon-bulk_PROMO-*_10" });
    await fe1Service.bulkCouponStatus("b/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/coupons/bulk/b%2F1");

    await fe1Service.bookingSlots("v/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products/v%2F1/booking-slots");
    await fe1Service.createBookingSlot("v1", { starts_at: "2026-12-01T09:00:00Z", ends_at: "2026-12-01T10:00:00Z", capacity: 2 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/products/v1/booking-slots", expect.any(Object), { idempotencyKey: "booking-v1-2026-12-01T09:00:00Z" });
    await fe1Service.previewPromotion({ code: "TEN", subtotal_minor: 1000 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/promotions/preview", { code: "TEN", subtotal_minor: 1000 });

    await fe1Service.products({ state: "active" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/products", { params: { state: "active" } });
    await fe1Service.updateProductLifecycle("p/1", { status: "published", version: 2 });
    expect(apiMock.put).toHaveBeenCalledWith("/api/admin/products/p%2F1", { status: "published", version: 2 }, { idempotencyKey: "product-lifecycle_p/1_published" });
    await fe1Service.approveProduct("p/1", { approval_status: "approved", version: 2 });
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/products/p%2F1/approval", { approval_status: "approved", version: 2 }, { idempotencyKey: "product-approval_p/1_approved" });

    await fe1Service.pages({ status: "draft" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/pages", { params: { status: "draft" } });
    await fe1Service.publishPage("pg/1", {});
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/pages/pg%2F1/publish", {}, { idempotencyKey: "page-publish_pg/1_{}" });

    await fe1Service.stores();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/stores");
    await fe1Service.storeChannels("s/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/stores/s%2F1/channels");
    await fe1Service.exportStatus("e/1");
    expect(apiMock.get).toHaveBeenCalledWith("/api/v2/admin/exports/e%2F1");
    await fe1Service.events({ type: "view" });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/analytics/events", { params: { type: "view" } });
    await fe1Service.notificationPreferences();
    expect(apiMock.get).toHaveBeenCalledWith("/api/account/notification-preferences");
    await fe1Service.startExport({ dataset: "orders", format: "csv" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/exports", { dataset: "orders", format: "csv" }, { idempotencyKey: "export_orders_csv" });
    await fe1Service.retryExport("e1");
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/exports/e1/retry", undefined, { idempotencyKey: "export-retry_e1" });
    await fe1Service.importCatalog({ format: "csv", source: "/tmp/a.csv" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/v2/admin/catalog/imports", { format: "csv", source: "/tmp/a.csv" }, { idempotencyKey: "import_csv_/tmp/a.csv" });
  });
});
