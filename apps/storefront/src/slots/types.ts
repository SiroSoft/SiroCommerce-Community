import type { ComponentType, ReactNode } from "react";

export const SLOT_NAMES = [
  "header",
  "footer",
  "pdp",
  "cart",
  "checkout",
  "my-account",
  "email",
  "order-status",
] as const;

export type SlotName = (typeof SLOT_NAMES)[number];

export interface SlotProps {
  scope?: string;
  locale?: string;
  data?: Record<string, unknown>;
}

export type SlotComponent = ComponentType<SlotProps>;

export interface SlotRegistration {
  id: string;
  priority: number;
  component: SlotComponent;
  order?: number;
}

export type SlotRegistry = Partial<Record<SlotName, readonly SlotRegistration[]>>;

export interface SlotErrorFallback {
  registration: SlotRegistration;
  slot: SlotName;
}

export type SlotErrorRenderer = ReactNode | ((context: SlotErrorFallback) => ReactNode);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export function isSlotName(value: unknown): value is SlotName {
  return typeof value === "string" && (SLOT_NAMES as readonly string[]).includes(value);
}

/**
 * Accepts function components, class components and React exotic components
 * (`memo`, `forwardRef`, `lazy`) which are objects carrying a `$$typeof` symbol.
 * Plain objects and primitives are rejected so runtime plugins cannot register
 * a non-renderable value.
 */
export function isSlotComponent(value: unknown): value is SlotComponent {
  if (typeof value === "function") return true;
  if (typeof value !== "object" || value === null) return false;
  return typeof (value as { $$typeof?: unknown }).$$typeof === "symbol";
}

function readRegistration(slot: SlotName, value: unknown, index: number): SlotRegistration {
  if (!isRecord(value)) {
    throw new TypeError(`Slot "${slot}" registration at index ${index} must be an object.`);
  }

  if (typeof value.id !== "string" || value.id.trim().length === 0) {
    throw new TypeError(`Slot "${slot}" registration at index ${index} requires a non-empty string id.`);
  }

  if (typeof value.priority !== "number" || !Number.isFinite(value.priority)) {
    throw new TypeError(`Slot "${slot}" registration "${value.id}" requires a finite number priority.`);
  }

  if (value.order !== undefined && (typeof value.order !== "number" || !Number.isFinite(value.order))) {
    throw new TypeError(`Slot "${slot}" registration "${value.id}" order must be a finite number when provided.`);
  }

  const component = value.component;
  if (!isSlotComponent(component)) {
    throw new TypeError(`Slot "${slot}" registration "${value.id}" requires a React component.`);
  }

  return {
    id: value.id,
    priority: value.priority,
    component,
    ...(value.order === undefined ? {} : { order: value.order }),
  };
}

function compareRegistrations(a: SlotRegistration, b: SlotRegistration): number {
  return a.priority - b.priority || (a.order ?? 0) - (b.order ?? 0) || a.id.localeCompare(b.id);
}

export function validateRegistry(registry: SlotRegistry): SlotRegistry {
  if (!isRecord(registry)) {
    throw new TypeError("Slot registry must be an object.");
  }

  const normalized: SlotRegistry = {};

  for (const [slot, entries] of Object.entries(registry)) {
    if (!isSlotName(slot)) {
      throw new TypeError(`Unknown slot "${slot}". Expected one of: ${SLOT_NAMES.join(", ")}.`);
    }

    if (!Array.isArray(entries)) {
      throw new TypeError(`Slot "${slot}" registrations must be an array.`);
    }

    const seen = new Set<string>();
    const registrations = entries.map((entry, index) => {
      const registration = readRegistration(slot, entry, index);
      if (seen.has(registration.id)) {
        throw new Error(`Duplicate slot registration id "${registration.id}" in "${slot}".`);
      }
      seen.add(registration.id);
      return registration;
    });

    registrations.sort(compareRegistrations);
    if (registrations.length > 0) normalized[slot] = registrations;
  }

  return normalized;
}

export function createSlotRegistry(registry: SlotRegistry = {}): SlotRegistry {
  return validateRegistry(registry);
}

export function registerSlot(
  registry: SlotRegistry,
  slot: SlotName,
  registration: SlotRegistration,
): SlotRegistry {
  if (!isSlotName(slot)) {
    throw new TypeError(`Unknown slot "${String(slot)}".`);
  }

  return validateRegistry({
    ...registry,
    [slot]: [...(registry[slot] ?? []), registration],
  });
}
