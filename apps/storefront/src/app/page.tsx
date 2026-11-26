import type { Metadata } from "next";
import type { ReactNode } from "react";
import { CatalogRequestError, catalog } from "@/services/catalog";
import { CatalogState } from "@/components/CatalogState";
import { HomepageRenderer } from "@/components/HomepageRenderer";
import { CampaignHero } from "@/components/merchandising/CampaignHero";
import { CollectionDirectory } from "@/components/merchandising/CollectionDirectory";
import { EditorialCollection } from "@/components/merchandising/EditorialCollection";
import { EditorialStory } from "@/components/merchandising/EditorialStory";
import { ServiceProof } from "@/components/merchandising/ServiceProof";
import { BannerGrid } from "@/components/merchandising/BannerGrid";
import { UspStrip } from "@/components/merchandising/UspStrip";
import { FaqAccordion } from "@/components/merchandising/FaqAccordion";
import { siteUrl } from "@/lib/locale";
import { requestLocale, requestScope } from "@/lib/request-locale";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
import type { HomepageSectionType } from "@/homepage/types";
import { CategoryShortcuts, FlashOfferStrip, resolveCategoryHref, TrustBand, type HomeCategoryLink } from "@/homepage/sections";
import { hasSectionContent } from "@/homepage/section-data";
import { LazyRecommendationSurface as RecommendationSurface } from "@/components/lazy/LazyRecommendationSurface";
import { resolveFeaturedRecommendations } from "@/services/recommendations";
import { jsonLd } from "@/lib/structured-data";
import { ExperimentSlot } from "@/components/ExperimentSlot";

export async function generateMetadata(): Promise<Metadata> { const config = await getConfiguredStorefrontConfig(); const locale = await requestLocale(); const copy = (await import("@/lib/i18n")).getCopy(locale); return { title: copy.home.title, description: copy.home.description, alternates: { canonical: "/" }, openGraph: { title: copy.home.title, description: copy.home.description, url: siteUrl(), locale, type: "website" }, other: { "content-language": locale, "x-commerce-currency": config.scope?.currency ?? process.env.STOREFRONT_CURRENCY ?? "USD" } }; }

export default async function Home() {
  const config = await getConfiguredStorefrontConfig();
  const locale = await requestLocale();
  const copy = (await import("@/lib/i18n")).getCopy(locale);
  const apiScope = await requestScope();
  let products;
  try { products = await catalog(apiScope).listProducts(); } catch (error) { if (error instanceof CatalogRequestError) return <CatalogState error={error} locale={locale} />; throw error; }
  if (products.length === 0) return <section className="page-intro catalog-empty" aria-labelledby="empty-catalog-title"><p className="eyebrow">{copy.emptyCatalog.eyebrow}</p><h1 id="empty-catalog-title">{copy.emptyCatalog.title}</h1><p>{copy.emptyCatalog.copy}</p><div className="state-actions"><a className="text-link" href="/search">{copy.emptyCatalog.search}</a></div></section>;
  const favorites = [...products].sort((a, b) => b.rating - a.rating || b.reviewCount - a.reviewCount || a.id.localeCompare(b.id)).slice(0, 4);
  const newEdit = products.filter((product) => product.tags.includes("new")).slice(0, 4);
  const campaignProduct = newEdit[0] ?? products[0];
  const storyProduct = products.find((product) => product.category === "home") ?? products[0];
  const categoryLinks: HomeCategoryLink[] = (() => {
    const bySlug = new Map<string, HomeCategoryLink>();
    for (const product of products) {
      const slug = product.category;
      if (!slug) continue;
      const existing = bySlug.get(slug);
      if (existing) existing.count += 1;
      else bySlug.set(slug, { slug, label: product.categoryName || slug, href: resolveCategoryHref(slug), count: 1 });
      if (bySlug.size >= 6) break;
    }
    return [...bySlug.values()];
  })();
  const homepageRenderers: Record<HomepageSectionType, (props: Record<string, unknown>) => ReactNode> = {
    campaign_hero: () => campaignProduct ? <div className="sf-media-campaign"><div className="sf-media-hero-shell"><CampaignHero product={campaignProduct} locale={locale} /></div><FlashOfferStrip products={products} locale={locale} /></div> : null,
    collection_directory: () => <CollectionDirectory products={products} locale={locale} />,
    editorial_story: () => storyProduct ? <EditorialStory product={storyProduct} locale={locale} /> : null,
    product_collection: (props) => {
      const source = props.source === "tag:new" ? "new" : "featured";
      const collection = source === "new" ? newEdit : favorites;
      return <EditorialCollection eyebrow={source === "new" ? copy.editorial.justArrived : copy.editorial.favorites} title={source === "new" ? copy.editorial.newEdit : copy.editorial.favoritesTitle} description={source === "new" ? copy.editorial.newEditCopy : copy.editorial.favoritesCopy} href="/search?sort=featured" linkLabel={source === "new" ? copy.editorial.seeEdit : copy.editorial.shopFavorites} products={collection} locale={locale} emptyTitle={copy.editorial.betweenSeasons} emptyCopy={copy.editorial.betweenRecovery} cardVariant={config.presentation.productCardVariant} listVariant={config.presentation.productListVariant} />;
    },
    service_proof: () => <ServiceProof locale={locale} />,
    trust_strip: (props) => <TrustBand locale={locale} props={props} />,
    category_links: (props) => <CategoryShortcuts categories={categoryLinks} props={props} locale={locale} />,
    banner_grid: (props) => <BannerGrid props={props} locale={locale} />,
    usp_strip: (props) => <UspStrip props={props} locale={locale} />,
    faq: (props) => <FaqAccordion props={props} locale={locale} />,
  };
  const scope = config.scope;
  return <>
    {scope && <ExperimentSlot config={config.experiments} scope={scope} experimentId="homepage_notice" variants={{ control: null, alternate: <p className="trust-strip" role="note">{copy.home.experimentNotice}</p> }} fallback={null} />}
    <HomepageRenderer sections={config.homepage.sections} render={(section) => homepageRenderers[section.type](section.props)} hasData={(section) => section.type !== "campaign_hero" ? hasSectionContent(section.type, section.props) : Boolean(campaignProduct)} />
    <RecommendationSurface products={resolveFeaturedRecommendations(products).products} catalogProducts={products} recommendationId="homepage-featured" source="catalog-fallback" eyebrow={copy.recommendation.forLater} title={copy.recommendation.fewThings} cardVariant={config.presentation.productCardVariant} />
     <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd({ "@context": "https://schema.org", "@type": "WebSite", inLanguage: locale, name: config.brand.name, url: siteUrl(), potentialAction: { "@type": "SearchAction", target: `${siteUrl("/search")}?q={search_term_string}`, "query-input": "required name=search_term_string" } }) }} />
  </>;
}
