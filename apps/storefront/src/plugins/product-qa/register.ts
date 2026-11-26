import { registerSlotComponent } from "@/slots";
import type { SlotName, SlotRegistration } from "@/slots";
import { ProductQaSlot } from "./index";

export const PRODUCT_QA_SLOT_NAME: SlotName = "pdp";
export const PRODUCT_QA_SLOT_ID = "siro/product-qa";

export const productQaRegistration: SlotRegistration = {
  id: PRODUCT_QA_SLOT_ID,
  priority: 120,
  component: ProductQaSlot,
};

/**
 * Explicit opt-in registration for the PDP product Q&A slot.
 * Importing this module has no side effect; call it once during bootstrap.
 */
export function registerProductQa(): void {
  registerSlotComponent(PRODUCT_QA_SLOT_NAME, productQaRegistration);
}
