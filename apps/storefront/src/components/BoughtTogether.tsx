"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import type { Product } from "@/types";
import { formatMinor } from "@/lib/money";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { trackStorefrontEvent } from "@/services/storefront-events";

type Affinity = { variant_id: string; orders: number; score: number };

function findProduct(products: Product[], variantId: string): Product | undefined {
  return products.find((product) => product.id === variantId || (product.variantOptions ?? []).some((option) => option.variantId === variantId));
}

async function fetchAffinities(variantId: string): Promise<Affinity[]> {
  const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
  const url = baseUrl
    ? `${baseUrl.replace(/\/$/, "")}/api/products/${encodeURIComponent(variantId)}/affinities`
    : `/api/products/${encodeURIComponent(variantId)}/affinities`;
  try {
    const response = await fetch(url, { cache: "no-store" });
    if (!response.ok) return [];
    const body = (await response.json().catch(() => null)) as { success?: boolean; data?: unknown } | null;
    const data = body?.data;
    if (!Array.isArray(data)) return [];
    return data.filter((item): item is Affinity => typeof item === "object" && item !== null && typeof (item as Affinity).variant_id === "string").slice(0, 4);
  } catch {
    return [];
  }
}

/**
 * Frequently bought together, mined nightly from real order lines (no model).
 * Cold start renders nothing — the PDP keeps its default recommendations.
 */
export function BoughtTogether({ variantIds, products }: { variantIds: string[]; products: Product[] }) {
  const locale = useLocale();
  const copy = useCopy().product;
  const [items, setItems] = useState<Product[]>([]);
  const [adding, setAdding] = useState(false);
  const key = variantIds.join("|");
  useEffect(() => {
    let active = true;
    const ids = key.split("|").filter(Boolean);
    void (async () => {
      for (const variantId of ids) {
        const affinities = await fetchAffinities(variantId);
        if (!active) return;
        const matched = affinities.flatMap((affinity) => {
          const product = findProduct(products, affinity.variant_id);
          return product && !ids.includes(product.id) ? [product] : [];
        });
        if (matched.length > 0) {
          setItems(matched);
          trackStorefrontEvent("recommendation_impression", { recommendation_id: `bought-together-${variantId}`, product_id: matched[0]!.id, position: 1, source: "co-purchase" });
          return;
        }
      }
    })();
    return () => { active = false; };
  }, [key, products]);

  if (items.length === 0) return null;
  const addAll = async () => {
    setAdding(true);
    try {
      for (const product of items) {
        const form = new FormData();
        form.set("product_id", product.id);
        form.set("quantity", "1");
        await fetch("/cart/add", { method: "POST", body: form });
      }
      window.location.assign("/cart?added=1");
    } finally {
      setAdding(false);
    }
  };
  return <section className="section bought-together" aria-labelledby="bought-together-title">
    <div className="section-heading"><div><p className="eyebrow">{copy.boughtTogether}</p><h2 id="bought-together-title">{copy.boughtTogether}</h2><p className="muted">{items[0] ? `${items.length} ${copy.boughtTogetherNote}` : ""}</p></div><button className="button" type="button" onClick={() => void addAll()} disabled={adding}>{adding ? copy.addingToBag : copy.addAll}</button></div>
    <div className="product-grid" data-list-layout="list-editorial">{items.map((product) => <article className="product-card card-minimal" key={product.id}>
      <Link className="product-link" href={`/product/${product.slug}`}>
        <div className="product-image"><img src={product.image || "/placeholder-product.svg"} alt={product.imageAlt || product.name} loading="lazy" decoding="async" width="800" height="1000" /></div>
        <div className="product-meta"><div className="product-meta-main"><h3>{product.name}</h3></div><strong>{formatMinor(product.price, product.currency, locale)}</strong></div>
      </Link>
      <div className="product-actions"><form method="post" action="/cart/add"><input type="hidden" name="product_id" value={product.id} /><input type="hidden" name="quantity" value={1} /><button className="button product-add" type="submit">{copy.addToCart}</button></form></div>
    </article>)}</div>
  </section>;
}
