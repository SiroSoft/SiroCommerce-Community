import api, { fetchWithAuth } from "./api";
import { AdminApiError } from "./api-errors";
import type { components } from "@/types/api";
import { mapProductFromApi, mapProductToApi } from "@/lib/api-mapping";

type Product = components["schemas"]["Product"];
type PaginationMeta = components["schemas"]["PaginationMeta"];
type ProductCsvImportResult = components["schemas"]["ProductCsvImportResult"];

export interface ProductsResponse {
  data: Product[];
  meta: PaginationMeta;
}

export interface ProductCsvExport {
  blob: Blob;
  filename: string;
  rows: number;
  total: number;
  truncated: boolean;
}

export interface ProductCsvImportInput {
  file?: File;
  csv?: string;
  dryRun?: boolean;
}

export const productsService = {
  async list(params?: Record<string, unknown>) {
    const res = await api.get<ProductsResponse>("/api/admin/products", { params });
    const data = Array.isArray(res.data?.data) ? res.data.data : [];
    const seen = new Set<string>();
    const mapped = data.map(mapProductFromApi).filter((product): product is Product => product !== null).filter((product) => {
      const key = product.id != null ? `id:${product.id}` : product.sku ? `sku:${product.sku}` : undefined;
      const secondary = product.sku ? `sku:${product.sku}` : undefined;
      if (!key || seen.has(key) || secondary && seen.has(secondary)) return false;
      seen.add(key);
      if (secondary) seen.add(secondary);
      return true;
    });
    return { ...res.data, data: mapped };
  },

  async get(id: number) {
    const res = await api.get<components["schemas"]["SuccessResponse_Product"]>(`/api/products/${id}`);
    const product = mapProductFromApi(res.data.data);
    if (!product) throw new Error("The catalog returned invalid product data.");
    return product;
  },

  async create(data: components["schemas"]["CreateProductRequest"], idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessResponse_Product"]>("/api/admin/products", mapProductToApi(data), { idempotencyKey });
    const product = mapProductFromApi(res.data.data);
    if (!product) throw new Error("The catalog returned invalid product data.");
    return product;
  },

  async update(id: number, data: components["schemas"]["UpdateProductRequest"], idempotencyKey: string) {
    const res = await api.put<components["schemas"]["SuccessResponse_Product"]>(`/api/products/${id}`, mapProductToApi(data), { idempotencyKey });
    return mapProductFromApi(res.data.data);
  },

  async delete(id: number, idempotencyKey: string) {
    await api.delete(`/api/admin/products/${id}`, { idempotencyKey });
  },

  async exportCsv(): Promise<ProductCsvExport> {
    const response = await fetchWithAuth("/api/admin/products/export.csv", {
      method: "GET",
      headers: { Accept: "text/csv, application/json; version=v1" },
    });
    if (!response.ok) {
      const body = await response.json().catch(() => null) as { message?: string; meta?: { error_code?: string; errors?: Record<string, string[]> } } | null;
      throw new AdminApiError({
        message: body?.message ?? `Product export failed (${response.status})`,
        status: response.status,
        errorCode: body?.meta?.error_code,
        errors: body?.meta?.errors,
        correlationId: response.headers.get("X-Correlation-Id") ?? undefined,
      });
    }
    const disposition = response.headers.get("Content-Disposition") ?? "";
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    const blob = await response.blob();
    return {
      blob,
      filename: match?.[1] ?? "products.csv",
      rows: Number(response.headers.get("X-Export-Rows") ?? 0),
      total: Number(response.headers.get("X-Export-Total") ?? 0),
      truncated: response.headers.get("X-Export-Truncated") === "1",
    };
  },

  async importCsv(input: ProductCsvImportInput, idempotencyKey: string): Promise<ProductCsvImportResult> {
    if (!input.file && !input.csv) throw new Error("Choose a CSV file or provide CSV text.");
    const form = new FormData();
    if (input.file) form.append("file", input.file);
    else form.append("csv", input.csv ?? "");
    const res = await api.post<{ data: ProductCsvImportResult }>("/api/admin/products/import", form, {
      idempotencyKey,
      params: { dry_run: input.dryRun ? 1 : undefined },
    });
    return res.data.data;
  },

  async transition(id: number, state: "draft" | "scheduled" | "published" | "archived", version: number | undefined, idempotencyKey: string) {
    const res = await api.post<components["schemas"]["SuccessResponse_Product"]>(`/api/admin/products/${id}/lifecycle`, { state, version }, { idempotencyKey });
    return mapProductFromApi(res.data.data);
  },
};
