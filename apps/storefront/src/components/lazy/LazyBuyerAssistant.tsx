"use client";

import dynamic from "next/dynamic";
import type { Product } from "@/types";
import type { BuyerAssistantState } from "@/lib/buyer-assistant";
import { useCopy } from "@/lib/i18n-client";

function Skeleton() {
  const label = useCopy().assistant.loadingState;
  return <aside className="buyer-assistant purchase-panel" aria-busy="true" aria-label={label}>
    <div className="skeleton skeleton-title" aria-hidden="true" />
    <div className="skeleton skeleton-copy" aria-hidden="true" />
    <p className="sr-only" role="status">{label}</p>
  </aside>;
}

const BuyerAssistant = dynamic(
  () => import("../BuyerAssistant").then((module) => module.BuyerAssistant),
  { ssr: false, loading: () => <Skeleton /> },
);

export function LazyBuyerAssistant(props: { products: Product[]; state?: BuyerAssistantState }) {
  return <BuyerAssistant {...props} />;
}
