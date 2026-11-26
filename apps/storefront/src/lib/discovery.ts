import type { ProductQuery } from "@/types";

export const sortValues = ["featured", "price-asc", "price-desc"] as const;
export type DiscoverySort = (typeof sortValues)[number];

export const discoveryPerPage = 4;

export function parseSort(value: unknown): DiscoverySort {
  return typeof value === "string" && sortValues.includes(value as DiscoverySort)
    ? value as DiscoverySort
    : "featured";
}

export function parseQuery(value: unknown): string {
  return typeof value === "string" ? value.trim().slice(0, 120) : "";
}

export function parseVendor(value: unknown): string {
  const vendor = typeof value === "string" ? value.trim().toLowerCase() : "";
  return /^[a-z0-9][a-z0-9-]{0,79}$/.test(vendor) ? vendor : "";
}

export function parsePage(value: unknown): number {
  const page = typeof value === "string" ? Number(value) : Number.NaN;
  return Number.isInteger(page) && page > 0 ? page : 1;
}

export function parseFacets(value: Record<string, unknown> | undefined): Record<string, string[]> {
  if (!value) return {};
  return Object.fromEntries(Object.entries(value)
    .filter(([name]) => name.startsWith("facet_"))
    .map(([name, raw]) => [name.slice(6), (Array.isArray(raw) ? raw : [raw])
      .flatMap((item) => typeof item === "string" ? item.split(",") : [])
      .map((item) => item.trim()).filter(Boolean)]));
}

export function queryForUrl(query: ProductQuery, sort: DiscoverySort): string {
  const params = new URLSearchParams();
  if (query.search) params.set("q", query.search);
  if (query.category) params.set("category", query.category);
  if (query.vendor) params.set("vendor", query.vendor);
  if (sort !== "featured") params.set("sort", sort);
  for (const [name, values] of Object.entries(query.facets ?? {})) {
    if (values.length) params.set(`facet_${name}`, values.join(","));
  }
  if (query.page && query.page > 1) params.set("page", String(query.page));
  const value = params.toString();
  return value ? `?${value}` : "";
}
