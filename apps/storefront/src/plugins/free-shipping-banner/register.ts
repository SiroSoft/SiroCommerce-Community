import { registerSlotComponent } from "@/slots";
import { FreeShippingBanner } from "./index";

export const FREE_SHIPPING_BANNER_SLOT_ID = "siro/free-shipping-banner";

/**
 * Explicit opt-in registration for the cart slot. Importing this module has no
 * side effects; call once during storefront bootstrap when the extension is
 * enabled for the active store/channel.
 */
export function registerFreeShippingBannerSlot(): void {
  registerSlotComponent("cart", {
    id: FREE_SHIPPING_BANNER_SLOT_ID,
    priority: 100,
    component: FreeShippingBanner,
  });
}
