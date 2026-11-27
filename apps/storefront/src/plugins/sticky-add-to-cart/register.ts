import { registerSlotComponent } from "@/slots";
import { StickyAddToCart } from "./index";

export const STICKY_ADD_TO_CART_SLOT_ID = "siro/sticky-add-to-cart";

/**
 * Explicit opt-in registration for the pdp.below-atc slot. Importing this
 * module has no side effects; call once during storefront bootstrap when
 * the extension is enabled for the active store/channel.
 */
export function registerStickyAddToCart(): void {
  registerSlotComponent("pdp.below-atc", {
    id: STICKY_ADD_TO_CART_SLOT_ID,
    priority: 100,
    component: StickyAddToCart,
  });
}
