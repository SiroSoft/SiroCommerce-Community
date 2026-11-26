import type { Metadata } from "next";
import { CatalogControls } from "@/components/CatalogControls";
import { CatalogRequestError, catalog } from "@/services/catalog";
import { CatalogState } from "@/components/CatalogState";
import { discoveryPerPage, parseFacets, parsePage, parseQuery, parseSort, parseVendor } from "@/lib/discovery";
import { siteUrl } from "@/lib/locale";
import { requestLocale, requestScope } from "@/lib/request-locale";
import { fill, getCopy } from "@/lib/i18n";
import { Breadcrumbs } from "@/components/Breadcrumbs";
import { SearchForm } from "@/components/SearchForm";
import { LazyBuyerAssistant as BuyerAssistant } from "@/components/lazy/LazyBuyerAssistant";
import { CollectionDirectory } from "@/components/merchandising/CollectionDirectory";
import type { Product } from "@/types";
import type { DiscoverySort } from "@/lib/discovery";
import { jsonLd } from "@/lib/structured-data";

export async function generateMetadata(): Promise<Metadata> { const copy = getCopy(await requestLocale()).search; return { title: copy.pageTitle, description: copy.pageDesc, alternates: { canonical: "/search" }, robots: { index: false, follow: true } }; }

function sortProducts(products: Product[], sort: DiscoverySort): Product[] {
  if (sort === "featured") return products;
  return [...products].sort((a, b) => (sort === "price-asc" ? a.price - b.price : b.price - a.price) || a.id.localeCompare(b.id));
}

export default async function Search({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const locale = await requestLocale(); const copy = getCopy(locale); const scope = await requestScope();
  const params = await searchParams; const q = parseQuery(params.q); const vendor = parseVendor(params.vendor); const sort = parseSort(params.sort); const page = parsePage(params.page); const facetsQuery = parseFacets(params); const source = catalog(scope); let result; let suggestions = [];
  try { result = source.listProductsResult ? await source.listProductsResult({ search: q, vendor: vendor || undefined, sort, page, perPage: discoveryPerPage, facets: facetsQuery }) : { products: await source.listProducts({ search: q, vendor: vendor || undefined, sort, page, perPage: discoveryPerPage, facets: facetsQuery }), facets: [] }; suggestions = q && source.autocomplete ? await source.autocomplete(q) : []; } catch (error) { if (error instanceof CatalogRequestError) return <CatalogState error={error} locale={locale} />; throw error; }
  const { facets, total } = result; const products = sortProducts(result.products, sort); const activeFilters = Object.values(facetsQuery).reduce((count, values) => count + values.length, 0);
  return <><Breadcrumbs current={copy.search.title} locale={locale} /><section className="page-intro search-hero"><p className="eyebrow">{copy.search.eyebrow}</p><h1>{copy.search.title}</h1><p>{copy.search.intro}</p><SearchForm value={q} suggestions={suggestions} />{(q || activeFilters > 0) && <p className="search-summary">{q ? <>{copy.catalog.showingFor} <strong>“{q}”</strong></> : copy.catalog.showingFiltered}{activeFilters > 0 && ` · ${fill(activeFilters === 1 ? copy.catalog.filterActive : copy.catalog.filtersActive, { count: activeFilters })}`} <a className="text-link" href="/search">{copy.catalog.clearAll}</a></p>}</section>{!q && activeFilters === 0 && <CollectionDirectory products={products} locale={locale} />}<BuyerAssistant products={products} /><CatalogControls initialProducts={products} facets={facets} total={total} query={{ search: q, vendor: vendor || undefined, sort, page, perPage: discoveryPerPage, facets: facetsQuery }} /><script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd({ "@context": "https://schema.org", "@type": "SearchResultsPage", inLanguage: locale, url: siteUrl("/search"), name: q ? fill(copy.search.resultsFor, { q }) : copy.search.title }) }} /></>;
}
