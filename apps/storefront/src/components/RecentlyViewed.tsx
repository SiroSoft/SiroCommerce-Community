"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { formatMinor } from "@/lib/money";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { rememberRecentlyViewedProduct, type RecentlyViewedProduct } from "@/lib/recently-viewed";

export function RecentlyViewed({ product }: { product: RecentlyViewedProduct }) {
  const locale = useLocale();
  const copy = useCopy().recommendation;
  const heading = ({ vi: "Đã xem gần đây", en: "Recently viewed" } as Record<string, string>)[locale] ?? copy.recentBrowsing;
  const [items, setItems] = useState<RecentlyViewedProduct[] | null>(null);

  useEffect(() => {
    const stored = rememberRecentlyViewedProduct(product);
    setItems(stored.filter((item) => item.slug !== product.slug));
  }, [product.slug, product.name, product.image, product.imageAlt, product.priceMinor, product.currency]);

  if (items === null || items.length === 0) return null;

  return <section className="sf-recent" aria-labelledby="sf-recent-title">
    <div className="section-heading sf-recent-heading">
      <div>
        <p className="eyebrow">{heading}</p>
        <h2 id="sf-recent-title">{heading}</h2>
      </div>
    </div>
    <ul className="sf-recent-list">
      {items.map((item) => <li className="sf-recent-card" key={item.slug}>
        <Link className="sf-recent-link" href={`/product/${encodeURIComponent(item.slug)}`}>
          <img className="sf-recent-image" src={item.image || "/placeholder-product.svg"} alt={item.imageAlt || item.name} loading="lazy" decoding="async" width="160" height="200" />
          <span className="sf-recent-name">{item.name}</span>
          <span className="sf-recent-price">{formatMinor(item.priceMinor, item.currency, locale)}</span>
        </Link>
      </li>)}
    </ul>
  </section>;
}
