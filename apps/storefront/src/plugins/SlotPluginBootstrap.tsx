"use client";

import { useEffect } from "react";
import { registerContactWidget } from "./contact-widget/register";
import { registerFreeShippingBannerSlot } from "./free-shipping-banner/register";
import { registerProductQa } from "./product-qa/register";
import { registerSizeGuide } from "./size-guide/register";
import { registerSocialShare } from "./social-share/register";
import { registerStoreLocator } from "./store-locator/register";

let registered = false;

/**
 * Registers first-party plugin slots once per browser session. Each plugin is
 * still responsible for hiding itself when its public endpoint is disabled or
 * unavailable, so this list can stay static.
 */
export function registerAllPluginSlots(): void {
  if (registered) return;
  registered = true;
  for (const register of [
    registerFreeShippingBannerSlot,
    registerProductQa,
    registerContactWidget,
    registerSocialShare,
    registerSizeGuide,
    registerStoreLocator,
  ]) {
    try {
      register();
    } catch {
      // A duplicate id after a hot reload must not break the storefront.
    }
  }
}

export function SlotPluginBootstrap() {
  useEffect(() => {
    registerAllPluginSlots();
  }, []);
  return null;
}
