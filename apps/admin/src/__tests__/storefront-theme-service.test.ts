import { beforeEach, describe, expect, it, vi } from "vitest";
import { storefrontThemeService, validateThemePackage } from "@/services/storefront-theme.service";

const { api } = vi.hoisted(() => ({ api: {
  get: vi.fn().mockResolvedValue({ data: { data: [] } }),
  put: vi.fn().mockResolvedValue({ data: { data: { version: 2 } } }),
  post: vi.fn().mockResolvedValue({ data: { data: { version: 2 } } }),
} }));
vi.mock("@/services/api", () => ({ default: api }));

beforeEach(() => vi.clearAllMocks());

describe("storefrontThemeService", () => {
  it("keeps the admin lifecycle behind the shared API boundary", async () => {
    await storefrontThemeService.saveDraft({ expected_version: 1 } as never, "draft-1");
    expect(api.put).toHaveBeenCalledWith("/api/admin/storefront/config", { expected_version: 1 }, { idempotencyKey: "draft-1" });
    await storefrontThemeService.preview({ expected_version: 1 } as never, "preview-1");
    expect(api.post).toHaveBeenCalledWith("/api/admin/storefront/config/preview", { expected_version: 1 }, { idempotencyKey: "preview-1" });
  });

  it("sends idempotency keys for publish, preset, and restore", async () => {
    await storefrontThemeService.publish(3, "publish-1");
    await storefrontThemeService.applyPreset(3, "apparel", "preset-1");
    await storefrontThemeService.restore("revision-1", 3, "restore-1");
    expect(api.post).toHaveBeenNthCalledWith(1, "/api/admin/storefront/config/publish", { expected_version: 3 }, { headers: { "Idempotency-Key": "publish-1" } });
    expect(api.post).toHaveBeenNthCalledWith(2, "/api/admin/storefront/config/apply-preset", { expected_version: 3, vertical_key: "apparel" }, { headers: { "Idempotency-Key": "preset-1" } });
    expect(api.post).toHaveBeenNthCalledWith(3, "/api/admin/storefront/config/restore/revision-1", { expected_version: 3 }, { headers: { "Idempotency-Key": "restore-1" } });
  });

  it("uses the revision diff contract", async () => {
    await storefrontThemeService.diff("from", "to");
    expect(api.get).toHaveBeenCalledWith("/api/admin/storefront/config/revisions/diff", { params: { from: "from", to: "to" } });
  });

  it("validates and imports native Siro theme packages", async () => {
    const theme = { schema: "siro.theme/1", id: "acme/editorial", name: "Editorial", version: "1.0.0", homepage: { sections: [{ id: "hero", type: "campaign_hero", order: 10 }] } };
    expect(validateThemePackage(theme)).toEqual({ ok: true, package: theme });
    expect(validateThemePackage({ ...theme, schema: "siro.theme/2" }).ok).toBe(false);
    expect(validateThemePackage({ ...theme, homepage: { sections: [] } }).ok).toBe(false);
    await storefrontThemeService.importThemePackage(theme, 3, "theme-1");
    expect(api.post).toHaveBeenCalledWith("/api/admin/storefront/config/import-theme", { theme, expected_version: 3 }, { headers: { "Idempotency-Key": "theme-1" } });
    await storefrontThemeService.exportThemePackage();
    expect(api.get).toHaveBeenCalledWith("/api/admin/storefront/config/export-theme");
  });
});
