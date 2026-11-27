"use client";

import { useEffect } from "react";
import { PLUGIN_SLOT_REGISTRY } from "./registry";

let registered = false;

/**
 * Registers first-party plugin slots once per browser session from
 * `PLUGIN_SLOT_REGISTRY` (one entry per backend extension). Each plugin is
 * still responsible for hiding itself when its public endpoint is disabled
 * or unavailable, so this list can stay static.
 *
 * Do NOT add per-plugin imports here: add the module plus one entry in
 * `registry.ts` instead.
 */
export function registerAllPluginSlots(): void {
  if (registered) return;
  registered = true;
  for (const entry of PLUGIN_SLOT_REGISTRY) {
    try {
      entry.register();
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
