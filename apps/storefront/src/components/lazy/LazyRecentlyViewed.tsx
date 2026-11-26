"use client";

import dynamic from "next/dynamic";
import type { RecentlyViewedProduct } from "@/lib/recently-viewed";
import { useCopy } from "@/lib/i18n-client";

function Skeleton() {
  const label = useCopy().recommendation.recentBrowsing;
  return <section className="section" aria-busy="true" aria-label={label}>
    <div className="section-heading" aria-hidden="true"><div><div className="skeleton skeleton-title" /><div className="skeleton skeleton-copy" /></div></div>
    <div className="product-grid" aria-hidden="true"><div className="skeleton" /><div className="skeleton" /><div className="skeleton" /><div className="skeleton" /></div>
    <p className="sr-only" role="status">{label}</p>
  </section>;
}

const RecentlyViewed = dynamic(
  () => import("../RecentlyViewed").then((module) => module.RecentlyViewed),
  { ssr: false, loading: () => <Skeleton /> },
);

export function LazyRecentlyViewed({ product }: { product: RecentlyViewedProduct }) {
  return <RecentlyViewed product={product} />;
}
