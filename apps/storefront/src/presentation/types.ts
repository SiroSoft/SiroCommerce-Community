import type { ReactNode } from "react";
export type ProductSlotName = "beforeMedia" | "mediaOverlay" | "afterTitle" | "metadata" | "price" | "availability" | "actions";
export type ProductDetailSlotName = "beforeGallery" | "galleryOverlay" | "beforePurchase" | "purchaseHeader" | "options" | "quantity" | "purchaseActions" | "afterPurchase" | "supporting" | "related";
export type ProductSlot = (product: unknown) => ReactNode;
export type ProductSlots = Partial<Record<ProductSlotName, ProductSlot>>;
export type ProductDetailSlots = Partial<Record<ProductDetailSlotName, ProductSlot>>;
