"use client";

import { useState } from "react";
import type { Product } from "@/types";
import { useCopy } from "@/lib/i18n-client";
import { trackStorefrontEvent } from "@/services/storefront-events";
import { useWishlist } from "@/components/wishlist-store";

export function WishlistButton({ product }: { product: Product }) {
  const copy = useCopy();
  const wishlist = useWishlist();
  const saved = wishlist.isSaved(product);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  async function toggle() {
    if (busy) return;
    setBusy(true);
    setMessage("");
    try {
      const result = await wishlist.toggle(product);
      trackStorefrontEvent(result.saved ? "wishlist_add" : "wishlist_remove", { product_id: product.id, category: product.category });
      setMessage(result.offline ? copy.wishlist.syncOffline : result.saved ? copy.wishlist.saved : copy.accountWishlist.removed);
    } finally {
      setBusy(false);
    }
  }
  return <><button className="wishlist-button sf-wish-toggle" type="button" aria-pressed={saved} aria-label={`${saved ? copy.wishlist.saved : copy.wishlist.save}: ${product.name}`} onClick={toggle} disabled={busy}>{saved ? copy.wishlist.saved : copy.wishlist.save}</button>{message && <span className="muted sf-wish-status" role="status">{message}</span>}</>;
}
