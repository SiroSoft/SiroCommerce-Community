import { notFound } from "next/navigation";
import { testMode } from "@/components/purchase/helpers";
import { UiPrimitivesDemo } from "@/components/ui-primitives/demo";

/**
 * Test-mode-only demo route for the UI primitives axe E2E spec.
 * Returns 404 in production (same `testMode` gate as FixtureControls).
 */
export default function UiPrimitivesDemoPage() {
  if (!testMode) notFound();
  return <UiPrimitivesDemo />;
}
