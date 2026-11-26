import api from "./api";

export type CmsScheduleState = "draft" | "scheduled" | "published" | "archived";
export type CmsBlockType = "text" | "image" | "hero" | "rich_text" | "product_list" | "markdown" | "internal";
export type CmsBlock = {
  id: string;
  page_id: string;
  type: CmsBlockType | string;
  content: string;
  sort?: number | null;
  status?: string;
  version?: number;
  created_at?: string;
  updated_at?: string;
};
export type CmsPage = {
  id: string;
  slug: string;
  title: string;
  body?: string | null;
  locale?: string;
  status?: CmsScheduleState | string;
  version?: number;
  published_at?: string | null;
  scheduled_at?: string | null;
  created_at?: string;
  updated_at?: string;
  blocks?: CmsBlock[];
};
export type CmsPageWrite = {
  slug: string;
  title: string;
  body?: string | null;
  locale?: string;
  status?: CmsScheduleState;
  scheduled_at?: string | null;
};
export type CmsBlockWrite = {
  type?: CmsBlockType | string;
  content?: string;
  sort?: number;
  status?: "draft" | "published";
};
export type CmsMediaReference = {
  id: string;
  url: string;
  mime: "image/jpeg" | "image/png" | "image/webp" | "image/avif" | "video/mp4" | "video/webm";
  size: number;
  width?: number;
  height?: number;
  alt?: string;
  title?: string;
};
export type CmsRevision = { id: string; entity_type: "page" | "block"; entity_id: string; version: number; action: string; snapshot_json: string; created_at?: string };
export type CmsRevisionDiff = { entity_type: string; entity_id: string; from_revision: string; to_revision: string; changes: Array<{ field: string; from: unknown; to: unknown }> };

export const cmsService = {
  async list() {
    const response = await api.get<{ data: CmsPage[] }>("/api/admin/pages");
    return Array.isArray(response.data.data) ? response.data.data : [];
  },
  async create(data: CmsPageWrite) {
    const response = await api.post<{ data: CmsPage }>("/api/admin/pages", data);
    return response.data.data;
  },
  async update(id: string, data: Partial<CmsPageWrite>) {
    const response = await api.put<{ data: CmsPage }>(`/api/admin/pages/${encodeURIComponent(id)}`, data);
    return response.data.data;
  },
  async preview(id: string) {
    const response = await api.get<{ data: CmsPage }>(`/api/admin/pages/${encodeURIComponent(id)}/preview`);
    return response.data.data;
  },
  async addBlock(pageId: string, data: CmsBlockWrite) {
    const response = await api.post<{ data: CmsBlock }>(`/api/admin/pages/${encodeURIComponent(pageId)}/blocks`, data);
    return response.data.data;
  },
  async updateBlock(id: string, data: CmsBlockWrite) {
    const response = await api.put<{ data: CmsBlock }>(`/api/admin/blocks/${encodeURIComponent(id)}`, data);
    return response.data.data;
  },
  async publish(id: string, idempotencyKey: string) {
    const response = await api.post<{ data: CmsPage }>(`/api/admin/pages/${encodeURIComponent(id)}/publish`, undefined, { idempotencyKey });
    return response.data.data;
  },
  async restore(id: string, revision: string, idempotencyKey: string) {
    const response = await api.post<{ data: CmsPage }>(`/api/admin/pages/${encodeURIComponent(id)}/restore/${encodeURIComponent(revision)}`, undefined, { idempotencyKey });
    return response.data.data;
  },
  async revisions(type: "page" | "block", id: string) {
    const response = await api.get<{ data: CmsRevision[] }>(`/api/admin/cms-revisions/${type}/${id}`);
    return response.data.data;
  },
  async diff(type: "page" | "block", id: string, from: string, to: string) {
    const response = await api.get<{ data: CmsRevisionDiff }>(`/api/admin/cms-revisions/${type}/${id}/diff`, { params: { from, to } });
    return response.data.data;
  },
  async autosave(id: string, body: { title?: string; body?: string; status?: CmsScheduleState; scheduled_at?: string; media_json?: CmsMediaReference[]; expected_version?: number }, idempotencyKey: string) {
    const response = await api.post(`/api/admin/pages/${id}/autosave`, body, { headers: { "Idempotency-Key": idempotencyKey } });
    return response.data;
  },
  async merge(id: string, revision: string, body: { title?: string; body?: string; status?: CmsScheduleState; expected_version?: number }, idempotencyKey: string) {
    const response = await api.post(`/api/admin/pages/${id}/merge/${revision}`, body, { headers: { "Idempotency-Key": idempotencyKey } });
    return response.data;
  },
};
