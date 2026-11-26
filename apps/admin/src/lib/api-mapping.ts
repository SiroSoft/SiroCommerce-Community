import type { components } from "@/types/api";

type ApiProduct = components["schemas"]["Product"];
type WriteProduct = components["schemas"]["CreateProductRequest"] | components["schemas"]["UpdateProductRequest"];
type ApiRecord = Record<string, unknown>;

function isRecord(value: unknown): value is ApiRecord {
  return !!value && typeof value === "object" && !Array.isArray(value);
}

function isFiniteNumber(value: unknown): value is number {
  return typeof value === "number" && Number.isFinite(value);
}

/** Validate an API product envelope item; returns a typed Product or null. */
export function mapProductFromApi(product: unknown): ApiProduct | null {
  if (!isRecord(product)) return null;
  const numericFields = ["price", "compare_price", "cost_price", "stock", "stock_min"] as const;
  if (typeof product["name"] !== "string" || !product["name"].trim()) return null;
  for (const field of numericFields) {
    const value = product[field];
    if (value !== undefined && value !== null && !isFiniteNumber(value)) return null;
  }
  const price = product["price"];
  const compare = product["compare_price"];
  if (price !== undefined && typeof price === "number" && price < 0) return null;
  if (compare !== undefined && compare !== null) {
    if (typeof compare !== "number" || compare < 0) return null;
    if (typeof price === "number" && compare < price) return null;
  }
  const stock = product["stock"];
  if (stock !== undefined && stock !== null && (!Number.isInteger(stock) || (stock as number) < 0)) return null;
  const record = product as ApiRecord & { image?: unknown; cover_image?: unknown; is_active?: unknown; status?: unknown };
  return {
    ...(product as Omit<ApiProduct, "image" | "is_active">),
    image: typeof record.image === "string" ? record.image : typeof record.cover_image === "string" ? record.cover_image : "",
    is_active: typeof record.is_active === "boolean" ? record.is_active : record.status === "active",
  } as ApiProduct;
}

/** Strip response-only fields before sending a product write contract. */
export function mapProductToApi(product: WriteProduct): Record<string, unknown> {
  const record = { ...(product as ApiRecord) };
  delete record["image"];
  delete record["cover_image"];
  return record;
}
