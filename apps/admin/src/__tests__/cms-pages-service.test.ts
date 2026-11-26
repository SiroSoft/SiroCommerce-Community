import { describe, expect, it, vi } from "vitest";

const { api } = vi.hoisted(() => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  },
}));

vi.mock("@/services/api", () => ({ default: api }));

import { cmsService } from "@/services/cms.service";

describe("cmsService page builder routes", () => {
  it("lists and creates pages through the admin page endpoints", async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: "p1", slug: "about", title: "About" }] } });
    await expect(cmsService.list()).resolves.toEqual([{ id: "p1", slug: "about", title: "About" }]);
    expect(api.get).toHaveBeenCalledWith("/api/admin/pages");

    api.post.mockResolvedValue({ data: { data: { id: "p1" } } });
    await cmsService.create({ slug: "about", title: "About", locale: "en", status: "draft", body: null });
    expect(api.post).toHaveBeenCalledWith("/api/admin/pages", { slug: "about", title: "About", locale: "en", status: "draft", body: null });
  });

  it("updates pages and loads the admin preview payload", async () => {
    api.put.mockResolvedValue({ data: { data: { id: "p1" } } });
    await cmsService.update("p1", { title: "New title" });
    expect(api.put).toHaveBeenCalledWith("/api/admin/pages/p1", { title: "New title" });

    api.get.mockResolvedValue({ data: { data: { id: "p1", blocks: [] } } });
    await expect(cmsService.preview("p1")).resolves.toEqual({ id: "p1", blocks: [] });
    expect(api.get).toHaveBeenCalledWith("/api/admin/pages/p1/preview");
  });

  it("creates blocks with the API payload and reorders through block update", async () => {
    api.post.mockResolvedValue({ data: { data: { id: "b1" } } });
    await cmsService.addBlock("p1", { type: "hero", content: "{\"headline\":\"Hi\"}", sort: 10 });
    expect(api.post).toHaveBeenCalledWith("/api/admin/pages/p1/blocks", { type: "hero", content: "{\"headline\":\"Hi\"}", sort: 10 });

    api.put.mockResolvedValue({ data: { data: { id: "b1", sort: 20 } } });
    await cmsService.updateBlock("b1", { sort: 20 });
    expect(api.put).toHaveBeenCalledWith("/api/admin/blocks/b1", { sort: 20 });
  });

  it("publishes and restores with idempotency keys", async () => {
    api.post.mockResolvedValue({ data: { data: { id: "p1" } } });
    await cmsService.publish("p1", "publish-1");
    expect(api.post).toHaveBeenCalledWith("/api/admin/pages/p1/publish", undefined, { idempotencyKey: "publish-1" });

    await cmsService.restore("p1", "rev-2", "restore-1");
    expect(api.post).toHaveBeenCalledWith("/api/admin/pages/p1/restore/rev-2", undefined, { idempotencyKey: "restore-1" });
  });
});
