export interface ProductLabel {
  rule_key: string;
  text: string;
  kind: "new" | "sale" | "best_seller" | "custom";
  priority: number;
}

export interface ProductLabelsPayload {
  product_id: string | null;
  product_slug: string;
  labels: ProductLabel[];
}

function isLabelKind(value: unknown): value is ProductLabel["kind"] {
  return (
    value === "new" || value === "sale" || value === "best_seller" || value === "custom"
  );
}

export function readLabelsPayload(value: unknown): ProductLabelsPayload | null {
  if (typeof value !== "object" || value === null || Array.isArray(value)) return null;
  const record = value as Record<string, unknown>;
  const data = record.data;
  if (typeof data !== "object" || data === null || Array.isArray(data)) return null;
  const payload = data as Record<string, unknown>;
  const labels = payload.labels;
  if (!Array.isArray(labels)) return null;
  const parsed: ProductLabel[] = [];
  for (const item of labels) {
    if (typeof item !== "object" || item === null || Array.isArray(item)) continue;
    const label = item as Record<string, unknown>;
    if (
      typeof label.rule_key !== "string" ||
      typeof label.text !== "string" ||
      !isLabelKind(label.kind) ||
      typeof label.priority !== "number"
    ) {
      continue;
    }
    parsed.push({
      rule_key: label.rule_key,
      text: label.text,
      kind: label.kind,
      priority: label.priority,
    });
  }
  return {
    product_id: typeof payload.product_id === "string" ? payload.product_id : null,
    product_slug: typeof payload.product_slug === "string" ? payload.product_slug : "",
    labels: parsed,
  };
}

export function labelsEndpoint(productSlug: string): string {
  const base = (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "").replace(/\/+$/, "");
  if (base === "") return "";
  return `${base}/api/ext/siro/product-labels/public/labels?product_slug=${encodeURIComponent(productSlug)}`;
}

export function labelsScopeHeaders(): HeadersInit {
  return {
    Accept: "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
  };
}
