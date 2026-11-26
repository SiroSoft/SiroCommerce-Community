import { describe, expect, it } from "vitest";
import { assignExperiments, trackExperimentExposure } from "@/services/experimentation";

const scope = { store_id: "store-a", channel_id: "web", locale: "en", currency: "USD" } as const;
const config = [{ id: "hero", variants: [{ id: "control", weight: 5000 }, { id: "alternate", weight: 5000 }] }];

describe("experimentation boundary", () => {
  it("is stable and isolated by scope", () => {
    window.sessionStorage.setItem("sirocommerce:experiment-subject", "fixed-subject");
    const first = assignExperiments(config, scope, true);
    expect(assignExperiments(config, scope, true)).toEqual(first);
    const isolated = Array.from({ length: 100 }, (_, index) => { window.sessionStorage.setItem("sirocommerce:experiment-subject", `subject-${index}`); return assignExperiments(config, scope, true)[0]?.variantId !== assignExperiments(config, { ...scope, store_id: "store-b" }, true)[0]?.variantId; });
    expect(isolated).toContain(true);
  });
  it("uses control without consent and ignores invalid variants", () => {
    expect(assignExperiments(config, scope, false)[0]?.variantId).toBe("control");
    expect(assignExperiments([{ id: "bad!", variants: [{ id: "control", weight: 1 }] }], scope, true)).toEqual([]);
  });
  it("deduplicates exposure events per session", () => {
    window.sessionStorage.removeItem("sirocommerce:experiment-exposure:hero:control");
    const assignment = { experimentId: "hero", variantId: "control", eligible: true };
    trackExperimentExposure(assignment); trackExperimentExposure(assignment);
    expect(window.sessionStorage.getItem("sirocommerce:experiment-exposure:hero:control")).toBe("1");
  });
});
