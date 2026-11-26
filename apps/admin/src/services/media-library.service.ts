import api from "./api";
import { idempotencyKey } from "./merchant.service";

export type MediaAsset = Record<string, unknown> & { id: string; url: string; type: string };

const envelope = <T>(v: { data?: T }) => v.data;

export const mediaLibraryService = {
  async list(params?: { q?: string; type?: string; page?: number; per_page?: number }) {
    const res = await api.get<{ data?: { data?: MediaAsset[] } }>("/api/admin/media-library", params ? { params } : undefined);
    const body = envelope(res.data);
    return { items: body?.data ?? [], total: 0 };
  },
  async upload(file: File, meta?: { title?: string; alt?: string }) {
    const form = new FormData();
    form.append("file", file);
    if (meta?.title) form.append("title", meta.title);
    if (meta?.alt) form.append("alt", meta.alt);
    const res = await api.post<{ data?: MediaAsset }>("/api/admin/media-library", form, { idempotencyKey: idempotencyKey() });
    const asset = envelope(res.data);
    if (!asset) throw new Error("Upload failed");
    return asset;
  },
  async remove(id: string) {
    await api.delete(`/api/admin/media-library/${encodeURIComponent(id)}`);
  },
};
