"use client";

import Link from "next/link";
import { useState, type FormEvent, type ReactElement } from "react";
import type { Product } from "@/types";
import { formatMinor } from "@/lib/money";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { CARD_IMAGE_SIZES, CARD_IMAGE_WIDTHS, srcSetFor, type ImageSources } from "@/lib/product-image";
import { WishlistButton } from "./WishlistButton";
import { Slot } from "@/slots";
import { useThemeProductImage } from "@/theme/ThemeMediaContext";
import { trackStorefrontEvent } from "@/services/storefront-events";


function placeholderStyle(placeholder?: string): { backgroundImage: string; backgroundSize: string; backgroundPosition: string } | undefined {
  if (!placeholder) return undefined;
  return { backgroundImage: `url("${placeholder.replace(/"/g, "%22")}")`, backgroundSize: "cover", backgroundPosition: "center" };
}

function pictureMarkup(resolved: ImageSources, image: ReactElement): ReactElement {
  if (resolved.sources.length === 0) return image;
  return <picture style={{ display: "contents" }}>
    {resolved.sources.map((source) => <source key={source.type} type={source.type} srcSet={source.srcSet} sizes={resolved.sizes} />)}
    {image}
  </picture>;
}

function ratingMarks(rating: number): string {
  const rounded = Math.min(5, Math.max(0, Math.round(rating)));
  return `${"★".repeat(rounded)}${"☆".repeat(5 - rounded)}`;
}

function defaultVariantId(product: Product): string | undefined {
  if (product.productType === "variable" || product.productType === "configurable") {
    const options = product.variantOptions;
    if (!options || options.length === 0) return undefined;
    const inStock = options.find((option) => option.stock === undefined || option.stock > 0);
    return (inStock ?? options[0])?.variantId;
  }
  if (product.productType === "simple" || product.productType === "downloadable" || product.productType === "virtual") return product.id;
  return undefined;
}

export function ProductCard({ product, priority = false, recommendationId, recommendationSource, recommendationPosition, variant = "editorial" }: { product: Product; priority?: boolean; recommendationId?: string; recommendationSource?: string; recommendationPosition?: number; variant?: "editorial" | "minimal" | "spotlight" }) {
  const [adding, setAdding] = useState(false);
  const locale = useLocale();
  const copy = useCopy();
  const unavailable = product.capability === "unavailable";
  const flashDeal = product.productType !== "booking" && product.flashSales && product.flashSales.length > 0 ? product.flashSales[0] : undefined;
  const memberBest = product.memberPrices && product.memberPrices.length > 0 ? [...product.memberPrices].sort((a, b) => a.price_minor - b.price_minor)[0] : undefined;
  const cardPrice = flashDeal?.sale_price_minor ?? product.price;
  const cardCompare = flashDeal ? product.price : product.compareAtPrice !== undefined && product.compareAtPrice > product.price ? product.compareAtPrice : undefined;
  const badge = product.status === "out_of_stock" ? copy.commerce.outOfStock : flashDeal ? `⚡ ${copy.product.flashBadge}` : product.status === "backorder" ? copy.product.badgeBackorder : product.tags.includes("new") ? copy.product.badgeNew : cardCompare !== undefined ? copy.product.badgeReduced : unavailable ? copy.product.badgeUnavailable : undefined;
  const stockCue = product.stock !== undefined && product.status === "active" ? product.stock === 0 ? copy.product.unavailableNow : product.stock <= 5 ? fill(copy.product.onlyLeft, { count: product.stock }) : undefined : undefined;
  const deliveryCue = product.delivery?.message ?? (product.delivery?.state === "pending" ? copy.product.deliveryPending : product.delivery?.state === "unavailable" ? copy.product.deliveryUnavailable : undefined);
  const availabilityCue = product.status === "backorder" ? copy.product.reserve : product.status === "out_of_stock" ? copy.product.unavailableNow : unavailable ? copy.product.catalogOnly : stockCue ?? copy.product.available;
  const eligible = !unavailable && product.status !== "out_of_stock" && product.productType !== "grouped";
  const quickVariant = eligible ? defaultVariantId(product) : undefined;
  const secondary = product.images?.[1];
  const secondaryImage = secondary && secondary.url !== product.image && secondary.type !== "video" && secondary.type !== "youtube" ? secondary : undefined;
  const primaryFrame = product.images?.find((frame) => frame.url === product.image) ?? product.images?.[0];
  const themeImage = useThemeProductImage(product.slug, "");
  const primarySrc = themeImage ? undefined : srcSetFor(product.image || "/placeholder-product.svg", CARD_IMAGE_WIDTHS, CARD_IMAGE_SIZES, primaryFrame?.variants);
  const secondarySrc = !themeImage && secondaryImage ? srcSetFor(secondaryImage.url, CARD_IMAGE_WIDTHS, CARD_IMAGE_SIZES, secondaryImage.variants) : undefined;
  const primaryImage = <img className="sf-media-card-image sf-media-card-image-primary" src={themeImage || product.image || "/placeholder-product.svg"} srcSet={primarySrc && primarySrc.sources.length === 0 ? primarySrc.srcSet : undefined} sizes={primarySrc?.sizes} style={themeImage ? undefined : placeholderStyle(primaryFrame?.placeholder)} alt={product.imageAlt || product.name} loading={priority ? "eager" : "lazy"} fetchPriority={priority ? "high" : "auto"} decoding="async" width="800" height="1000" />;
  const secondaryMarkup = !themeImage && secondaryImage && secondarySrc ? <img className="sf-media-card-image sf-media-card-image-alt" src={secondaryImage.url} srcSet={secondarySrc.sources.length === 0 ? secondarySrc.srcSet : undefined} sizes={secondarySrc.sizes ?? CARD_IMAGE_SIZES} style={placeholderStyle(secondaryImage.placeholder)} alt="" aria-hidden="true" loading="lazy" decoding="async" width="800" height="1000" /> : undefined;
  const track = () => trackStorefrontEvent(recommendationId ? "recommendation_click" : "product_click", { product_id: product.id, ...(recommendationId ? { recommendation_id: recommendationId, recommendation_source: recommendationSource, position: recommendationPosition } : {}) });
  async function quickAdd(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (adding || typeof window === "undefined") return;
    const form = event.currentTarget;
    setAdding(true);
    try {
      const response = await fetch(form.action, { method: "POST", body: new FormData(form), headers: { Accept: "application/json" }, redirect: "follow" });
      const finalUrl = new URL(response.url || form.action, window.location.origin);
      if (response.ok && finalUrl.searchParams.get("add") !== "failed") {
        window.dispatchEvent(new CustomEvent("cart:open", { detail: { line: { name: product.name, quantity: 1, unitPriceMinor: cardPrice, currency: product.currency, slug: product.slug } } }));
      }
    } catch {
      setAdding(false);
      return;
    }
    setAdding(false);
  }
  return <article className={variant === "minimal" ? "product-card card-minimal" : variant === "spotlight" ? "product-card sf-card-spotlight" : "product-card"} data-card-variant={variant}>
    <Link className="product-link" href={`/product/${product.slug}`} prefetch={false} onClick={track}>
      <div className="product-image sf-media-card-frame">
        {primarySrc ? pictureMarkup(primarySrc, primaryImage) : primaryImage}
        {secondaryImage && secondaryMarkup && pictureMarkup(secondarySrc!, secondaryMarkup)}
        <span className="sf-media-card-veil" aria-hidden="true" />
        {badge && <span className="product-badge">{badge}</span>}
        <Slot name="product-card" data={{ productId: product.id, productSlug: product.slug }} />
      </div>
      <div className="product-meta">
        <div className="product-meta-main">
          {variant === "spotlight" && <p className="sf-card-spotlight-label">{copy.product.spotlightLabel}</p>}
          <h3>{product.name}</h3>
          <p className="product-category">{product.categoryName}</p>
          {variant !== "minimal" && product.rating > 0 && <p className="product-rating">
            <span aria-hidden="true">{ratingMarks(product.rating)}</span>
            <span className="sr-only">{fill(copy.product.ratingOutOf, { rating: product.rating.toFixed(1) })}</span>
            <span className="rating-score" aria-hidden="true">{product.rating.toFixed(1)} · {product.reviewCount} {product.reviewCount === 1 ? copy.product.review : copy.product.reviews}</span>
          </p>}
          <p className="product-availability">{availabilityCue}{deliveryCue && ` · ${deliveryCue}`}</p>
        </div>
        <strong>{formatMinor(cardPrice, product.currency, locale)}{cardCompare !== undefined && <del className="compare-price">{formatMinor(cardCompare, product.currency, locale)}</del>}</strong>
        {!flashDeal && memberBest && <span className="member-price-cue">{fill(copy.product.memberFrom, { price: formatMinor(memberBest.price_minor, memberBest.currency, locale) })}</span>}
      </div>
    </Link>
    <div className="product-actions">
      <WishlistButton product={product} />
      {eligible && quickVariant && <form method="post" action="/cart/add" onSubmit={quickAdd}><input type="hidden" name="product_id" value={quickVariant} /><input type="hidden" name="quantity" value="1" /><button className={adding ? "button product-add sf-quick-add is-loading" : "button product-add sf-quick-add"} type="submit" aria-busy={adding} disabled={adding} aria-label={fill(copy.product.quickAddLabel, { name: product.name })}>{adding ? copy.product.addingToBag : copy.product.quickAdd}</button></form>}
      {eligible && !quickVariant && <form method="post" action="/cart/add" onSubmit={() => setAdding(true)}><input type="hidden" name="product_id" value={product.id} /><button className={adding ? "button product-add is-loading" : "button product-add"} type="submit" aria-busy={adding} disabled={adding} aria-label={copy.product.addToCartLabel}>{adding ? copy.product.addingToBag : copy.product.addToCart}</button></form>}
    </div>
  </article>;
}
