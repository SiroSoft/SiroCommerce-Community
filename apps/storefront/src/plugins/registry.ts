import type { SlotName } from "@/slots";
import { registerContactWidget } from "./contact-widget/register";
import { registerEmailCapturePopup } from "./email-capture-popup/register";
import { registerFreeShippingBannerSlot } from "./free-shipping-banner/register";
import { registerProductLabels } from "./product-labels/register";
import { registerProductPhotoReviews } from "./product-photo-reviews/register";
import { registerProductQa } from "./product-qa/register";
import { registerSizeGuide } from "./size-guide/register";
import { registerSocialShare } from "./social-share/register";
import { registerShopperAssistant } from "./shopper-assistant/register";
import { registerStickyAddToCart } from "./sticky-add-to-cart/register";
import { registerStoreLocator } from "./store-locator/register";

/**
 * Manifest-driven slot registry (PLAN §2.2).
 *
 * Each entry maps one backend extension id to the local first-party module
 * that renders its slot. The `slot` here MUST match `slots.storefront` in
 * that extension's backend `manifest.json` — `plugin-registry.test.tsx`
 * enforces the mapping both ways, and `ExtensionManifest::fromArray`
 * rejects unknown slot names at install time (422).
 *
 * The backend enables/disables extensions; it never ships render code. The
 * React code stays first-party, bundled in this repo. Adding a new plugin
 * means adding its module plus ONE entry here — `SlotPluginBootstrap.tsx`
 * and core components are never touched again for registration.
 *
 * Dynamic JS loading from outside the repo is P2 and deliberately not done.
 */
export interface PluginSlotEntry {
  /** Backend extension id, e.g. `siro/free-shipping-banner`. */
  extensionId: string;
  /** Storefront slot, as declared in the backend manifest. */
  slot: SlotName;
  /** Render priority (ascending renders first); kept with the register fn. */
  priority: number;
  /** Opt-in register call from the plugin module; no side effect on import. */
  register: () => void;
}

export const PLUGIN_SLOT_REGISTRY: readonly PluginSlotEntry[] = [
  {
    extensionId: "siro/free-shipping-banner",
    slot: "cart",
    priority: 100,
    register: registerFreeShippingBannerSlot,
  },
  {
    extensionId: "siro/product-photo-reviews",
    slot: "pdp.below-gallery",
    priority: 100,
    register: registerProductPhotoReviews,
  },
  {
    extensionId: "siro/product-qa",
    slot: "pdp",
    priority: 120,
    register: registerProductQa,
  },
  {
    // Product Labels renders on TWO slots (product-card + pdp.title) but the
    // registry holds one entry per extension id: the entry below is the
    // product-card half, and registerProductLabels() also registers
    // pdp.title with the same id and priority (see register.ts).
    extensionId: "siro/product-labels",
    slot: "product-card",
    priority: 100,
    register: registerProductLabels,
  },
  {
    extensionId: "siro/contact-widget",
    slot: "footer",
    priority: 90,
    register: registerContactWidget,
  },
  {
    extensionId: "siro/email-capture-popup",
    slot: "footer",
    priority: 95,
    register: registerEmailCapturePopup,
  },
  {
    extensionId: "siro/social-share",
    slot: "pdp",
    priority: 110,
    register: registerSocialShare,
  },
  {
    extensionId: "siro/size-guide",
    slot: "pdp",
    priority: 130,
    register: registerSizeGuide,
  },
  {
    extensionId: "siro/sticky-add-to-cart",
    slot: "pdp.below-atc",
    priority: 100,
    register: registerStickyAddToCart,
  },
  {
    extensionId: "siro/store-locator",
    slot: "footer",
    priority: 80,
    register: registerStoreLocator,
  },
  {
    extensionId: "siro/shopper-assistant",
    slot: "header",
    priority: 200,
    register: registerShopperAssistant,
  },
];
