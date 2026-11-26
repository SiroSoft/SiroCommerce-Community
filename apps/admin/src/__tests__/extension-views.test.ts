import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { extensionsService } from "@/services/extensions.service";

describe("extension embedded views — contract paths", () => {
  it("lists views and fetches panels under the extension mount only", async () => {
    apiMock.get.mockResolvedValue({ data: { data: [{ id: "siro/affiliate", label: "Affiliates", href: "/extensions/siro/affiliate", panels: [] }] } });
    const views = await extensionsService.views();
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/extensions/ui");
    expect(views[0]?.href).toBe("/extensions/siro/affiliate");

    apiMock.get.mockResolvedValue({ data: { data: { stats: [] } } });
    await extensionsService.panel("siro/affiliate", "admin/overview");
    expect(apiMock.get).toHaveBeenCalledWith("/api/ext/siro/affiliate/admin/overview");

    await expect(extensionsService.panel("siro/affiliate", "../admin")).rejects.toThrow(/invalid/);
    await expect(extensionsService.panel("siro/affiliate", "https://evil.test/x")).rejects.toThrow(/invalid/);
    await expect(extensionsService.panel("siro/affiliate", "")).rejects.toThrow(/invalid/);
  });
});
