import { describe, expect, it, vi } from "vitest";
const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock("@/services/api", () => ({ default: apiMock }));
import { mediaLibraryService } from "@/services/media-library.service";

describe("media library — contract paths", () => {
  it("lists, uploads and deletes through versioned admin routes", async () => {
    apiMock.get.mockResolvedValue({ data: { data: { data: [] } } });
    apiMock.post.mockResolvedValue({ data: { data: { id: "m1", url: "/x.jpg", type: "image" } } });
    await mediaLibraryService.list({ type: "video", per_page: 48 });
    expect(apiMock.get).toHaveBeenCalledWith("/api/admin/media-library", expect.objectContaining({ params: expect.objectContaining({ type: "video" }) }));
    const file = new File(["x"], "clip.mp4", { type: "video/mp4" });
    await mediaLibraryService.upload(file, { title: "Clip" });
    expect(apiMock.post).toHaveBeenCalledWith("/api/admin/media-library", expect.any(FormData), expect.objectContaining({ idempotencyKey: expect.any(String) }));
    await mediaLibraryService.remove("m1");
    expect(apiMock.delete).toHaveBeenCalledWith("/api/admin/media-library/m1");
  });
});
