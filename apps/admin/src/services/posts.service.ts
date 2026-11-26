import api from "./api";
import type { components } from "@/types/api";

type Post = components["schemas"]["Post"];
type PaginationMeta = components["schemas"]["PaginationMeta"];

export interface PostsResponse {
  data: Post[];
  meta: PaginationMeta;
}

export const postsService = {
  async list(params?: Record<string, unknown>) {
    const res = await api.get<PostsResponse>("/api/posts", { params });
    return res.data;
  },

  async get(id: number) {
    const res = await api.get<components["schemas"]["SuccessResponse_Post"]>(`/api/posts/${id}`);
    return res.data.data;
  },

  async create(data: components["schemas"]["CreatePostRequest"], idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessResponse_Post"]>("/api/posts", data, { idempotencyKey });
    return res.data.data;
  },

  async update(id: number, data: components["schemas"]["UpdatePostRequest"], idempotencyKey: string) {
    const res = await api.put<components["schemas"]["SuccessResponse_Post"]>(`/api/posts/${id}`, data, { idempotencyKey });
    return res.data.data;
  },

  async delete(id: number, idempotencyKey: string) {
    await api.delete(`/api/posts/${id}`, { idempotencyKey });
  },
};
