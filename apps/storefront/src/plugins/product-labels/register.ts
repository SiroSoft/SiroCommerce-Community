import { registerSlotComponent } from "@/slots";
import type { SlotName, SlotRegistration } from "@/slots";
import { PdpTitleLabels, ProductCardLabels } from "./index";

export const PRODUCT_LABELS_SLOT_ID = "siro/product-labels";
export const PRODUCT_LABELS_CARD_SLOT: SlotName = "product-card";
export const PRODUCT_LABELS_PDP_TITLE_SLOT: SlotName = "pdp.title";

export const productCardLabelsRegistration: SlotRegistration = {
  id: PRODUCT_LABELS_SLOT_ID,
  priority: 100,
  component: ProductCardLabels,
};

export const pdpTitleLabelsRegistration: SlotRegistration = {
  id: PRODUCT_LABELS_SLOT_ID,
  priority: 100,
  component: PdpTitleLabels,
};

/**
 * Explicit opt-in registration for both label slots. Importing this module
 * has no side effects; call once during storefront bootstrap when the
 * extension is enabled for the active store/channel.
 *
 * NOTE (registry shape): `PLUGIN_SLOT_REGISTRY` holds one entry per
 * extension id, so this module registers under a single `product-card`
 * entry and also registers the `pdp.title` slot here. Both registrations
 * carry the same extension id and priority; the registry test enforces the
 * product-card half, and `index.test.tsx` covers the pdp.title half.
 */
export function registerProductLabels(): void {
  registerSlotComponent(PRODUCT_LABELS_CARD_SLOT, productCardLabelsRegistration);
  registerSlotComponent(PRODUCT_LABELS_PDP_TITLE_SLOT, pdpTitleLabelsRegistration);
}
