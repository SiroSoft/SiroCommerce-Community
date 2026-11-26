"use client";

import dynamic from "next/dynamic";
import { useCopy } from "@/lib/i18n-client";

function Skeleton() {
  const label = useCopy().product.boughtTogether;
  return <section className="section" aria-busy="true" aria-label={label}>
    <div className="section-heading" aria-hidden="true"><div><div className="skeleton skeleton-title" /><div className="skeleton skeleton-copy" /></div></div>
    <div className="product-grid" aria-hidden="true"><div className="skeleton" /><div className="skeleton" /><div className="skeleton" /><div className="skeleton" /></div>
    <p className="sr-only" role="status">{label}</p>
  </section>;
}

const ProductFbt = dynamic(
  () => import("../ProductFbt").then((module) => module.ProductFbt),
  { ssr: false, loading: () => <Skeleton /> },
);

export function LazyProductFbt({ variantIds }: { variantIds: string[] }) {
  return <ProductFbt variantIds={variantIds} />;
}
