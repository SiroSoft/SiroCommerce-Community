"use client";

import dynamic from "next/dynamic";
import type { Cart } from "@/purchase/contracts";
import type { QuoteMode } from "@/services/storefront-config";

function FlowSkeleton() {
  return <section className="purchase-flow purchase-panel sf-flow-skeleton" aria-busy="true">
    <div className="skeleton skeleton-title" aria-hidden="true" />
    <div className="skeleton skeleton-copy" aria-hidden="true" />
    <div className="skeleton skeleton-copy" aria-hidden="true" />
  </section>;
}

/**
 * Checkout and quote do not need the purchase flow in the SSR HTML, so the
 * heavyweight flow (fixtures, catalog, both adapters) is loaded client-side
 * after hydration. The skeleton keeps layout stable while it resolves.
 */
const PurchaseFlow = dynamic(
  () => import("./PurchaseFlow").then((module) => module.PurchaseFlow),
  { ssr: false, loading: () => <FlowSkeleton /> },
);

export function PurchaseFlowLazy(props: { initialStep?: string; initialCart?: Cart; flash?: "added" | "updated" | "removed" | "failed"; quoteMode?: QuoteMode }) {
  return <PurchaseFlow {...props} />;
}
