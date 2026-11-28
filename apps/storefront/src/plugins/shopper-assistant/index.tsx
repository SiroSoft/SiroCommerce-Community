"use client";

import { usePathname } from "next/navigation";
import { useState, type FormEvent } from "react";
import { getCustomerAccessToken } from "@/services/customer";
import type { SlotProps } from "@/slots";

interface Source {
  label: string;
  url: string;
}

interface AssistantReply {
  status: string;
  answer: string | null;
  sources: Source[];
  reason: string | null;
}

function apiBase(): string {
  return (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "").replace(/\/+$/, "");
}

function headers(locale?: string): Record<string, string> {
  const result: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": locale?.trim() || process.env.NEXT_PUBLIC_STOREFRONT_LOCALE || "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
  };
  const feToken = process.env.NEXT_PUBLIC_FE_TOKEN;
  if (feToken) result["X-Siro-FE"] = feToken;
  return result;
}

function idempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return `assistant-${crypto.randomUUID()}`;
  }
  return `assistant-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

function productSlug(pathname: string): string | undefined {
  const match = /^\/product\/([^/?#]+)/.exec(pathname);
  return match?.[1] ? decodeURIComponent(match[1]) : undefined;
}

function readReply(payload: unknown): AssistantReply | null {
  if (typeof payload !== "object" || payload === null) return null;
  const envelope = payload as { success?: unknown; data?: unknown };
  if (envelope.success !== true || typeof envelope.data !== "object" || envelope.data === null) return null;
  const data = envelope.data as Record<string, unknown>;
  const sources = Array.isArray(data.sources)
    ? data.sources.filter((item): item is Source => typeof item === "object" && item !== null
      && typeof (item as Source).label === "string"
      && typeof (item as Source).url === "string")
    : [];
  return {
    status: typeof data.status === "string" ? data.status : "available",
    answer: typeof data.answer === "string" ? data.answer : null,
    sources,
    reason: typeof data.reason === "string" ? data.reason : null,
  };
}

export function ShopperAssistant({ locale }: SlotProps) {
  const pathname = usePathname() || "/";
  const [open, setOpen] = useState(false);
  const [question, setQuestion] = useState("");
  const [reply, setReply] = useState<AssistantReply | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showOrderAccess, setShowOrderAccess] = useState(false);
  const [orderId, setOrderId] = useState("");
  const [orderToken, setOrderToken] = useState("");

  const ask = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const text = question.trim();
    if (text.length < 3 || busy) return;
    setBusy(true);
    setError(null);
    try {
      const body: Record<string, string> = { question: text };
      const slug = productSlug(pathname);
      if (slug) body.product_slug = slug;
      const requestedOrderId = orderId.trim();
      const requestedOrderToken = orderToken.trim();
      if (showOrderAccess && requestedOrderId) {
        body.order_id = requestedOrderId.slice(0, 64);
        if (requestedOrderToken) body.order_token = requestedOrderToken.slice(0, 256);
      }
      const requestHeaders: Record<string, string> = { ...headers(locale), "Idempotency-Key": idempotencyKey() };
      const accessToken = getCustomerAccessToken();
      if (accessToken) requestHeaders.Authorization = `Bearer ${accessToken}`;
      const response = await fetch(`${apiBase()}/api/ext/siro/shopper-assistant/public/ask`, {
        method: "POST",
        headers: requestHeaders,
        body: JSON.stringify(body),
      });
      if (response.status === 429) {
        setError("Too many questions. Please wait a moment and try again.");
        return;
      }
      if (!response.ok) {
        setError("The assistant is unavailable right now.");
        return;
      }
      const parsed = readReply(await response.json());
      if (parsed === null) {
        setError("The assistant returned an invalid response.");
        return;
      }
      setReply(parsed);
    } catch {
      setError("The assistant is unavailable right now.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed bottom-4 right-4 z-50">
      {open ? (
        <section
          role="dialog"
          aria-label="Shopping assistant"
          className="mb-2 flex max-h-[70vh] w-[min(24rem,calc(100vw-2rem))] flex-col rounded-lg border bg-background p-3 shadow-xl"
        >
          <div className="mb-2 flex items-center justify-between gap-2">
            <h2 className="text-sm font-semibold">Shopping assistant</h2>
            <button type="button" className="text-xs underline" onClick={() => setOpen(false)}>Close</button>
          </div>
          <form className="flex gap-2" onSubmit={ask}>
            <input
              aria-label="Your question"
              className="min-w-0 flex-1 rounded-md border bg-background px-2 py-1.5 text-sm"
              maxLength={1000}
              placeholder="Ask about a product, size or shipping"
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
            />
            <button type="submit" className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground" disabled={busy || question.trim().length < 3}>
              {busy ? "..." : "Ask"}
            </button>
          </form>
          <button
            type="button"
            aria-expanded={showOrderAccess}
            aria-controls="shopper-order-access"
            className="mt-2 self-start text-xs underline"
            onClick={() => setShowOrderAccess((value) => !value)}
          >
            {showOrderAccess ? "Hide order details" : "Have an order? Add order details"}
          </button>
          {showOrderAccess && (
            <div id="shopper-order-access" className="mt-2 space-y-2 rounded-md border p-2">
              <label className="block text-xs font-medium" htmlFor="shopper-order-id">
                Order ID
                <input
                  id="shopper-order-id"
                  className="mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm"
                  autoComplete="off"
                  maxLength={64}
                  placeholder="From your order confirmation"
                  value={orderId}
                  onChange={(event) => setOrderId(event.target.value)}
                />
              </label>
              <label className="block text-xs font-medium" htmlFor="shopper-order-token">
                Order access token
                <input
                  id="shopper-order-token"
                  type="password"
                  className="mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm"
                  autoComplete="off"
                  maxLength={256}
                  placeholder="Only for a guest order"
                  value={orderToken}
                  onChange={(event) => setOrderToken(event.target.value)}
                />
              </label>
              <p className="text-[11px] text-muted-foreground">
                Only add details for your own order. Signed-in customers do not need a token.
              </p>
            </div>
          )}
          {error && <p role="alert" className="mt-2 text-xs text-destructive">{error}</p>}
          {reply && (
            <div className="mt-3 space-y-2 overflow-y-auto text-sm">
              {reply.answer && <p>{reply.answer}</p>}
              {!reply.answer && <p role="status" className="text-muted-foreground">The assistant could not answer that yet.</p>}
              {reply.sources.length > 0 && (
                <ul className="space-y-1 text-xs">
                  {reply.sources.map((source) => (
                    <li key={source.url}><a className="underline" href={source.url}>{source.label}</a></li>
                  ))}
                </ul>
              )}
            </div>
          )}
          <p className="mt-3 text-[11px] text-muted-foreground">Answers are read-only and may be limited to the current product and catalog.</p>
        </section>
      ) : null}
      <button
        type="button"
        aria-label="Open shopping assistant"
        aria-expanded={open}
        className="rounded-full bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow-lg"
        onClick={() => setOpen((value) => !value)}
      >
        {open ? "Close" : "Ask"}
      </button>
    </div>
  );
}
