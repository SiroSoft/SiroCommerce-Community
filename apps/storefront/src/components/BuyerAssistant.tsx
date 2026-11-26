"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { analyzeBuyerQuestion, type BuyerAssistantState } from "@/lib/buyer-assistant";
import type { Product } from "@/types";
import { formatMinor } from "@/lib/money";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { trackStorefrontEvent } from "@/services/storefront-events";

export function BuyerAssistant({ products, state = "ready" }: { products: Product[]; state?: BuyerAssistantState }) {
  const copy = useCopy().assistant;
  const locale = useLocale();
  const [question, setQuestion] = useState("");
  const [submittedQuestion, setSubmittedQuestion] = useState("");
  const result = analyzeBuyerQuestion(submittedQuestion, products);
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const next = question.trim(); setSubmittedQuestion(next); const analysis = analyzeBuyerQuestion(next, products); trackStorefrontEvent("buyer_assistant_submit", { query_length: analysis.question.length, result_count: analysis.suggestions.length, outcome: analysis.outcome }); }
  return <aside className="buyer-assistant" aria-labelledby="buyer-assistant-title">
    <div><p className="eyebrow">{copy.eyebrow}</p><h2 id="buyer-assistant-title">{copy.title}</h2><p className="buyer-assistant-copy">{copy.copy}</p></div>
    {state === "loading" ? <p className="buyer-assistant-status" role="status" aria-live="polite">{copy.loadingState}</p> : state === "error" ? <p className="buyer-assistant-status" role="alert">{copy.errorState}</p> : state === "unavailable" ? <p className="buyer-assistant-status">{copy.unavailableState}</p> : <>
      <form className="buyer-assistant-form" onSubmit={submit}><label htmlFor="buyer-assistant-question">{copy.label}</label><div><input id="buyer-assistant-question" value={question} maxLength={240} onChange={(event) => setQuestion(event.target.value)} placeholder={copy.placeholder} aria-describedby="buyer-assistant-help" /><button type="submit">{copy.suggest}</button></div><p id="buyer-assistant-help" className="buyer-assistant-note">{copy.help}</p></form>
      {submittedQuestion && <div className="buyer-assistant-results" role="status" aria-live="polite">{result.outcome === "unsupported" ? <p>{copy.unsupported}</p> : result.outcome === "too_long" ? <p>{copy.tooLong}</p> : result.suggestions.length > 0 ? <><p>{fill(copy.basedOn, { question: result.question })}</p><ul>{result.suggestions.map((product, index) => <li key={product.id}><Link href={`/product/${product.slug}`} onClick={() => trackStorefrontEvent("buyer_assistant_click", { product_id: product.id, position: index + 1 })}><strong>{product.name}</strong><span>{product.categoryName} · {formatMinor(product.price, product.currency, locale)} · {product.status === "out_of_stock" ? copy.outOfStockStatus : product.status === "backorder" ? copy.backorderStatus : copy.availableStatus}</span></Link></li>)}</ul><p className="buyer-assistant-note">{copy.priceNote}</p></> : <p>{copy.noMatch}</p>}</div>}
      <p className="buyer-assistant-note">{copy.disclaimer}</p>
    </>}
  </aside>;
}
