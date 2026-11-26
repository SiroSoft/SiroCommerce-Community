"use client";

import { createContext, useContext, useMemo, useSyncExternalStore } from "react";
import type { ReactNode } from "react";
import {
  getSlotRegistry,
  mergeSlotRegistries,
  subscribeSlotRegistry,
} from "./registry";
import type { SlotName, SlotRegistration, SlotRegistry } from "./types";

const SlotContext = createContext<SlotRegistry>({});

export interface SlotProviderProps {
  registry?: SlotRegistry;
  children: ReactNode;
}

export function SlotProvider({ registry, children }: SlotProviderProps) {
  const runtimeRegistry = useSyncExternalStore(
    subscribeSlotRegistry,
    getSlotRegistry,
    getSlotRegistry,
  );

  const value = useMemo(
    () => (registry === undefined
      ? runtimeRegistry
      : mergeSlotRegistries(registry, runtimeRegistry)),
    [registry, runtimeRegistry],
  );

  return <SlotContext.Provider value={value}>{children}</SlotContext.Provider>;
}

export function useSlots(): SlotRegistry {
  return useContext(SlotContext);
}

export function useSlot(name: SlotName): readonly SlotRegistration[] {
  return useSlots()[name] ?? [];
}
