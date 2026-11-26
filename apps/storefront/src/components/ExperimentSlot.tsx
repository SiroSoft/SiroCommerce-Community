"use client";

import type { ReactNode } from "react";
import type { CommerceScope } from "@sirocommerce/scope";
import { useEffect, useState } from "react";
import { analyticsConsent } from "@/services/storefront-events";
import { assignExperiments, trackExperimentExposure, type Assignment } from "@/services/experimentation";
import type { ExperimentConfig } from "@/services/storefront-config";

export function ExperimentSlot({ config, scope, experimentId, variants, fallback }: { config: ExperimentConfig[]; scope: CommerceScope; experimentId: string; variants: Record<string, ReactNode>; fallback: ReactNode }) {
  const [assignment, setAssignment] = useState<Assignment>();
  useEffect(() => { const value = assignExperiments(config, scope, analyticsConsent()).find((item) => item.experimentId === experimentId); setAssignment(value); if (value) trackExperimentExposure(value); }, [config, scope, experimentId]);
  return <>{assignment && variants[assignment.variantId] !== undefined ? variants[assignment.variantId] : fallback}</>;
}
