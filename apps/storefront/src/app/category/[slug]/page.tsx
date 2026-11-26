import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";
import { CatalogControls } from "@/components/CatalogControls";
import { CatalogRequestError, catalog } from "@/services/catalog";
import { CatalogState } from "@/components/CatalogState";
import { EditorialCollection } from "@/components/merchandising/EditorialCollection";
import { discoveryPerPage, parseFacets, parsePage, parseSort, parseVendor } from "@/lib/discovery";
import { siteUrl } from "@/lib/locale";
import { requestLocale, requestScope } from "@/lib/request-locale";
import { getCopy } from "@/lib/i18n";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import type { Product } from "@/types";
import type { DiscoverySort } from "@/lib/discovery";
import { jsonLd } from "@/lib/structured-data";

const categorySlugs = ["apparel", "home-living", "personal-care", "food-beverage"] as const;
const categoryNameKeys = { apparel: "apparel", "home-living": "homeLiving", "personal-care": "personalCare", "food-beverage": "foodBeverage" } as const;
/** Legacy Vietnamese slugs (and old English aliases) 301 to canonical English. */
const legacyCategorySlugs: Record<string, string> = {
  "thoi-trang": "apparel",
  "nha-cua-doi-song": "home-living",
  "cham-soc-ca-nhan": "personal-care",
  "do-uong-thuc-pham": "food-beverage",
  home: "home-living",
  accessories: "personal-care",
};
export function generateStaticParams() { return categorySlugs.map((slug) => ({ slug })); }
export const dynamic = "force-dynamic";
function sortProducts(products: Product[], sort: DiscoverySort): Product[] {
  if (sort === "featured") return products;
  return [...products].sort((a, b) => (sort === "price-asc" ? a.price - b.price : b.price - a.price) || a.id.localeCompare(b.id));
}
export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params; const locale = await requestLocale(); const copy = getCopy(locale);
  const key = categoryNameKeys[slug as keyof typeof categoryNameKeys]; const name = key ? copy.category.names[key] : undefined; const canonical = `/category/${slug}`;
  return { title: name ?? copy.category.fallbackTitle, description: name ? copy.category.metaDesc.replace("{name}", name.toLowerCase()) : copy.category.fallbackDesc, alternates: { canonical }, openGraph: { title: name ?? copy.category.fallbackTitle, url: canonical, locale, type: "website" } };
}

export default async function Category({ params, searchParams }: { params: Promise<{ slug: string }>; searchParams?: Promise<Record<string, string | string[] | undefined>> }) {
  const { slug } = await params;
  const canonical = legacyCategorySlugs[slug] ?? slug;
  if (canonical !== slug) redirect(`/category/${canonical}`);
  const locale = await requestLocale(); const copy = getCopy(locale);
  const key = categoryNameKeys[slug as keyof typeof categoryNameKeys]; const name = key ? copy.category.names[key] : undefined; if (!name) notFound();
  const paramsValue = await searchParams; const sort = parseSort(paramsValue?.sort); const page = parsePage(paramsValue?.page); const facetsQuery = parseFacets(paramsValue); const vendor = parseVendor(paramsValue?.vendor); const source = catalog(await requestScope()); let result;
  try {
    const combined = await Promise.all([slug].map((category) => source.listProductsResult ? source.listProductsResult({ category, vendor: vendor || undefined, sort, page, perPage: discoveryPerPage, facets: facetsQuery }) : source.listProducts({ category, vendor: vendor || undefined, sort, page, perPage: discoveryPerPage, facets: facetsQuery }).then((products) => ({ products, facets: [] as never[], total: products.length }))));
    const merged = combined.flatMap((entry) => entry.products);
    const seen = new Set<string>();
    const deduped = merged.filter((product) => (seen.has(product.id) || seen.has(product.slug) ? false : (seen.add(product.id), seen.add(product.slug), true)));
    result = { products: deduped, facets: combined[0]?.facets ?? [], total: combined.reduce((sum, entry) => sum + (typeof entry.total === "number" ? entry.total : entry.products.length), 0) };
  } catch (error) { if (error instanceof CatalogRequestError) return <CatalogState error={error} locale={locale} />; throw error; }
  const { facets, total } = result; const products = sortProducts(result.products, sort);
  const lead = products[0];
  return <><Breadcrumbs current={name} locale={locale} /><section className="page-intro"><p className="eyebrow">{copy.category.prefix} / {name}</p><h1>{name}</h1><p>{copy.category.intro}</p>{lead && <p className="form-notice">{copy.category.featuredIn} <strong>{lead.name}</strong>{lead.tags.length > 0 && ` · ${lead.tags.slice(0, 2).join(" · ")}`}</p>}</section><CatalogControls initialProducts={products} facets={facets} total={total} query={{ category: slug, vendor: vendor || undefined, sort, page, perPage: discoveryPerPage, facets: facetsQuery }} /><EditorialCollection eyebrow={copy.editorial.closerEyebrow} title={copy.editorial.closerTitle.replace("{name}", (name as string).toLowerCase())} description={copy.editorial.closerCopy} href="/search" linkLabel={copy.editorial.searchAll} products={products.slice(0, 2)} locale={locale} emptyTitle={copy.editorial.betweenSeasons} emptyCopy={copy.editorial.betweenRecovery} /><script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd({ "@context": "https://schema.org", "@type": "CollectionPage", inLanguage: locale, name, url: siteUrl(`/category/${slug}`), mainEntity: { "@type": "ItemList", itemListElement: products.map((product, position) => ({ "@type": "ListItem", position: position + 1, url: siteUrl(`/product/${product.slug}`), name: product.name })) } }) }} /></>;
}
