import { registerSlotComponent } from "@/slots";
import type { SlotName, SlotRegistration } from "@/slots";
import { SizeGuideSlot } from "./index";

export const SIZE_GUIDE_SLOT_NAME: SlotName = "pdp";
export const SIZE_GUIDE_SLOT_ID = "siro/size-guide";

export const sizeGuideRegistration: SlotRegistration = {
  id: SIZE_GUIDE_SLOT_ID,
  priority: 130,
  component: SizeGuideSlot,
};

/**
 * Explicit opt-in registration for the PDP size guide slot.
 * Importing this module has no side effect; call it once during bootstrap.
 */
export function registerSizeGuide(): void {
  registerSlotComponent(SIZE_GUIDE_SLOT_NAME, sizeGuideRegistration);
}
