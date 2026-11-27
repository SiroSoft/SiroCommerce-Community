"use client";

import { useEffect, useState } from "react";
import type { SlotProps } from "@/slots";
import { Badge, type BadgeKind } from "@/components/ui-primitives";
import {
  labelsEndpoint,
  labelsScopeHeaders,
  readLabelsPayload,
  type ProductLabel,
} from "./labels";

// Backend kind `best_seller` maps to the primitive kind `best-seller`;
// the other kinds already match the shared Badge API.
const KIND_MAP: Record<ProductLabel["kind"], BadgeKind> = {
  new: "new",
  sale: "sale",
  best_seller: "best-seller",
  custom: "custom",
};

function slotProductSlug(data: SlotProps["data"]): string {
  const slug = data?.productSlug;
  return typeof slug === "string" ? slug : "";
}

function useProductLabels(productSlug: string): ProductLabel[] | null {
  const [labels, setLabels] = useState<ProductLabel[] | null>(null);

  useEffect(() => {
    if (productSlug === "") return;
    const endpoint = labelsEndpoint(productSlug);
    if (endpoint === "") return;
    const controller = new AbortController();
    void (async () => {
      try {
        const response = await fetch(endpoint, {
          cache: "no-store",
          headers: labelsScopeHeaders(),
          signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Product labels request failed: ${response.status}`);
        const payload = readLabelsPayload(await response.json());
        if (!controller.signal.aborted) setLabels(payload?.labels ?? []);
      } catch {
        if (!controller.signal.aborted) setLabels([]);
      }
    })();
    return () => controller.abort();
  }, [productSlug]);

  return labels;
}

function LabelBadge({ label }: { label: ProductLabel }) {
  // Badge takes no data-* props, so the per-rule test id lives on a thin
  // wrapper span; colour comes from the shared primitive's theme tokens.
  return (
    <span data-testid={`product-label-${label.rule_key}`} data-kind={label.kind}>
      <Badge kind={KIND_MAP[label.kind]} label={label.text} size="sm" />
    </span>
  );
}

/**
 * Card badge: absolute stack at the top-RIGHT of the card image. The core
 * `.product-badge` already occupies the top-left, so this side never
 * overlaps it. Renders nothing until the endpoint answers, so listing
 * layout never shifts.
 */
export function ProductCardLabels({ data }: SlotProps) {
  const labels = useProductLabels(slotProductSlug(data));
  if (labels === null || labels.length === 0) return null;
  return (
    <span
      data-testid="product-labels-card"
      aria-label="Product labels"
      style={{
        alignItems: "flex-end",
        display: "flex",
        flexDirection: "column",
        gap: "0.35rem",
        position: "absolute",
        right: "0.75rem",
        top: "0.75rem",
        zIndex: 1,
      }}
    >
      {labels.map((label) => (
        <LabelBadge key={label.rule_key} label={label} />
      ))}
    </span>
  );
}

/**
 * PDP badges: inline row directly under the product title (pdp.title slot).
 * Same fetch, same labels, row layout instead of the card badge stack.
 */
export function PdpTitleLabels({ data }: SlotProps) {
  const labels = useProductLabels(slotProductSlug(data));
  if (labels === null || labels.length === 0) return null;
  return (
    <span
      data-testid="product-labels-pdp"
      aria-label="Product labels"
      style={{
        display: "flex",
        flexWrap: "wrap",
        gap: "0.4rem",
        marginTop: "0.5rem",
      }}
    >
      {labels.map((label) => (
        <LabelBadge key={label.rule_key} label={label} />
      ))}
    </span>
  );
}
