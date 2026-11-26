"use client";
import { createContext, useContext } from "react";
import type { ReactNode } from "react";
import type { ListLayout, ProductCardVariant, ProductDetailVariant, PurchaseVariant } from "@/theme/types";

export interface PresentationVariants {
  card: ProductCardVariant;
  detail: ProductDetailVariant;
  list: ListLayout;
  purchase: PurchaseVariant;
}

const defaultVariants: PresentationVariants = { card: "editorial", detail: "editorial", list: "list-editorial", purchase: "standard" };

const VariantContext = createContext<PresentationVariants>(defaultVariants);

export function PresentationVariantProvider({ card = "editorial", detail = "editorial", list = "list-editorial", purchase = "standard", children }: { card?: ProductCardVariant; detail?: ProductDetailVariant; list?: ListLayout; purchase?: PurchaseVariant; children: ReactNode }) {
  return <VariantContext.Provider value={{ card, detail, list, purchase }}>{children}</VariantContext.Provider>;
}

export function useCardVariant(): ProductCardVariant { return useContext(VariantContext).card; }
export function useDetailVariant(): ProductDetailVariant { return useContext(VariantContext).detail; }
export function useListVariant(): ListLayout { return useContext(VariantContext).list; }
export function usePurchaseVariant(): PurchaseVariant { return useContext(VariantContext).purchase; }
