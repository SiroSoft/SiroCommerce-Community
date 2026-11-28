"use client";

import { useState, type FormEvent } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { extensionsService, type ExtensionPanel } from "@/services/extensions.service";

interface Source { label: string; url: string }
interface Reply { status: string; answer: string | null; sources: Source[]; reason: string | null }

function readReply(value: unknown): Reply | null {
  if (typeof value !== "object" || value === null) return null;
  const row = value as Record<string, unknown>;
  const sources = Array.isArray(row.sources)
    ? row.sources.filter((item): item is Source => typeof item === "object" && item !== null
      && typeof (item as Source).label === "string"
      && typeof (item as Source).url === "string")
    : [];
  return {
    status: typeof row.status === "string" ? row.status : "available",
    answer: typeof row.answer === "string" ? row.answer : null,
    sources,
    reason: typeof row.reason === "string" ? row.reason : null,
  };
}

export function StaffAssistantPanel({ extensionId, panel }: { extensionId: string; panel: ExtensionPanel }) {
  const [question, setQuestion] = useState("");
  const [orderId, setOrderId] = useState("");
  const [productId, setProductId] = useState("");
  const [customerId, setCustomerId] = useState("");
  const [reply, setReply] = useState<Reply | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const text = question.trim();
    if (text.length < 3 || busy) return;
    setBusy(true);
    setError(null);
    try {
      const value = await extensionsService.assistantAsk(extensionId, panel.endpoint, {
        question: text,
        ...(orderId.trim() ? { order_id: orderId.trim() } : {}),
        ...(productId.trim() ? { product_id: productId.trim() } : {}),
        ...(customerId.trim() ? { customer_id: customerId.trim() } : {}),
      });
      const parsed = readReply(value);
      if (parsed === null) throw new Error("The assistant returned an invalid response.");
      setReply(parsed);
    } catch {
      setError("The assistant is unavailable right now.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader><CardTitle>{panel.title}</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        <form className="space-y-2" onSubmit={submit}>
          <label className="block text-xs font-medium">Question
            <input aria-label="Assistant question" className="mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm" maxLength={1000} value={question} onChange={(event) => setQuestion(event.target.value)} placeholder="Summarize this order or check a product" />
          </label>
          <div className="grid gap-2 sm:grid-cols-3">
            <label className="block text-xs font-medium">Order ID (optional)
              <input aria-label="Order ID" className="mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm" maxLength={64} value={orderId} onChange={(event) => setOrderId(event.target.value)} />
            </label>
            <label className="block text-xs font-medium">Product ID (optional)
              <input aria-label="Product ID" className="mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm" maxLength={64} value={productId} onChange={(event) => setProductId(event.target.value)} />
            </label>
            <label className="block text-xs font-medium">Customer ID (owner)
              <input aria-label="Customer ID" className="mt-1 w-full rounded-md border bg-background px-2 py-1.5 text-sm" maxLength={64} value={customerId} onChange={(event) => setCustomerId(event.target.value)} />
            </label>
          </div>
          <button type="submit" className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground" disabled={busy || question.trim().length < 3}>{busy ? "Thinking..." : "Ask assistant"}</button>
        </form>
        {error && <p role="alert" className="text-xs text-destructive">{error}</p>}
        {reply && (
          <div className="space-y-2 rounded-md border p-3 text-sm">
            {reply.answer ? <p>{reply.answer}</p> : <p role="status" className="text-muted-foreground">No answer was returned.</p>}
            {reply.sources.length > 0 && <ul className="space-y-1 text-xs">{reply.sources.map((source) => <li key={source.url}><a className="underline" href={source.url}>{source.label}</a></li>)}</ul>}
          </div>
        )}
        <p className="text-xs text-muted-foreground">Read-only: the assistant cannot change order, price or inventory state.</p>
      </CardContent>
    </Card>
  );
}
