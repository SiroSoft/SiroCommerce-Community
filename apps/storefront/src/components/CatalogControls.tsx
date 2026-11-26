"use client";
import { useState, useTransition } from "react";
import Link from "next/link";
import type { CatalogFacet, Product, ProductQuery } from "@/types";
import { discoveryPerPage, parseSort, queryForUrl, type DiscoverySort } from "@/lib/discovery";
import { fill } from "@/lib/i18n";
import { useCopy } from "@/lib/i18n-client";
import { useCardVariant, useListVariant } from "@/presentation/variant-context";
import { ProductGrid } from "./ProductGrid";

export function CatalogControls({ initialProducts, query, facets = [], total }: { initialProducts: Product[]; query: ProductQuery; facets?: CatalogFacet[]; total?: number }) {
  const copy = useCopy();
  const cardVariant = useCardVariant();
  const listVariant = useListVariant();
  const [sort, setSort] = useState<DiscoverySort>(parseSort(query.sort));
  const [isPending, startTransition] = useTransition();
  const urlQuery = query.category ? { ...query, category: undefined } : query;
  function changeSort(next: DiscoverySort) {
    setSort(next);
    startTransition(() => {
      window.location.assign(`${window.location.pathname}${queryForUrl(urlQuery, next)}`);
    });
  }
  const page = query.page ?? 1;
  const perPage = query.perPage ?? discoveryPerPage;
  const lastPage = total !== undefined && total > 0 ? Math.max(1, Math.ceil(total / perPage)) : undefined;
  const hasPrevious = page > 1;
  const hasNext = lastPage !== undefined ? page < lastPage : total !== undefined ? page * perPage < total : initialProducts.length >= perPage;
  const filterFacets = query.facets ?? {};
  const activeFilters = Object.entries(filterFacets).flatMap(([name, values]) => values.map((value) => ({ name, value, label: facets.find((facet) => facet.name === name)?.values.find((entry) => entry.value === value)?.label ?? value })));
  const activeFilterCount = Object.values(filterFacets).reduce((count, values) => count + values.length, 0);
  const pageNumbers: (number | "gap")[] = [];
  if (lastPage !== undefined && lastPage > 1) {
    const start = Math.max(1, page - 1);
    const end = Math.min(lastPage, page + 1);
    for (let value = 1; value <= lastPage; value += 1) {
      if (value === 1 || value === lastPage || (value >= start && value <= end)) pageNumbers.push(value);
      else if (pageNumbers[pageNumbers.length - 1] !== "gap") pageNumbers.push("gap");
    }
  }
  function pageHref(target: number): string {
    const base = queryForUrl({ ...urlQuery, page: target }, sort);
    return target === 1 ? `${base}${base ? "&" : "?"}page=1` : base;
  }
  function removeFilterHref(name: string, value: string): string {
    const nextFacets: Record<string, string[]> = { ...filterFacets, [name]: (filterFacets[name] ?? []).filter((entry) => entry !== value) };
    if (nextFacets[name]!.length === 0) delete nextFacets[name];
    return queryForUrl({ ...urlQuery, facets: nextFacets, page: undefined }, sort);
  }
  const filterContent = facets.length > 0 && <div className="facet-groups">{facets.map((facet) => <fieldset key={facet.name}><legend>{facet.label ?? facet.name}</legend>{facet.values.map((value) => { const checked = query.facets?.[facet.name]?.includes(value.value) ?? false; return <label className="facet-option" key={value.value}><input type="checkbox" name={`facet_${facet.name}`} value={value.value} defaultChecked={checked} /><span>{value.label ?? value.value}</span><small>({value.count})</small></label>; })}</fieldset>)}</div>;
  const count = total ?? initialProducts.length;
  const unit = count === 1 ? copy.discovery.piece : copy.discovery.pieces;
  return <section aria-label={copy.catalog.filterResults}>
    <div className="results-header"><p className="results-count" aria-live="polite"><span className="results-count-value">{count} {unit}</span> <span className="muted">{copy.catalog.selectedFor}</span></p><label className="results-sort">{copy.catalog.sort} <select value={sort} onChange={(event) => changeSort(parseSort(event.target.value))} aria-label={copy.catalog.sort} disabled={isPending}><option value="featured">{copy.discovery.featured}</option><option value="price-asc">{copy.discovery.priceLow}</option><option value="price-desc">{copy.discovery.priceHigh}</option></select></label></div>
    {facets.length > 0 && <details className="filters filter-panel" open={activeFilterCount > 0}><summary aria-label={copy.catalog.filterResults}>{copy.catalog.filterResults} {activeFilterCount > 0 && <strong> · {fill(activeFilterCount === 1 ? copy.catalog.filterActive : copy.catalog.filtersActive, { count: activeFilterCount })}</strong>} <span aria-hidden="true">({fill(copy.catalog.optionsSuffix, { count: facets.reduce((sum, facet) => sum + facet.values.length, 0) })})</span></summary><form method="get" action=""><input type="hidden" name="q" value={query.search ?? ""} />{query.category && <input type="hidden" name="category" value={query.category} />}{query.vendor && <input type="hidden" name="vendor" value={query.vendor} />}<input type="hidden" name="sort" value={sort === "featured" ? "" : sort} />{filterContent}<div className="filter-actions"><button className="button" type="submit">{copy.catalog.applyFilters}</button>{activeFilterCount > 0 && <Link className="text-link" href={queryForUrl({ ...urlQuery, facets: undefined, page: undefined }, sort)}>{copy.catalog.clearFilters}</Link>}</div></form></details>}
    {activeFilters.length > 0 && <ul className="sf-filter-chips" aria-label={copy.catalog.activeFilters}>{activeFilters.map((filter) => <li key={`${filter.name}:${filter.value}`}><span className="sf-filter-chip">{filter.label}<Link className="sf-filter-chip-remove" href={removeFilterHref(filter.name, filter.value)} aria-label={fill(copy.catalog.removeFilter, { label: filter.label })}>×</Link></span></li>)}</ul>}
    <div aria-busy={isPending}>{initialProducts.length === 0 ? <div className="catalog-empty empty-state"><p className="eyebrow">{copy.catalog.emptyEyebrow}</p><h2>{copy.catalog.emptyTitle}</h2><p>{copy.catalog.emptyStatus}</p>{query.search && <p>{fill(copy.catalog.emptyFor, { q: query.search })}</p>}<p className="recovery-copy">{copy.catalog.emptyRecovery}</p><div className="state-actions">{query.search && <Link className="text-link" href="/search">{copy.catalog.clearSearch}</Link>}{query.facets && Object.keys(query.facets).length > 0 && <Link className="text-link" href={queryForUrl({ ...urlQuery, facets: undefined, page: undefined }, sort)}>{copy.catalog.clearFilters}</Link>}<Link className="text-link" href="/category/apparel">{copy.catalog.browseApparel}</Link><Link className="text-link" href="/category/home-living">{copy.catalog.browseHome}</Link></div></div> : <ProductGrid products={initialProducts} cardVariant={cardVariant} listVariant={listVariant} />}</div>
    {(hasPrevious || hasNext || pageNumbers.length > 0) && <nav className="pagination" aria-label={copy.catalog.pagination}>{hasPrevious && <Link className="pagination-prev" href={pageHref(page - 1)}>{copy.catalog.previous}</Link>}{pageNumbers.length > 0 && <ol className="sf-page-list">{pageNumbers.map((value, index) => value === "gap" ? <li className="sf-page-gap" key={`gap-${index}`} aria-hidden="true">…</li> : <li key={value}>{value === page ? <span className="sf-page-link is-current" aria-current="page" aria-label={fill(copy.catalog.page, { page: value })}>{value}</span> : <Link className="sf-page-link" href={pageHref(value)} aria-label={fill(copy.catalog.page, { page: value })}>{value}</Link>}</li>)}</ol>}{hasNext && <Link className="pagination-next" href={pageHref(page + 1)}>{copy.catalog.next}</Link>}</nav>}
  </section>;
}
