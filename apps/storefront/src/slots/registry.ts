import {
  createSlotRegistry,
  isSlotName,
  registerSlot,
  validateRegistry,
} from "./types";
import type { SlotName, SlotRegistration, SlotRegistry } from "./types";

/**
 * Empty registry used by the core storefront.
 *
 * First-party plugins can build an extended registry ahead of time with
 * `registerSlot(defaultSlotRegistry, name, registration)` and pass it to
 * `SlotProvider`. Runtime plugins use `registerSlotComponent` instead.
 */
export const defaultSlotRegistry: SlotRegistry = createSlotRegistry();

let runtimeRegistry: SlotRegistry = defaultSlotRegistry;
const listeners = new Set<() => void>();

function notify(): void {
  for (const listener of Array.from(listeners)) listener();
}

/**
 * Returns the current runtime registry. The returned object is immutable and
 * its identity only changes after a register/unregister/reset, so it is safe
 * to use as a `useSyncExternalStore` snapshot.
 */
export function getSlotRegistry(): SlotRegistry {
  return runtimeRegistry;
}

/**
 * Subscribes to runtime registry changes. Intended for `SlotProvider`; plugin
 * code normally does not need to call this directly.
 */
export function subscribeSlotRegistry(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/**
 * Registers one component into a slot at runtime.
 *
 * - Unknown slot names, non-finite priorities and non-component values throw.
 * - A duplicate `id` within the same slot throws; the registry is left untouched.
 * - Safe to call during SSR module setup. It never touches `window`.
 */
export function registerSlotComponent(
  name: SlotName,
  registration: SlotRegistration,
): SlotRegistry {
  if (!isSlotName(name)) {
    throw new TypeError(`Unknown slot "${String(name)}".`);
  }

  const next = registerSlot(runtimeRegistry, name, registration);
  runtimeRegistry = next;
  notify();
  return next;
}

/**
 * Removes a runtime registration by id. Returns `true` when something was
 * removed and `false` when the id was not registered. Unknown slot names throw.
 */
export function unregisterSlotComponent(name: SlotName, id: string): boolean {
  if (!isSlotName(name)) {
    throw new TypeError(`Unknown slot "${String(name)}".`);
  }

  const entries = runtimeRegistry[name] ?? [];
  const remaining = entries.filter((entry) => entry.id !== id);
  if (remaining.length === entries.length) return false;

  const next: SlotRegistry = { ...runtimeRegistry };
  if (remaining.length > 0) next[name] = remaining;
  else delete next[name];

  runtimeRegistry = validateRegistry(next);
  notify();
  return true;
}

/**
 * Clears the runtime registry. Tests call this between cases; production code
 * should register plugins once during bootstrap. An optional seed allows a
 * first-party registry to be installed as the new baseline.
 */
export function resetSlotRegistry(seed: SlotRegistry = {}): SlotRegistry {
  runtimeRegistry = createSlotRegistry(seed);
  notify();
  return runtimeRegistry;
}

/**
 * Merges any number of registries into one validated registry. Used by
 * `SlotProvider` to combine an explicitly supplied (first-party) registry with
 * runtime registrations. Duplicate ids across sources throw, matching
 * `validateRegistry`.
 */
export function mergeSlotRegistries(
  ...registries: Array<SlotRegistry | undefined>
): SlotRegistry {
  const merged: Record<string, SlotRegistration[]> = {};

  for (const registry of registries) {
    if (!registry) continue;
    for (const [slot, entries] of Object.entries(registry)) {
      if (!entries || entries.length === 0) continue;
      (merged[slot] ??= []).push(...entries);
    }
  }

  return validateRegistry(merged as SlotRegistry);
}
