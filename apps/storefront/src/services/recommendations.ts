import type { Product } from "@/types";

export type RecommendationSource = "provider" | "catalog-fallback";
export type RecommendationResult = { products: Product[]; source: RecommendationSource };
export type PreferenceSignals = { categories: string[]; tags: string[] };

const preferenceCategoryLimit = 3;
const preferenceTagLimit = 6;

export function derivePreferenceSignals(historyIds: string[], catalog: Product[]): PreferenceSignals {
  const productsById = new Map(catalog.map((candidate) => [candidate.id, candidate]));
  const categories = new Set<string>();
  const tags = new Set<string>();
  for (const id of historyIds.slice(0, 8)) {
    const product = productsById.get(id);
    if (!product) continue;
    if (categories.size < preferenceCategoryLimit) categories.add(product.category);
    for (const tag of product.tags) {
      if (tags.size >= preferenceTagLimit) break;
      tags.add(tag);
    }
  }
  return { categories: [...categories], tags: [...tags] };
}

function canRecommend(product: Product): boolean {
  return product.capability === "available" && (product.status === "active" || product.status === "backorder") && (product.stock === undefined || product.stock > 0 || product.allowBackorder === true);
}

// The catalog remains the source of price, availability, and merchandising truth
// until a recommendation provider is configured behind an existing boundary.
export function resolveRecommendations(product: Product, candidates: Product[], limit = 4, signals: PreferenceSignals = { categories: [], tags: [] }): RecommendationResult {
  const products = candidates
    .filter((candidate) => candidate.id !== product.id && candidate.slug !== product.slug && canRecommend(candidate))
    .map((candidate) => ({ candidate, score: (candidate.category === product.category ? 2 : 0) + candidate.tags.filter((tag) => product.tags.includes(tag)).length + (signals.categories.includes(candidate.category) ? 1 : 0) + candidate.tags.filter((tag) => signals.tags.includes(tag)).length }))
    .sort((a, b) => b.score - a.score || b.candidate.rating - a.candidate.rating || b.candidate.reviewCount - a.candidate.reviewCount || a.candidate.id.localeCompare(b.candidate.id))
    .slice(0, Math.max(0, limit))
    .map(({ candidate }) => candidate);
  return { products, source: "catalog-fallback" };
}

export function resolveFeaturedRecommendations(candidates: Product[], limit = 4): RecommendationResult {
  return { products: candidates.filter(canRecommend).sort((a, b) => b.rating - a.rating || b.reviewCount - a.reviewCount || a.id.localeCompare(b.id)).slice(0, Math.max(0, limit)), source: "catalog-fallback" };
}
