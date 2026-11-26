"use client";
import { createContext, useContext } from "react";
import type { ReactNode } from "react";
import type { ProductDetailSlots, ProductSlots } from "./types";
const PresentationContext = createContext<{ card: ProductSlots; detail: ProductDetailSlots }>({ card: {}, detail: {} });
export function PresentationProvider({ card = {}, detail = {}, children }: { card?: ProductSlots; detail?: ProductDetailSlots; children: ReactNode }) { return <PresentationContext.Provider value={{ card, detail }}>{children}</PresentationContext.Provider>; }
export function useProductPresentationSlots() { return useContext(PresentationContext); }
