import { registerSlotComponent } from "@/slots";
import type { SlotRegistration } from "@/slots";
import { CONTACT_WIDGET_ID, ContactWidget } from "./index";

export const CONTACT_WIDGET_SLOT_ID = CONTACT_WIDGET_ID;

export const contactWidgetRegistration: SlotRegistration = {
  id: CONTACT_WIDGET_ID,
  priority: 90,
  component: ContactWidget,
};

/**
 * Explicit opt-in registration for the footer slot. Nothing registers at
 * module import time, so importing this file has no side effect.
 */
export function registerContactWidget(): void {
  registerSlotComponent("footer", contactWidgetRegistration);
}
