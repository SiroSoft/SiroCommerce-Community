import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { extensionsService } from "@/services/extensions.service";

describe("extension embedded views — contract paths", () => {
  it("lists views and fetches panels under the extension mount only", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [{ id: "siro/store-locator", label: "Store locator", href: "/extensions/siro/store-locator", panels: [] }] } });
    const views = await extensionsService.views();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/extensions/ui");
    expect(views[0]?.href).toBe("/extensions/siro/store-locator");

    apiMock.get.mockResolvedValue({ data: { data: { stats: [] } } });
    await extensionsService.panel("siro/store-locator", "admin/overview");
    expect(apiMock.get).toHaveBeenCalledWith("/api/ext/siro/store-locator/admin/overview");

    await expect(extensionsService.panel("siro/store-locator", "../admin")).rejects.toThrow(/invalid/);
    await expect(extensionsService.panel("siro/store-locator", "https://evil.test/x")).rejects.toThrow(/invalid/);
    await expect(extensionsService.panel("siro/store-locator", "")).rejects.toThrow(/invalid/);
  });
});
