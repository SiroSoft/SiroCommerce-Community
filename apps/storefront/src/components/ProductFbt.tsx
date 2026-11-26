"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { formatMinor } from "@/lib/money";
import { useCopy, useLocale } from "@/lib/i18n-client";

type FbtSuggestion = {
  variantId: string;
  productId: string;
  name: string;
  slug: string;
  priceMinor: number;
  currency: string;
  image: string;
  imageAlt: string;
};

const idPattern = /^[A-Za-z0-9][A-Za-z0-9._~:-]{0,63}$/;
const slugPattern = /^[A-Za-z0-9][A-Za-z0-9._~-]{0,159}$/;
const currencyPattern = /^[A-Z]{3}$/;

function safeText(value: unknown, maxLength: number): string | undefined {
  if (typeof value !== "string") return undefined;
  const text = value.trim();
  return text !== "" && text.length <= maxLength ? text : undefined;
}

function safeImage(value: unknown): string {
  if (typeof value !== "string") return "";
  const image = value.trim();
  if (image.startsWith("/") && !image.startsWith("//")) return image.slice(0, 2048);
  if (/^https?:\/\//i.test(image)) return image.slice(0, 2048);
  return "";
}

function parseSuggestions(data: unknown, excludedIds: string[]): FbtSuggestion[] {
  if (!Array.isArray(data)) return [];
  const excluded = new Set(excludedIds);
  const seen = new Set<string>();
  const suggestions: FbtSuggestion[] = [];
  for (const entry of data) {
    if (!entry || typeof entry !== "object") continue;
    const record = entry as Record<string, unknown>;
    const variantId = typeof record.variant_id === "string" && idPattern.test(record.variant_id) ? record.variant_id : undefined;
    const productId = typeof record.product_id === "string" && idPattern.test(record.product_id) ? record.product_id : undefined;
    const slug = typeof record.slug === "string" && slugPattern.test(record.slug) ? record.slug : undefined;
    const name = safeText(record.name, 200);
    const priceMinor = typeof record.price_minor === "number" && Number.isSafeInteger(record.price_minor) && record.price_minor >= 0 ? record.price_minor : undefined;
    const currency = typeof record.currency === "string" && currencyPattern.test(record.currency) ? record.currency : undefined;
    if (!variantId || !productId || !slug || !name || priceMinor === undefined || !currency || excluded.has(variantId) || excluded.has(productId) || seen.has(slug)) continue;
    seen.add(slug);
    suggestions.push({
      variantId,
      productId,
      name,
      slug,
      priceMinor,
      currency,
      image: safeImage(record.image),
      imageAlt: safeText(record.image_alt, 200) ?? name,
    });
    if (suggestions.length >= 4) break;
  }
  return suggestions;
}

function affinityUrl(variantId: string): string {
  const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
  const path = `/api/products/${encodeURIComponent(variantId)}/affinities`;
  return baseUrl ? `${baseUrl.replace(/\/$/, "")}${path}` : path;
}

async function fetchAffinities(variantId: string, signal: AbortSignal): Promise<unknown> {
  try {
    const response = await fetch(affinityUrl(variantId), { cache: "no-store", signal });
    if (!response.ok) return undefined;
    const body = (await response.json().catch(() => null)) as { data?: unknown } | null;
    return body?.data;
  } catch {
    return undefined;
  }
}

export function ProductFbt({ variantIds }: { variantIds: string[] }) {
  const locale = useLocale();
  const copy = useCopy().product;
  const [items, setItems] = useState<FbtSuggestion[]>([]);
  const key = variantIds.filter((id) => idPattern.test(id)).join("|");

  useEffect(() => {
    const ids = key === "" ? [] : key.split("|");
    if (ids.length === 0) {
      setItems([]);
      return;
    }
    const controller = new AbortController();
    let active = true;
    void (async () => {
      for (const variantId of ids) {
        const data = await fetchAffinities(variantId, controller.signal);
        if (!active) return;
        const suggestions = parseSuggestions(data, ids);
        if (suggestions.length > 0) {
          setItems(suggestions);
          return;
        }
      }
      if (active) setItems([]);
    })();
    return () => {
      active = false;
      controller.abort();
    };
  }, [key]);

  if (items.length === 0) return null;

  return <section className="sf-fbt" aria-labelledby="sf-fbt-title">
    <div className="section-heading sf-fbt-heading">
      <div>
        <p className="eyebrow">{copy.boughtTogether}</p>
        <h2 id="sf-fbt-title">{copy.boughtTogether}</h2>
        <p className="muted">{copy.boughtTogetherNote}</p>
      </div>
    </div>
    <ul className="sf-fbt-list">
      {items.map((item) => <li className="sf-fbt-card" key={item.variantId}>
        <Link className="sf-fbt-link" href={`/product/${encodeURIComponent(item.slug)}`}>
          <img className="sf-fbt-image" src={item.image || "/placeholder-product.svg"} alt={item.imageAlt} loading="lazy" decoding="async" width="160" height="200" />
          <span className="sf-fbt-name">{item.name}</span>
          <span className="sf-fbt-price">{formatMinor(item.priceMinor, item.currency, locale)}</span>
        </Link>
      </li>)}
    </ul>
  </section>;
}
