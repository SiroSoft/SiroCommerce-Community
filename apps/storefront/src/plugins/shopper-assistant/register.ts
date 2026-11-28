import { registerSlotComponent } from "@/slots";
import { ShopperAssistant } from "./index";

export const SHOPPER_ASSISTANT_SLOT_ID = "siro/shopper-assistant";

/** Explicit opt-in registration for the floating shopper assistant. */
export function registerShopperAssistant(): void {
  registerSlotComponent("header", {
    id: SHOPPER_ASSISTANT_SLOT_ID,
    priority: 200,
    component: ShopperAssistant,
  });
}
