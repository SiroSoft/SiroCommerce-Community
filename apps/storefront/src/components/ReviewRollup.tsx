"use client";

import { useEffect, useState } from "react";
import { useCopy } from "@/lib/i18n-client";

type Summary = {
  rating_avg: number;
  rating_count: number;
  pos_count: number;
  neg_count: number;
  aspects: Record<string, { pos: number; neg: number }>;
  excerpts: { positive?: string | null; negative?: string | null };
};

function ratingMarks(rating: number): string {
  const rounded = Math.min(5, Math.max(0, Math.round(rating)));
  return `${"★".repeat(rounded)}${"☆".repeat(5 - rounded)}`;
}

/**
 * Buyer-voice summary mined nightly from approved reviews (no model).
 * Cold start renders nothing — the plain review list stays as fallback.
 * Everything shown is a count or a verbatim quote; nothing is generated.
 */
export function ReviewRollup({ productId }: { productId: string }) {
  const copy = useCopy().product;
  const [summary, setSummary] = useState<Summary | null>(null);
  useEffect(() => {
    let active = true;
    const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
    const url = baseUrl
      ? `${baseUrl.replace(/\/$/, "")}/api/products/${encodeURIComponent(productId)}/reviews/summary`
      : `/api/products/${encodeURIComponent(productId)}/reviews/summary`;
    void fetch(url, { cache: "no-store" })
      .then((response) => (response.ok ? response.json().catch(() => null) : null))
      .then((body: { success?: boolean; data?: unknown } | null) => {
        if (!active || !body?.data || typeof body.data !== "object") return;
        setSummary(body.data as Summary);
      })
      .catch(() => undefined);
    return () => { active = false; };
  }, [productId]);

  if (!summary || summary.rating_count === 0) return null;
  const aspects = Object.entries(summary.aspects).slice(0, 6);
  return <section className="review-rollup" aria-labelledby="review-rollup-title">
    <p className="eyebrow">{copy.reviewSummary}</p>
    <h2 id="review-rollup-title">{copy.reviewSummary}</h2>
    <p className="rating-summary"><span aria-hidden="true">{ratingMarks(summary.rating_avg)}</span> <strong>{summary.rating_avg.toFixed(1)}</strong> <span className="muted">· {summary.pos_count + summary.neg_count} opinions</span></p>
    {aspects.length > 0 && <ul className="aspect-chips">{aspects.map(([aspect, counts]) => <li key={aspect}><strong>{aspect}</strong> <span className="aspect-pos">+{counts.pos}</span> <span className="aspect-neg">−{counts.neg}</span></li>)}</ul>}
    {summary.excerpts.positive && <blockquote><p>“{summary.excerpts.positive}”</p><cite>{copy.helpfulPositive}</cite></blockquote>}
    {summary.excerpts.negative && <blockquote><p>“{summary.excerpts.negative}”</p><cite>{copy.helpfulNegative}</cite></blockquote>}
  </section>;
}
