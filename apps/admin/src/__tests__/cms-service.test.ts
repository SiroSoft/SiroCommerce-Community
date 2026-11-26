import { describe, expect, it, vi } from "vitest";
import { cmsService } from "@/services/cms.service";

const { api } = vi.hoisted(() => ({ api: {
  get: vi.fn().mockResolvedValue({ data: { data: { changes: [] } } }),
  post: vi.fn().mockResolvedValue({ data: { data: { id: "page-1" } } }),
} }));

vi.mock("@/services/api", () => ({ default: api }));

describe("cmsService", () => {
  it("requests a scoped revision diff", async () => {
    await cmsService.diff("page", "page-1", "rev-1", "rev-2");
    expect(api.get).toHaveBeenCalledWith("/api/admin/cms-revisions/page/page-1/diff", { params: { from: "rev-1", to: "rev-2" } });
  });

  it("sends an idempotency key for autosave", async () => {
    await cmsService.autosave("page-1", { body: "draft", expected_version: 2 }, "autosave-1");
    expect(api.post).toHaveBeenCalledWith("/api/admin/pages/page-1/autosave", { body: "draft", expected_version: 2 }, { headers: { "Idempotency-Key": "autosave-1" } });
  });
});
