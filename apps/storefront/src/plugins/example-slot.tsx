import type { SlotName, SlotProps, SlotRegistration } from "@/slots";
import { registerSlotComponent } from "@/slots";

export const EXAMPLE_SLOT_NAME: SlotName = "pdp";
export const EXAMPLE_SLOT_ID = "example.notice@1";

export interface ExampleSlotData {
  message?: string;
  tone?: "info" | "promo";
}

function readExampleData(data: SlotProps["data"]): ExampleSlotData {
  const message = data?.message;
  const tone = data?.tone;

  return {
    message: typeof message === "string" && message.trim().length > 0
      ? message
      : "Example plugin notice is active.",
    tone: tone === "promo" ? "promo" : "info",
  };
}

/**
 * Minimal, self-contained plugin component. It renders deterministic markup,
 * performs no network requests and only receives the typed `SlotProps`.
 */
export function ExampleSlotNotice({ scope, locale, data }: SlotProps) {
  const { message, tone } = readExampleData(data);

  return (
    <aside
      className="slot-example-notice"
      data-testid="example-slot-notice"
      data-tone={tone}
      data-scope={scope}
      data-locale={locale}
    >
      <strong>Example plugin</strong>
      <p>{message}</p>
    </aside>
  );
}

export const exampleSlotRegistration: SlotRegistration = {
  id: EXAMPLE_SLOT_ID,
  priority: 900,
  component: ExampleSlotNotice,
};

/**
 * Explicit opt-in registration. Nothing is registered at module import time,
 * so importing this file in production has no side effect.
 */
export function registerExampleSlot(): void {
  registerSlotComponent(EXAMPLE_SLOT_NAME, exampleSlotRegistration);
}
