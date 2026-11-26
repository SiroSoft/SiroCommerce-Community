"use client";

import { useEffect, useState } from "react";
import type { Product } from "@/types";
import type { ProductCardVariant } from "@/theme/types";
import { readRecentlyViewed, productsForRecentlyViewed } from "@/services/recently-viewed";
import { trackStorefrontEvent } from "@/services/storefront-events";
import { useCopy } from "@/lib/i18n-client";
import { useListVariant } from "@/presentation/variant-context";
import { ProductGrid } from "./ProductGrid";

export function RecommendationSurface({ products, catalogProducts = products, currentProductId, recommendationId, source, eyebrow, title, cardVariant = "editorial" }: { products: Product[]; catalogProducts?: Product[]; currentProductId?: string; recommendationId: string; source: "provider" | "catalog-fallback"; eyebrow?: string; title?: string; cardVariant?: ProductCardVariant }) {
  const fallback = useCopy().recommendation;
  const listVariant = useListVariant();
  const headingEyebrow = eyebrow ?? fallback.nextStep;
  const headingTitle = title ?? fallback.alsoLike;
  const [recentIds, setRecentIds] = useState<string[] | null>(null);
  useEffect(() => { setRecentIds(readRecentlyViewed()); }, []);
  const recentlyViewed = productsForRecentlyViewed(recentIds ?? [], catalogProducts, currentProductId);
  const displayProducts = recentlyViewed.length > 0 ? recentlyViewed.slice(0, 4) : products;
  const displaySource = recentlyViewed.length > 0 ? "recently-viewed" : source;
  const displayProductIds = displayProducts.map((product) => product.id).join("|");
  useEffect(() => { if (recentIds !== null) displayProducts.forEach((product, index) => trackStorefrontEvent("recommendation_impression", { recommendation_id: recommendationId, product_id: product.id, position: index + 1, source: displaySource })); }, [displayProductIds, displaySource, recentIds, recommendationId]);
  if (recentIds === null) return <section className="section recommendation-surface" aria-busy="true" aria-live="polite" aria-label={fallback.loading}><div className="section-heading"><div><p className="eyebrow">{headingEyebrow}</p><h2>{headingTitle}</h2></div></div><div className="product-grid" aria-hidden="true"><div className="skeleton" /><div className="skeleton" /></div><p className="sr-only">{fallback.loading}</p></section>;
  if (!displayProducts.length) return <section className="section recommendation-surface" aria-labelledby={`${recommendationId}-title`}><div className="section-heading"><div><p className="eyebrow">{headingEyebrow}</p><h2 id={`${recommendationId}-title`}>{headingTitle}</h2></div></div><p className="account-unavailable">{fallback.unavailable}</p></section>;
  return <section className="section recommendation-surface" aria-labelledby={`${recommendationId}-title`}><div className="section-heading"><div><p className="eyebrow">{recentlyViewed.length > 0 ? fallback.recentBrowsing : headingEyebrow}</p><h2 id={`${recommendationId}-title`}>{recentlyViewed.length > 0 ? fallback.continueExploring : headingTitle}</h2></div></div><ProductGrid products={displayProducts} recommendationId={recommendationId} recommendationSource={displaySource} cardVariant={cardVariant} listVariant={listVariant} /></section>;
}
