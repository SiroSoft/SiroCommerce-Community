"use client";

import { useEffect, useId, useState } from "react";
import type { SlotProps } from "@/slots";

interface QaItem {
  id: string;
  customer_name: string;
  question: string;
  answer?: string | null;
  created_at?: string | null;
  answered_at?: string | null;
}

type LoadState = "loading" | "ready" | "unavailable";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readString(data: SlotProps["data"], key: string): string {
  const value = data?.[key];
  return typeof value === "string" ? value.trim() : "";
}

function apiBase(): string {
  const configured = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
  return configured.replace(/\/$/, "");
}

function scopeHeaders(locale?: string): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": locale?.trim() || process.env.NEXT_PUBLIC_STOREFRONT_LOCALE || "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "USD",
  };
  const feToken = process.env.NEXT_PUBLIC_FE_TOKEN;
  if (feToken) headers["X-Siro-FE"] = feToken;
  return headers;
}

function parseItems(payload: unknown): QaItem[] {
  if (!isRecord(payload) || payload.success !== true || !Array.isArray(payload.data)) return [];
  const items: QaItem[] = [];
  for (const entry of payload.data) {
    if (!isRecord(entry)) continue;
    const id = typeof entry.id === "string" ? entry.id : "";
    const question = typeof entry.question === "string" ? entry.question : "";
    if (!id || !question) continue;
    items.push({
      id,
      question,
      customer_name: typeof entry.customer_name === "string" ? entry.customer_name : "Customer",
      answer: typeof entry.answer === "string" ? entry.answer : null,
      created_at: typeof entry.created_at === "string" ? entry.created_at : null,
      answered_at: typeof entry.answered_at === "string" ? entry.answered_at : null,
    });
  }
  return items;
}

function readMessage(payload: unknown): string {
  if (isRecord(payload) && typeof payload.message === "string" && payload.message.trim() !== "") {
    return payload.message.trim();
  }
  return "";
}

function createIdempotencyKey(): string {
  const random = typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2);
  return `qa-${Date.now().toString(36)}-${random}`.slice(0, 128);
}

export function ProductQaSlot({ locale, data }: SlotProps) {
  const headingId = useId();
  const productId = readString(data, "productId");
  const productSlug = readString(data, "productSlug");
  const [items, setItems] = useState<QaItem[]>([]);
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [name, setName] = useState("");
  const [question, setQuestion] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    if (!productId) {
      setItems([]);
      setLoadState("ready");
      return;
    }
    const controller = new AbortController();
    setLoadState("loading");
    const url = `${apiBase()}/api/ext/siro/product-qa/public?product_id=${encodeURIComponent(productId)}`;
    void fetch(url, { headers: scopeHeaders(locale), cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(String(response.status));
        const payload: unknown = await response.json().catch(() => null);
        return parseItems(payload);
      })
      .then((next) => {
        setItems(next);
        setLoadState("ready");
      })
      .catch(() => {
        if (controller.signal.aborted) return;
        setItems([]);
        setLoadState("unavailable");
      });
    return () => controller.abort();
  }, [productId, locale]);

  if (!productId) return null;

  const submit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;
    const customerName = name.trim();
    const bodyQuestion = question.trim();
    if (customerName.length < 2 || customerName.length > 80) {
      setError("Please enter your name (2-80 characters).");
      return;
    }
    if (bodyQuestion.length < 5 || bodyQuestion.length > 1000) {
      setError("Please enter a question (5-1000 characters).");
      return;
    }
    if (/[<>]/.test(customerName) || /[<>]/.test(bodyQuestion)) {
      setError("HTML is not allowed in questions.");
      return;
    }

    setSubmitting(true);
    setError("");
    setNotice("");
    try {
      const response = await fetch(`${apiBase()}/api/ext/siro/product-qa/public`, {
        method: "POST",
        headers: {
          ...scopeHeaders(locale),
          "Content-Type": "application/json",
          "Idempotency-Key": createIdempotencyKey(),
        },
        cache: "no-store",
        body: JSON.stringify({
          product_id: productId,
          ...(productSlug ? { product_slug: productSlug } : {}),
          customer_name: customerName,
          question: bodyQuestion,
        }),
      });
      const payload: unknown = await response.json().catch(() => null);
      if (!response.ok || !isRecord(payload) || payload.success !== true) {
        throw new Error(readMessage(payload) || "Your question could not be submitted.");
      }
      setNotice("Thanks! Your question was submitted and is awaiting review.");
      setName("");
      setQuestion("");
    } catch (caught) {
      setError(caught instanceof Error && caught.message ? caught.message : "Your question could not be submitted.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section className="product-qa" data-testid="product-qa" aria-labelledby={headingId}>
      <div className="section-heading">
        <div>
          <p className="eyebrow">Product questions</p>
          <h2 id={headingId}>Questions &amp; answers</h2>
        </div>
      </div>
      {loadState === "loading" && <p className="muted" role="status">Loading questions...</p>}
      {loadState === "unavailable" && <p className="muted" role="status">Questions are unavailable right now. Please try again later.</p>}
      {loadState === "ready" && items.length === 0 && <p className="muted">No questions yet. Ask the first one below.</p>}
      {items.length > 0 && (
        <ul className="product-qa-list">
          {items.map((item) => (
            <li key={item.id}>
              <p className="product-qa-question"><strong>{item.customer_name}</strong> {item.question}</p>
              {item.answer ? <p className="product-qa-answer">{item.answer}</p> : null}
            </li>
          ))}
        </ul>
      )}
      <form className="product-qa-form" onSubmit={(event) => void submit(event)}>
        <p className="muted">Have a question about this product? Ask us and we will answer after review.</p>
        <label htmlFor={`qa-name-${headingId}`}>Your name
          <input
            id={`qa-name-${headingId}`}
            name="customer_name"
            type="text"
            autoComplete="name"
            minLength={2}
            maxLength={80}
            required
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
        </label>
        <label htmlFor={`qa-question-${headingId}`}>Your question
          <textarea
            id={`qa-question-${headingId}`}
            name="question"
            minLength={5}
            maxLength={1000}
            rows={4}
            required
            value={question}
            onChange={(event) => setQuestion(event.target.value)}
          />
        </label>
        <button className="button" type="submit" disabled={submitting}>
          {submitting ? "Sending..." : "Ask a question"}
        </button>
        {notice ? <p className="form-notice" role="status">{notice}</p> : null}
        {error ? <p className="form-error" role="alert">{error}</p> : null}
      </form>
    </section>
  );
}
