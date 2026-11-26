export type VerticalKey = "general" | "apparel" | "home" | "food_beverage" | "digital" | "marketplace" | "b2b";
export type ImageRatio = "square" | "portrait" | "landscape";
export type QuantityMode = "standard" | "case" | "hidden";
export interface VerticalPreset { key: VerticalKey; version: string; productCard: { imageRatio: ImageRatio; showRating: boolean; showAvailability: boolean; showVendor: boolean }; productDetail: { showAttributes: boolean; showDelivery: boolean; showIngredients: boolean; showSubscriptions: boolean }; purchase: { quantityMode: QuantityMode; requireOptionsBeforePurchase: boolean }; homepage: { preferredSections: string[] } }
