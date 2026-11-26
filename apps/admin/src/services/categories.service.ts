import api from "./api";
import type { components } from "@/types/api";

type Category = components["schemas"]["Category"];
type PaginationMeta = components["schemas"]["PaginationMeta"];

export interface CategoriesResponse {
  data: Category[];
  meta: PaginationMeta;
}

export const categoriesService = {
  async list(params?: Record<string, unknown>) {
    const res = await api.get<CategoriesResponse>("/api/categories", { params });
    const data = Array.isArray(res.data?.data) ? res.data.data : [];
    const seen = new Set<string>();
    return { ...res.data, data: data.filter((category) => {
      if (!category || typeof category.name !== "string" || !category.name.trim()) return false;
      const key = category.id != null ? `id:${category.id}` : category.slug ? `slug:${category.slug}` : undefined;
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    }) };
  },

  async get(id: number) {
    const res = await api.get<components["schemas"]["SuccessResponse_Category"]>(`/api/categories/${id}`);
    return res.data.data;
  },

  async create(data: components["schemas"]["CreateCategoryRequest"], idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessResponse_Category"]>("/api/categories", data, { idempotencyKey });
    return res.data.data;
  },

  async update(id: number, data: components["schemas"]["UpdateCategoryRequest"], idempotencyKey: string) {
    const res = await api.put<components["schemas"]["SuccessResponse_Category"]>(`/api/categories/${id}`, data, { idempotencyKey });
    return res.data.data;
  },

  async delete(id: number, idempotencyKey: string) {
    await api.delete(`/api/categories/${id}`, { idempotencyKey });
  },
};
