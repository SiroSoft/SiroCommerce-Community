import { registerSlotComponent } from "@/slots";
import type { SlotName, SlotRegistration } from "@/slots";
import { ProductPhotoReviewsSlot } from "./index";

export const PRODUCT_PHOTO_REVIEWS_SLOT_NAME: SlotName = "pdp.below-gallery";
export const PRODUCT_PHOTO_REVIEWS_SLOT_ID = "siro/product-photo-reviews";

export const productPhotoReviewsRegistration: SlotRegistration = {
  id: PRODUCT_PHOTO_REVIEWS_SLOT_ID,
  priority: 100,
  component: ProductPhotoReviewsSlot,
};

/**
 * Explicit opt-in registration for the PDP below-gallery photo-reviews slot.
 * Importing this module has no side effect; call it once during bootstrap.
 */
export function registerProductPhotoReviews(): void {
  registerSlotComponent(PRODUCT_PHOTO_REVIEWS_SLOT_NAME, productPhotoReviewsRegistration);
}
