"use client";

import { useRef } from "react";
import type { Product } from "@/types";
import { useCopy } from "@/lib/i18n-client";
import { trackStorefrontEvent } from "@/services/storefront-events";
import { SearchSuggest, trackSuggestionClick } from "@/components/SearchSuggest";

export function SearchForm({ value = "", suggestions = [] }: { value?: string; suggestions?: Product[] }) {
  const text = useCopy();
  const formRef = useRef<HTMLFormElement>(null);
  const pick = (query: string, position: number) => {
    trackSuggestionClick(query, position);
    const input = document.getElementById("catalog-search") as HTMLInputElement | null;
    if (input) input.value = query;
    formRef.current?.requestSubmit();
  };
  return <form ref={formRef} className="search-form" action="/search" method="get" role="search" onSubmit={(event) => { const query = new FormData(event.currentTarget).get("q"); if (typeof query === "string" && query.trim()) trackStorefrontEvent("search_submit", { query_length: query.trim().length, query: query.trim().slice(0, 64) }); }}>
    <label className="sr-only" htmlFor="catalog-search">{text.search.formLabel}</label>
    <input id="catalog-search" name="q" defaultValue={value} placeholder={text.search.formPlaceholder} autoComplete="off" aria-autocomplete="list" aria-controls="catalog-search-suggestions" aria-expanded="false" list={suggestions.length ? "search-suggestions" : undefined} />
    <SearchSuggest inputId="catalog-search" onPick={pick} />
    {suggestions.length > 0 && <datalist id="search-suggestions">{suggestions.map((product) => <option key={product.id} value={product.name}>{product.categoryName}</option>)}</datalist>}
      <button type="submit">{text.search.submit}</button>{value && <a className="text-link" href="/search" aria-label={text.catalog.clearSearch}>{text.search.clear}</a>}
  </form>;
}
