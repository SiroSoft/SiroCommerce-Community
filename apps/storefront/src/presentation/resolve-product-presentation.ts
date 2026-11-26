import type { Product } from "@/types";
import type { VerticalPreset } from "@/verticals/types";
export function resolveProductPresentation(product: Product, vertical: VerticalPreset) {
  return { imageRatio: vertical.productCard.imageRatio, showRating: vertical.productCard.showRating && product.rating > 0, showAvailability: vertical.productCard.showAvailability, showVendor: vertical.productCard.showVendor && Boolean(product.vendor), showAttributes: vertical.productDetail.showAttributes && Boolean(product.attributes?.length), showDelivery: vertical.productDetail.showDelivery && Boolean(product.delivery), showIngredients: vertical.productDetail.showIngredients, showSubscriptions: vertical.productDetail.showSubscriptions && Boolean(product.subscriptionPlans?.length), quantityMode: vertical.purchase.quantityMode, requireOptionsBeforePurchase: vertical.purchase.requireOptionsBeforePurchase && Boolean(product.options?.length) };
}
