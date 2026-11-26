"use client";

import { useEffect } from "react";
import { trackStorefrontEvent } from "@/services/storefront-events";
import { rememberRecentlyViewed } from "@/services/recently-viewed";

export function ProductViewTracker({ productId, category }: { productId: string; category: string }): null {
  useEffect(() => {
    trackStorefrontEvent("product_view", { product_id: productId, category });
    rememberRecentlyViewed(productId);
  }, [productId, category]);
  return null;
}
