import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CatalogState } from "@/components/CatalogState";
import { CatalogRequestError, catalog, products } from "@/services/catalog";
import { siteUrl } from "@/lib/locale";
import { requestLocale, requestScope } from "@/lib/request-locale";
import { fill, getCopy } from "@/lib/i18n";
import { ProductDecisionPanel } from "@/components/ProductDecisionPanel";
import { ProductGallery } from "@/components/ProductGallery";
import { LazyBuyerAssistant as BuyerAssistant } from "@/components/lazy/LazyBuyerAssistant";
import { LazyProductFbt as ProductFbt } from "@/components/lazy/LazyProductFbt";
import { LazyRecentlyViewed as RecentlyViewed } from "@/components/lazy/LazyRecentlyViewed";
import { LazyRecommendationSurface as RecommendationSurface } from "@/components/lazy/LazyRecommendationSurface";
import { ReviewSummary, WishlistButton } from "@/components/BuyerEngagement";
import { ProductViewTracker } from "@/components/ProductViewTracker";
import { DeliveryEstimator } from "@/components/DeliveryEstimator";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
import { resolveRecommendations } from "@/services/recommendations";
import { jsonLd, priceForSchema } from "@/lib/structured-data";
import { SubscriptionExperience } from "@/components/SubscriptionExperience";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { categoryHref } from "@/lib/category-href";
import { Slot } from "@/slots";

export function generateStaticParams() { return process.env.STOREFRONT_CATALOG_SOURCE === "fixture" ? products.map(({ slug }) => ({ slug })) : []; }
export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const copy = getCopy(await requestLocale()).product;
  try {
    const product = await catalog(await requestScope()).getProduct((await params).slug);
    if (!product) return { title: copy.notFoundTitle };
    const canonical = `/product/${product.slug}`;
    return { title: product.name, description: product.description, alternates: { canonical }, openGraph: { title: product.name, description: product.description, url: canonical, locale: await requestLocale(), images: [product.image] } };
  } catch { return { title: copy.unavailableTitle }; }
}

export default async function ProductDetail({ params }: { params: Promise<{ slug: string }> }) {
  const locale = await requestLocale();
  const copy = getCopy(locale).product;
  const storefrontConfig = await getConfiguredStorefrontConfig();
  const presentation = storefrontConfig.presentation;
  const focused = presentation.productDetailVariant === "focused";
  const detailVariant = focused ? "focused" : presentation.productDetailVariant;
  let product;
  try { product = await catalog(await requestScope()).getProduct((await params).slug); }
  catch (error) { if (error instanceof CatalogRequestError) return <CatalogState error={error} locale={locale} />; throw error; }
  if (!product) notFound();
  const url = siteUrl(`/product/${product.slug}`);
  const unavailable = product.capability === "unavailable";
  const availability = product.status === "backorder" ? "https://schema.org/PreOrder" : product.status === "active" ? "https://schema.org/InStock" : "https://schema.org/OutOfStock";
    let recommendationResult = resolveRecommendations(product, []);
    let recommendationCatalog: typeof products = [];
    try {
      recommendationCatalog = await catalog(await requestScope()).listProducts({ category: product.category, perPage: 100 });
      recommendationResult = resolveRecommendations(product, recommendationCatalog);
    } catch {
      // Recommendations are non-critical; the detail page keeps its catalog truth.
    }
   const delivery = product.delivery;
   const deliverySummary = delivery?.message ?? (delivery?.mode === "downloadable" ? copy.dlDownload : delivery?.mode === "virtual" ? copy.dlVirtual : delivery?.mode === "scheduled" ? `${copy.dlScheduled}${delivery.scheduled_for ? fill(copy.dlScheduledFor, { when: delivery.scheduled_for }) : copy.dlScheduledSuffix}.` : delivery?.mode === "physical" ? copy.dlPhysical : copy.dlUnknown);
    const themeImages = Array.isArray(storefrontConfig.productImages) ? storefrontConfig.productImages : [];
    const themeGalleryFrames = themeImages.map((image) => ({ url: image, alt: product.name }));
    const galleryFrames = themeGalleryFrames.length > 0 ? themeGalleryFrames : product.images && product.images.length > 0 ? product.images : product.image ? [{ url: product.image, alt: product.imageAlt || product.name }] : [];
    const fbtVariantIds = [...new Set([product.id, ...(product.variantOptions ?? []).map((option) => option.variantId)].filter(Boolean))];
    const recentProduct = { slug: product.slug, name: product.name, image: product.image, imageAlt: product.imageAlt, priceMinor: Math.round(product.price), currency: product.currency };
    return <article className="detail" data-detail-variant={detailVariant}><ProductViewTracker productId={product.id} category={product.category} />
      <Breadcrumbs current={product.name} locale={locale} category={{ label: product.categoryName, href: categoryHref(product.category) }} />
      <div className="detail-main">
      <div className="detail-gallery">
        <figure>
           <ProductGallery frames={galleryFrames} name={product.name} label={product.image ? copy.galleryImage : copy.galleryMissing} counter={copy.galleryOf} />
        </figure>
        {product.tags.length > 0 && <div className="gallery-tags" aria-label={copy.tagsLabel}>{product.tags.map((tag) => <span key={tag}>{tag}</span>)}</div>}
      </div>
       <div className="detail-purchase-column">
       <ProductDecisionPanel product={product} variant={detailVariant} />
       <WishlistButton product={product} />
       <DeliveryEstimator priceMinor={Math.round(product.price)} currency={product.currency} variantId={product.variantOptions?.[0]?.variantId ?? product.id} />
          {!focused && <BuyerAssistant products={recommendationCatalog} />}
       </div>
       </div>
       <div className="section-heading detail-heading">
         <div><p className="eyebrow">{copy.fullPicture}</p><h2>{copy.everythingTitle}</h2><p>{copy.everythingCopy}</p></div>
       </div>
       <div className="detail-supporting">
          <section aria-labelledby="product-confidence-heading" className="trust-strip support-card">
            <h2 id="product-confidence-heading">{copy.beforeYouBuy}</h2>
            <p>{copy.confidenceCopy}</p>
          </section>
           <ReviewSummary product={product} />
          {product.attributes && product.attributes.length > 0 && <section className="support-card" aria-labelledby="product-details-heading"><p className="eyebrow">{copy.specs}</p><h2 id="product-details-heading">{copy.materialAttrs}</h2><dl className="spec-list">{product.attributes.map((attribute) => <div key={`${attribute.name}:${attribute.value}`}><dt>{attribute.name}</dt><dd>{attribute.value}</dd></div>)}</dl></section>}
          {!focused && product.tags.length > 0 && <section className="support-card" aria-labelledby="catalog-signals-heading"><p className="eyebrow">{copy.catalogSignals}</p><h2 id="catalog-signals-heading">{copy.howDescribed}</h2><ul className="tag-row">{product.tags.map((tag) => <li key={tag}>{tag}</li>)}</ul></section>}
           {product.subscriptionPlans && product.subscriptionPlans.length > 0 && <SubscriptionExperience plans={product.subscriptionPlans} />}
          {delivery && <section className="catalog-capability support-card" aria-labelledby="delivery-heading"><h2 id="delivery-heading">{copy.deliveryTitle}</h2><p>{deliverySummary}</p>{delivery.state !== "available" && <p>{fill(copy.deliveryUnknown, { state: delivery.state.replace("_", " ") })}</p>}{delivery.warehouse && <p>{fill(copy.fulfillmentSource, { source: delivery.warehouse })}</p>}{delivery.reservation === "unknown" && <p>{copy.reservationNote}</p>}</section>}
          {(product.vendor || product.site) && <section className="support-card" aria-labelledby="provenance-heading"><h2 id="provenance-heading">{copy.provenance}</h2>{product.vendor && <p>{product.vendor.slug ? <Link className="text-link" href={`/vendor/${product.vendor.slug}`}>{fill(copy.soldBy, { name: product.vendor.name })}</Link> : fill(copy.soldBy, { name: product.vendor.name })}</p>}{product.site && <p>{fill(copy.storeContext, { name: product.site.name })}</p>}</section>}
        </div>
        <Slot name="pdp" locale={locale} data={{ productId: product.id, productSlug: product.slug, category: product.category }} />
        <ProductFbt variantIds={fbtVariantIds} />
       {!focused && <RecommendationSurface products={recommendationResult.products} catalogProducts={recommendationCatalog} currentProductId={product.id} recommendationId={`product-detail-${product.id}`} source={recommendationResult.source} eyebrow={copy.sameEdit} title={getCopy(locale).recommendation.alsoLike} cardVariant={presentation.productCardVariant} />}
       <RecentlyViewed product={recentProduct} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd({ "@context": "https://schema.org", "@type": "Product", "@id": `${url}#product`, inLanguage: locale, name: product.name, description: product.description, image: product.image ? [product.image] : undefined, brand: { "@type": "Brand", name: product.vendor?.name ?? "SiroCommerce" }, ...(product.rating > 0 && product.reviewCount > 0 ? { aggregateRating: { "@type": "AggregateRating", ratingValue: product.rating, reviewCount: product.reviewCount } } : {}), offers: { "@type": "Offer", url, price: priceForSchema(product.price, product.currency), priceCurrency: product.currency, availability } }) }} />
  </article>;
}
