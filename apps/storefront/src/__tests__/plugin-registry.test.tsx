import "@testing-library/jest-dom/vitest";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { beforeEach, describe, expect, it } from "vitest";
import { getSlotRegistry, resetSlotRegistry } from "@/slots";
import { PLUGIN_SLOT_REGISTRY } from "@/plugins/registry";
import { registerAllPluginSlots } from "@/plugins/SlotPluginBootstrap";

/**
 * PLAN §2.2: the storefront slot registry is data-driven from the backend
 * manifests. This test enforces the mapping three ways:
 *   backend manifest.json `slots.storefront`
 *     <-> `PLUGIN_SLOT_REGISTRY` entry (extension id + slot + priority)
 *     <-> the plugin module's register fn (actual slot + id + priority).
 * Adding a plugin = one module + one registry entry; the bootstrap and core
 * components are never edited for registration.
 */

const BACKEND_EXTENSIONS_DIR = join(
  process.cwd(),
  "..",
  "..",
  "backend",
  "app",
  "Extensions",
  "Siro",
);

interface BackendManifest {
  id: string;
  slots?: { storefront?: unknown };
}

function backendManifests(): BackendManifest[] {
  const out: BackendManifest[] = [];
  for (const dir of readdirSync(BACKEND_EXTENSIONS_DIR)) {
    const path = join(BACKEND_EXTENSIONS_DIR, dir, "manifest.json");
    try {
      out.push(JSON.parse(readFileSync(path, "utf-8")) as BackendManifest);
    } catch {
      // Not every directory ships a manifest; only manifest-backed ones count.
    }
  }
  return out;
}

describe("plugin slot registry (PLAN §2.2)", () => {
  beforeEach(() => {
    resetSlotRegistry();
  });

  it("registers each entry on the exact slot, id and priority it declares", () => {
    for (const entry of PLUGIN_SLOT_REGISTRY) {
      resetSlotRegistry();
      entry.register();
      const registrations = getSlotRegistry()[entry.slot] ?? [];
      expect(registrations).toHaveLength(1);
      expect(registrations[0]?.id).toBe(entry.extensionId);
      expect(registrations[0]?.priority).toBe(entry.priority);
    }
  });

  it("matches every entry to its backend manifest slots.storefront", () => {
    const manifests = backendManifests();
    expect(manifests.length).toBeGreaterThan(0);
    for (const entry of PLUGIN_SLOT_REGISTRY) {
      const manifest = manifests.find((item) => item.id === entry.extensionId);
      expect(
        manifest,
        `no backend manifest.json for registry entry ${entry.extensionId}`,
      ).toBeDefined();
      const storefront = manifest?.slots?.storefront;
      expect(Array.isArray(storefront)).toBe(true);
      expect(storefront).toContain(entry.slot);
    }
  });

  it("covers every backend manifest that declares a storefront slot", () => {
    const manifests = backendManifests();
    const covered = new Set(PLUGIN_SLOT_REGISTRY.map((entry) => entry.extensionId));
    for (const manifest of manifests) {
      const storefront = manifest.slots?.storefront;
      if (!Array.isArray(storefront) || storefront.length === 0) continue;
      expect(
        covered.has(manifest.id),
        `backend manifest ${manifest.id} declares storefront slots ${JSON.stringify(storefront)} but has no registry entry`,
      ).toBe(true);
    }
  });

  it("has no duplicate extension ids", () => {
    const ids = PLUGIN_SLOT_REGISTRY.map((entry) => entry.extensionId);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it("registerAllPluginSlots registers the six legacy plugins unchanged", () => {
    resetSlotRegistry();
    registerAllPluginSlots();
    const registry = getSlotRegistry();
    const bySlot = (slot: string) =>
      (registry[slot as keyof typeof registry] ?? []).map((item) => ({
        id: item.id,
        priority: item.priority,
      }));
    expect(bySlot("cart")).toEqual([{ id: "siro/free-shipping-banner", priority: 100 }]);
    expect(bySlot("pdp")).toEqual([
      { id: "siro/social-share", priority: 110 },
      { id: "siro/product-qa", priority: 120 },
      { id: "siro/size-guide", priority: 130 },
    ]);
    expect(bySlot("footer")).toEqual([
      { id: "siro/store-locator", priority: 80 },
      { id: "siro/contact-widget", priority: 90 },
      { id: "siro/email-capture-popup", priority: 95 },
    ]);
  });
});
