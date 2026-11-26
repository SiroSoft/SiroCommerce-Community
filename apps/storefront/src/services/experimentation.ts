import type { ExperimentConfig } from "@/services/storefront-config";
import type { CommerceScope } from "@sirocommerce/scope";
import { trackStorefrontEvent } from "@/services/storefront-events";

export type Assignment = { experimentId: string; variantId: string; eligible: boolean };
const key = "sirocommerce:experiment-subject";

function hash(value: string): number { let h = 2166136261; for (let i = 0; i < value.length; i += 1) { h ^= value.charCodeAt(i); h = Math.imul(h, 16777619); } return (h >>> 0) % 10000; }
function subject(consent: boolean): string | undefined {
  if (!consent || typeof window === "undefined") return undefined;
  const existing = window.sessionStorage.getItem(key); if (existing) return existing;
  const value = crypto.randomUUID(); window.sessionStorage.setItem(key, value); return value;
}
export function assignExperiments(config: ExperimentConfig[], scope: CommerceScope, consent: boolean): Assignment[] {
  const stableSubject = subject(consent);
  return config.slice(0, 50).flatMap((experiment) => {
    if (experiment.enabled === false) return [];
    const variants = experiment.variants.filter((variant) => /^[a-z0-9][a-z0-9_-]{0,31}$/.test(variant.id) && Number.isInteger(variant.weight) && variant.weight > 0).slice(0, 8);
    if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(experiment.id) || !variants.length || variants.reduce((sum, item) => sum + item.weight, 0) > 10000) return [];
    const bucket = stableSubject ? hash(`${experiment.id}|${scope.store_id}|${scope.channel_id}|${scope.locale}|${scope.currency}|${stableSubject}`) : 0;
    let cursor = 0; let selected = variants[0]?.id ?? "control";
    for (const variant of variants) { cursor += variant.weight; if (bucket < cursor) { selected = variant.id; break; } }
    return [{ experimentId: experiment.id, variantId: selected, eligible: Boolean(stableSubject) }];
  });
}
export function trackExperimentExposure(assignment: Assignment): void {
  if (!assignment.eligible || typeof window === "undefined") return;
  const exposureKey = `sirocommerce:experiment-exposure:${assignment.experimentId}:${assignment.variantId}`;
  if (window.sessionStorage.getItem(exposureKey)) return;
  window.sessionStorage.setItem(exposureKey, "1");
  trackStorefrontEvent("experiment_exposure", { experiment_id: assignment.experimentId, variant_id: assignment.variantId });
}
