"use client";

import { useEffect, useRef, useState } from "react";
import { useCopy } from "@/lib/i18n-client";
import { trackStorefrontEvent } from "@/services/storefront-events";

export type QuerySuggestion = { query: string; submits: number; clicks: number };
export type ProductSuggestion = { product_id: string; variant_id: string; name: string; slug: string; price_minor: number; currency: string; image: string };
export type SuggestResult = { queries: QuerySuggestion[]; products: ProductSuggestion[] };

/** The storefront detail route is singular: /product/[slug]. */
export function productHref(slug: string): string {
  return `/product/${encodeURIComponent(slug)}`;
}

function isProductSuggestion(item: unknown): item is ProductSuggestion {
  return typeof item === "object" && item !== null && typeof (item as ProductSuggestion).name === "string" && typeof (item as ProductSuggestion).product_id === "string";
}

async function fetchSuggestions(query: string): Promise<SuggestResult> {
  const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
  if (!baseUrl || query.trim().length < 2) return { queries: [], products: [] };
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), 2000);
  try {
    const response = await fetch(`${baseUrl.replace(/\/$/, "")}/api/search/suggest?q=${encodeURIComponent(query.trim())}`, { signal: controller.signal, cache: "no-store" });
    if (!response.ok) return { queries: [], products: [] };
    const body = (await response.json().catch(() => null)) as { success?: boolean; data?: unknown; products?: unknown } | null;
    const data = body?.data;
    const queries = Array.isArray(data)
      ? data
        .filter((item): item is QuerySuggestion => typeof item === "object" && item !== null && typeof (item as QuerySuggestion).query === "string")
        .slice(0, 8)
      : [];
    const products = Array.isArray(body?.products) ? (body.products as unknown[]).filter(isProductSuggestion).slice(0, 4) : [];
    return { queries, products };
  } catch {
    return { queries: [], products: [] };
  } finally {
    window.clearTimeout(timer);
  }
}

/**
 * Query suggestions rolled up nightly from real search logs (no model).
 * Keyboard: Up/Down to move, Enter to search, Esc to dismiss.
 */
export function SearchSuggest({ inputId, onPick }: { inputId: string; onPick: (query: string, position: number) => void }) {
  const copy = useCopy();
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<QuerySuggestion[]>([]);
  const [products, setProducts] = useState<ProductSuggestion[]>([]);
  const [active, setActive] = useState(-1);
  const boxRef = useRef<HTMLDivElement>(null);
  const listId = `${inputId}-suggestions`;

  useEffect(() => {
    const input = document.getElementById(inputId) as HTMLInputElement | null;
    if (!input) return;
    let timer = 0;
    let stale = false;
    const update = () => {
      window.clearTimeout(timer);
      const value = input.value;
      if (value.trim().length < 2) {
        setItems([]);
        setOpen(false);
        return;
      }
      timer = window.setTimeout(() => {
        void fetchSuggestions(value).then((next) => {
          if (stale) return;
          setItems(next.queries);
          setProducts(next.products);
          setActive(-1);
          setOpen((next.queries.length + next.products.length) > 0 && document.activeElement === input);
        });
      }, 150);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        if (items.length + products.length === 0) return;
        event.preventDefault();
        setActive((current) => {
          const next = event.key === "ArrowDown" ? current + 1 : current - 1;
          return (next + items.length + products.length) % (items.length + products.length);
        });
        setOpen(true);
      } else if (event.key === "Enter" && active >= 0) {
        event.preventDefault();
        const pickedQuery = active < items.length ? items[active] : undefined;
        const pickedProduct = active >= items.length ? products[active - items.length] : undefined;
        if (pickedQuery) onPick(pickedQuery.query, active);
        else if (pickedProduct) window.location.assign(productHref(pickedProduct.slug));
      } else if (event.key === "Escape") {
        setOpen(false);
      }
    };
    const onBlur = (event: FocusEvent) => {
      if (!boxRef.current?.contains(event.relatedTarget as Node | null)) setOpen(false);
    };
    input.addEventListener("input", update);
    input.addEventListener("keydown", onKey);
    input.addEventListener("focus", update);
    input.addEventListener("blur", onBlur);
    return () => {
      stale = true;
      window.clearTimeout(timer);
      input.removeEventListener("input", update);
      input.removeEventListener("keydown", onKey);
      input.removeEventListener("focus", update);
      input.removeEventListener("blur", onBlur);
    };
  });

  useEffect(() => {
    const input = document.getElementById(inputId) as HTMLInputElement | null;
    if (input) input.setAttribute("aria-expanded", open ? "true" : "false");
  }, [open, inputId]);

  if (!open || (items.length === 0 && products.length === 0)) return null;
  return <div ref={boxRef} className="search-suggest" role="listbox" id={listId} aria-label={copy.search.suggestLabel ?? "Search suggestions"}>
    {items.map((item, index) => <button key={item.query} type="button" role="option" aria-selected={index === active} className={index === active ? "is-active" : undefined} onMouseDown={(event) => event.preventDefault()} onClick={() => onPick(item.query, index)} onMouseEnter={() => setActive(index)}>{item.query}</button>)}
    {products.map((product, index) => {
      const position = items.length + index;
      return <a key={product.product_id} role="option" aria-selected={position === active} className={position === active ? "is-active suggest-product" : "suggest-product"} href={productHref(product.slug)} onMouseDown={(event) => event.preventDefault()} onClick={() => trackSuggestionClick(product.name, position)} onMouseEnter={() => setActive(position)}>{product.image !== "" ? <img src={product.image} alt="" width={32} height={32} decoding="async" /> : null}<span>{product.name}</span></a>;
    })}
  </div>;
}

export function trackSuggestionClick(query: string, position: number): void {
  trackStorefrontEvent("search_click", { query: query.slice(0, 64), position });
}
