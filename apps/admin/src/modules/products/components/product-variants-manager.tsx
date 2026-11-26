"use client";

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ApiState } from "@/modules/merchant/api-state";
import { fe1Service, type RecordValue } from "@/services/fe1.service";

type Props = { productId: string | number };

export function ProductVariantsManager({ productId }: Props) {
  const [items, setItems] = useState<RecordValue[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [sku, setSku] = useState("");
  const [price, setPrice] = useState("");
  const [notice, setNotice] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    fe1Service.variants(productId).then((value) => setItems(value)).catch(setError).finally(() => setLoading(false));
  }, [productId]);
  useEffect(() => { load(); }, [load]);

  async function submit() {
    const priceMinor = Number(price);
    if (!sku.trim() || !Number.isSafeInteger(priceMinor) || priceMinor < 0) return;
    setBusy(true);
    setNotice("");
    try {
      await fe1Service.createVariant(productId, { sku: sku.trim(), price_minor: priceMinor });
      setSku("");
      setPrice("");
      setNotice("Variant created.");
      load();
    } catch (error) {
      setError(error);
    } finally {
      setBusy(false);
    }
  }

  return <Card><CardHeader><CardTitle>Variants</CardTitle></CardHeader><CardContent className="space-y-4">
    <p className="text-sm text-muted-foreground">Each variant carries its own SKU and price. Option values attach through the options editor.</p>
    {loading ? <p role="status">Loading variants...</p> : items.length ? <ul className="space-y-2">{items.map((item, index) => (
      <li key={String(item.id ?? index)} className="flex items-center justify-between gap-3 rounded-md border p-2">
        <div className="min-w-0"><p className="truncate text-sm font-medium">{String(item.sku ?? item.id)}</p><p className="text-xs text-muted-foreground">{typeof item.price_minor === "number" ? `${item.price_minor} ${String(item.currency ?? "")}`.trim() : ""}</p></div>
      </li>
    ))}</ul> : <p className="text-sm text-muted-foreground">No variants yet.</p>}
    <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
      <Input aria-label="Variant SKU" value={sku} onChange={(event) => setSku(event.target.value)} disabled={busy} placeholder="SKU, e.g. TEE-M-NAT" />
      <Input aria-label="Variant price in minor units" value={price} onChange={(event) => setPrice(event.target.value)} disabled={busy} inputMode="numeric" placeholder="Price, e.g. 6800" />
      <Button disabled={busy || !sku.trim() || !price.trim()} onClick={() => void submit()}>Add variant</Button>
    </div>
    {notice ? <p role="status" className="text-sm text-emerald-600">{notice}</p> : null}
    {error ? <ApiState error={error} onRetry={load} /> : null}
  </CardContent></Card>;
}
