"use client";

import { useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { ApiState } from "@/modules/merchant/api-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { giftCreditService, type GiftCredit } from "@/services/gift-credit.service";

type GiftConfirmation = { title: string; description: string; run: () => void };

export default function GiftCardsPage() {
  const [items, setItems] = useState<GiftCredit[]>([]);
  const [confirmation, setConfirmation] = useState<GiftConfirmation | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [filter, setFilter] = useState("");
  const [amount, setAmount] = useState("");
  const [owner, setOwner] = useState("");
  const [code, setCode] = useState("");

  const load = () => {
    setState("loading"); setError(undefined);
    void giftCreditService.list(filter.trim() ? { customer_id: filter.trim() } : undefined)
      .then((value) => { setItems(value); setState("ready"); })
      .catch((cause) => { setError(cause); setState("error"); });
  };
  useEffect(load, []);

  const issue = async () => {
    if (busy) return;
    const amountMinor = Number(amount);
    if (!Number.isInteger(amountMinor) || amountMinor <= 0) { setError(new Error("Enter a positive amount in minor units.")); return; }
    setBusy(true); setError(undefined); setNotice("");
    try {
      const created = await giftCreditService.issue({
        amount_minor: amountMinor,
        currency: "VND",
        ...(owner.trim() ? { owner_customer_id: owner.trim() } : {}),
        ...(code.trim() ? { code: code.trim().toUpperCase() } : {}),
      });
      setNotice(`Issued ${created.code} for ${created.balance_minor} ${created.currency}.`);
      setAmount(""); setOwner(""); setCode("");
      load();
    } catch (cause) { setError(cause); }
    finally { setBusy(false); }
  };

  const voidCredit = async (id: string, creditCode: string) => {
    if (busy) return;
    setBusy(true); setError(undefined); setNotice("");
    try {
      await giftCreditService.void(id);
      setNotice(`${creditCode} voided.`);
      load();
    } catch (cause) { setError(cause); }
    finally { setBusy(false); }
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Gift cards & store credit" description="Issue gift value, watch balances drain at checkout, void on fraud. Refunds land here via Refund to credit on the order." />
      {notice && <p role="status" className="rounded-md border border-primary/30 bg-primary/5 p-3 text-sm">{notice}</p>}
      <Card>
        <CardHeader><CardTitle>Issue credit</CardTitle></CardHeader>
        <CardContent className="flex flex-wrap items-end gap-3">
          <label className="text-sm">Amount (minor units)<Input aria-label="Amount in minor units" type="number" min="1" step="1" value={amount} onChange={(event) => setAmount(event.target.value)} className="mt-1 w-40" /></label>
          <label className="text-sm">Owner customer id (optional)<Input aria-label="Owner customer id" value={owner} onChange={(event) => setOwner(event.target.value)} placeholder="blank = bearer gift" className="mt-1 w-56" /></label>
          <label className="text-sm">Code (optional)<Input aria-label="Gift code" value={code} onChange={(event) => setCode(event.target.value)} placeholder="auto GC-…" className="mt-1 w-48" /></label>
          <Button disabled={busy} onClick={() => void issue()}>{busy ? "Issuing…" : "Issue"}</Button>
        </CardContent>
      </Card>
      <Card>
        <CardHeader><CardTitle>Outstanding value</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          <div className="flex flex-wrap items-end gap-3">
            <label className="text-sm">Filter by customer<Input aria-label="Filter by customer" value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="customer id" className="mt-1 w-56" /></label>
            <Button variant="outline" onClick={load}>Search</Button>
          </div>
          {state === "loading" && <p role="status" className="text-sm text-muted-foreground">Loading…</p>}
          {state === "error" && <ApiState error={error} onRetry={load} />}
          {state === "ready" && (items.length === 0 ? <p className="text-sm text-muted-foreground">No credit on record.</p> : (
            <div className="divide-y rounded-md border">
              {items.map((item) => (
                <div key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <p className="font-medium">{item.code} <Badge variant={item.state === "active" ? "default" : item.state === "exhausted" ? "outline" : "destructive"}>{item.state}</Badge> <span className="text-xs text-muted-foreground">{item.type}</span></p>
                    <p className="text-sm text-muted-foreground">{item.owner_customer_id ? `owner ${item.owner_customer_id}` : "bearer"} · balance {item.balance_minor.toLocaleString()} / {item.amount_minor.toLocaleString()} {item.currency}{item.expires_at ? ` · expires ${item.expires_at}` : ""}</p>
                  </div>
                  {item.state === "active" && <Button variant="outline" size="sm" disabled={busy} onClick={() => { if (busy) return; setConfirmation({ title: "Void gift card", description: `Void ${item.code}? Remaining balance is frozen and the code stops working.`, run: () => void voidCredit(item.id, item.code) }); }}>Void</Button>}
                </div>
              ))}
            </div>
          ))}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={confirmation !== null}
        title={confirmation?.title ?? ""}
        description={confirmation?.description}
        destructive
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          const action = confirmation;
          setConfirmation(null);
          action?.run();
        }}
      />
    </div>
  );
}
