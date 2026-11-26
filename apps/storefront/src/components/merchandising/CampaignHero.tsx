"use client";

import Link from "next/link";
import type { Product } from "@/types";
import { fill, getCopy, type Locale } from "@/lib/i18n";
import { HERO_IMAGE_SIZES, HERO_IMAGE_WIDTHS, srcSetFor } from "@/lib/product-image";
import { useThemeProductImage } from "@/theme/ThemeMediaContext";

export function CampaignHero({ product, locale }: { product: Product; locale?: Locale }) {
  const copy = getCopy(locale);
  const reviews = product.rating > 0 && product.reviewCount > 0 ? fill(copy.home.heroReviews, { rating: product.rating.toFixed(1), count: product.reviewCount }) : undefined;
  const themeImage = useThemeProductImage(product.slug, "");
  const heroImage = themeImage || product.image || "/placeholder-product.svg";
  const heroSrc = themeImage ? undefined : srcSetFor(heroImage, HERO_IMAGE_WIDTHS, HERO_IMAGE_SIZES);
  return <section className="hero hero-campaign" aria-labelledby="campaign-title">
    <div className="hero-mesh" aria-hidden="true" />
    <div className="hero-copy">
      <p className="hero-badge">{product.categoryName}</p>
      <p className="eyebrow">{copy.home.eyebrow} · {product.categoryName}</p>
      <h1 id="campaign-title">{copy.home.title}</h1>
      <p className="hero-lede">{copy.home.description}</p>
      <div className="state-actions">
        <Link className="button" href={`/category/${product.category}`}>{copy.home.shopCollection}</Link>
        <Link className="text-link" href="/search">{copy.discovery.viewAll}</Link>
      </div>
      <ul className="hero-trust">
        <li>{copy.home.heroTrustShipping}</li>
        <li>{copy.home.heroTrustTax}</li>
        <li>{copy.footer.confirmationNote}</li>
      </ul>
    </div>
    <div className="hero-media">
      <img className="hero-image" src={heroImage} srcSet={heroSrc?.srcSet} sizes={heroSrc?.sizes} alt={product.imageAlt || product.name} loading="eager" fetchPriority="high" decoding="async" width="1200" height="1500" />
      <p className="hero-feature" role="note">{copy.home.featuredNow}: <strong>{product.name}</strong>{reviews && <span> · {reviews}</span>}</p>
    </div>
  </section>;
}
