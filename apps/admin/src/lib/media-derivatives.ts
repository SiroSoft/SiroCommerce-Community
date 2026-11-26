export type ImageDerivative = { w: number; url: string; format: string };

type VariantRecord = Record<string, unknown> | unknown;

function parseVariants(raw: unknown): unknown {
  return typeof raw === "string" ? (() => { try { return JSON.parse(raw) as unknown; } catch { return undefined; } })() : raw;
}

export function derivativesOf(source: Record<string, unknown> | VariantRecord): ImageDerivative[] {
  if (!source || typeof source !== "object") return [];
  const record = source as Record<string, unknown>;
  const parsed = parseVariants(record.variants ?? record.variants_json);
  if (!Array.isArray(parsed)) return [];
  return parsed.flatMap((entry) => {
    if (!entry || typeof entry !== "object") return [];
    const value = entry as { w?: unknown; url?: unknown; format?: unknown };
    const width = Number(value.w);
    if (!Number.isFinite(width) || width <= 0 || typeof value.url !== "string" || !value.url.trim()) return [];
    const format = typeof value.format === "string" && value.format.trim() ? value.format.trim().toLowerCase() : "webp";
    return [{ w: Math.round(width), url: value.url.trim(), format }];
  }).sort((a, b) => a.w - b.w || a.format.localeCompare(b.format));
}

export function derivativeSummary(derivatives: ImageDerivative[]): string {
  const groups = new Map<string, number[]>();
  for (const derivative of derivatives) {
    const label = derivative.format === "avif" ? "AVIF" : derivative.format === "webp" ? "WebP" : derivative.format.toUpperCase();
    const widths = groups.get(label) ?? [];
    if (!widths.includes(derivative.w)) widths.push(derivative.w);
    groups.set(label, widths);
  }
  const preferred = ["WebP", "AVIF"];
  const labels = [...groups.keys()].sort((a, b) => {
    const ai = preferred.indexOf(a);
    const bi = preferred.indexOf(b);
    if (ai !== -1 || bi !== -1) return (ai === -1 ? preferred.length : ai) - (bi === -1 ? preferred.length : bi);
    return a.localeCompare(b);
  });
  return labels.map((label) => `${label} ${groups.get(label)!.slice().sort((a, b) => a - b).join("/")}`).join(" · ");
}

export function previewUrl(source: Record<string, unknown>, fallback: unknown): string {
  const derivatives = derivativesOf(source);
  return derivatives.find((variant) => variant.w === 320 && variant.format === "webp")?.url
    ?? derivatives.find((variant) => variant.w === 320)?.url
    ?? derivatives.find((variant) => variant.format === "webp")?.url
    ?? derivatives[0]?.url
    ?? String(fallback ?? "");
}
