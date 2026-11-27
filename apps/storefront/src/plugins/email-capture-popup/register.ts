import { registerSlotComponent } from "@/slots";
import { EMAIL_CAPTURE_POPUP_ID, EmailCapturePopup } from "./index";

export const EMAIL_CAPTURE_POPUP_SLOT_ID = EMAIL_CAPTURE_POPUP_ID;

/**
 * Explicit opt-in registration for the footer slot. Importing this module has
 * no side effects; call once during storefront bootstrap when the extension
 * is enabled for the active store/channel.
 */
export function registerEmailCapturePopup(): void {
  registerSlotComponent("footer", {
    id: EMAIL_CAPTURE_POPUP_ID,
    priority: 95,
    component: EmailCapturePopup,
  });
}
