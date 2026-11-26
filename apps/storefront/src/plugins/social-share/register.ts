import { registerSlotComponent } from "@/slots";
import type { SlotName, SlotRegistration } from "@/slots";
import { SOCIAL_SHARE_ID, SocialShare } from "./index";

export const SOCIAL_SHARE_SLOT_NAME: SlotName = "pdp";
export const SOCIAL_SHARE_SLOT_ID = SOCIAL_SHARE_ID;

export const socialShareRegistration: SlotRegistration = {
  id: SOCIAL_SHARE_SLOT_ID,
  priority: 110,
  component: SocialShare,
};

/**
 * Explicit opt-in registration for the PDP share bar. Importing this module has
 * no side effect; call it once during storefront bootstrap.
 */
export function registerSocialShare(): void {
  registerSlotComponent(SOCIAL_SHARE_SLOT_NAME, socialShareRegistration);
}
