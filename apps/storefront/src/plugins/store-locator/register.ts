import { registerSlotComponent } from "@/slots";
import type { SlotRegistration } from "@/slots";
import { STORE_LOCATOR_ID, StoreLocatorFooter } from "./index";

export const STORE_LOCATOR_SLOT_ID = STORE_LOCATOR_ID;

export const storeLocatorRegistration: SlotRegistration = {
  id: STORE_LOCATOR_ID,
  priority: 80,
  component: StoreLocatorFooter,
};

/**
 * Explicit opt-in registration for the footer slot. Importing this module has
 * no side effect; call it once during bootstrap when the extension is enabled
 * for the active store/channel.
 */
export function registerStoreLocator(): void {
  registerSlotComponent("footer", storeLocatorRegistration);
}
