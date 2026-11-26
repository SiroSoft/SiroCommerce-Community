"use client";

import dynamic from "next/dynamic";
import type { Product } from "@/types";
import type { ProductCardVariant } from "@/theme/types";
import { useCopy } from "@/lib/i18n-client";

function Skeleton() {
  const label = useCopy().recommendation.loading;
  return <section className="section" aria-busy="true" aria-label={label}>
    <div className="section-heading" aria-hidden="true"><div><div className="skeleton skeleton-title" /><div className="skeleton skeleton-copy" /></div></div>
    <div className="product-grid" aria-hidden="true"><div className="skeleton" /><div className="skeleton" /><div className="skeleton" /><div className="skeleton" /></div>
    <p className="sr-only" role="status">{label}</p>
  </section>;
}

const RecommendationSurface = dynamic(
  () => import("../RecommendationSurface").then((module) => module.RecommendationSurface),
  { ssr: false, loading: () => <Skeleton /> },
);

export function LazyRecommendationSurface(props: {
  products: Product[];
  catalogProducts?: Product[];
  currentProductId?: string;
  recommendationId: string;
  source: "provider" | "catalog-fallback";
  eyebrow?: string;
  title?: string;
  cardVariant?: ProductCardVariant;
}) {
  return <RecommendationSurface {...props} />;
}
