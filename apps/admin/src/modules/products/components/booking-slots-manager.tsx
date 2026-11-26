"use client";

import { useEffect, useState, useMemo } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ApiState } from "@/modules/merchant/api-state";
import api from "@/services/api";
import { idempotencyKey } from "@/services/merchant.service";

type Slot = { id: string; variant_id: string; starts_at: string; ends_at: string; capacity: number; price_minor?: number | null; currency?: string | null };
type Variant = { id: string; sku?: string; price_minor?: number };

export function BookingSlotsManager({ productId }: { productId: number }) {
  const [variants, setVariants] = useState<Variant[]>([]);
  const [variantId, setVariantId] = useState("");
  const [slots, setSlots] = useState<Slot[]>([]);
  const [startsAt, setStartsAt] = useState("");
  const [endsAt, setEndsAt] = useState("");
  const [capacity, setCapacity] = useState("10");
  const [price, setPrice] = useState("");
  const [bulkCsv, setBulkCsv] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<unknown>();
  const [notice, setNotice] = useState("");

  useEffect(() => {
    let cancelled = false;
    api.get<{ data?: Variant[] }>(`/api/admin/products/${encodeURIComponent(String(productId))}/variants`)
      .then((r) => {
        if (cancelled) return;
        const data = (r.data as { data?: Variant[] }).data ?? (r.data as unknown as Variant[]);
        const list = Array.isArray(data) ? data : [];
        setVariants(list);
        if (list[0]?.id && !variantId) setVariantId(list[0].id);
      })
      .catch(() => {});
    return () => { cancelled = true; };
  }, [productId]);

  const load = () => {
    if (!variantId.trim()) return;
    setLoading(true); setError(undefined);
    api.get<{ data?: Slot[] }>(`/api/admin/products/${encodeURIComponent(variantId.trim())}/booking-slots`)
      .then((r) => {
        const payload = r.data as { data?: Slot[] };
        const data = payload.data ?? (r.data as unknown as Slot[]);
        setSlots(Array.isArray(data) ? data : []);
      })
      .catch(setError)
      .finally(() => setLoading(false));
  };

  useEffect(() => { if (variantId) load(); }, [variantId]);

  const create = async () => {
    if (!variantId.trim() || !startsAt || !endsAt) { setNotice("Variant, start and end required"); return; }
    setLoading(true); setError(undefined); setNotice("");
    try {
      await api.post(`/api/admin/products/${encodeURIComponent(variantId.trim())}/booking-slots`, {
        starts_at: new Date(startsAt).toISOString(),
        ends_at: new Date(endsAt).toISOString(),
        capacity: Number(capacity),
        ...(price ? { price_minor: Number(price) } : {}),
      }, { idempotencyKey: idempotencyKey() });
      setNotice("Slot created");
      load();
    } catch (e) { setError(e); } finally { setLoading(false); }
  };

  const bulkCreate = async () => {
    const lines = bulkCsv.split("\n").map((l) => l.trim()).filter(Boolean);
    if (!variantId.trim() || lines.length === 0) { setNotice("Enter CSV lines: starts_at,ends_at,capacity,price_minor"); return; }
    setLoading(true); setError(undefined); setNotice("");
    let created = 0; let failed = 0;
    for (const line of lines) {
      const [s, e, c, p] = line.split(",").map((x) => x.trim());
      if (!s || !e || !c) { failed++; continue; }
      try {
        await api.post(`/api/admin/products/${encodeURIComponent(variantId.trim())}/booking-slots`, {
          starts_at: new Date(s).toISOString(),
          ends_at: new Date(e).toISOString(),
          capacity: Number(c),
          ...(p ? { price_minor: Number(p) } : {}),
        }, { idempotencyKey: idempotencyKey() });
        created++;
      } catch { failed++; }
    }
    setNotice(`Bulk: ${created} created, ${failed} failed`);
    load(); setLoading(false);
  };

  const grouped = useMemo(() => {
    const map: Record<string, Slot[]> = {};
    for (const s of slots) {
      const day = s.starts_at.slice(0, 10);
      (map[day] ??= []).push(s);
    }
    return Object.entries(map).sort(([a], [b]) => a.localeCompare(b));
  }, [slots]);

  return (
    <Card>
      <CardHeader><CardTitle>Booking slots</CardTitle><p className="text-xs text-muted-foreground">Product {productId} · {variants.length} variants · calendar view by day</p></CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-2 md:grid-cols-3">
          <div>
            <Label htmlFor="booking-variant">Variant</Label>
            {variants.length ? <select id="booking-variant" className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm" value={variantId} onChange={(e) => setVariantId(e.target.value)}><option value="">Select variant</option>{variants.map((v) => <option key={v.id} value={v.id}>{v.sku ?? v.id} · {v.price_minor ?? ""}</option>)}</select> : <Input id="booking-variant" value={variantId} onChange={(e) => setVariantId(e.target.value)} placeholder="variant id (product variant for booking)" />}
          </div>
          <div className="flex items-end"><Button variant="outline" onClick={load} disabled={!variantId.trim() || loading}>Reload</Button></div>
          <div className="flex items-end text-xs text-muted-foreground">{slots.length} slots total · booking type uses slot capacity for checkout</div>
        </div>
        {variantId && (
          <>
            <div className="grid gap-2 md:grid-cols-4">
              <div><Label>Starts at</Label><Input type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} /></div>
              <div><Label>Ends at</Label><Input type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} /></div>
              <div><Label>Capacity</Label><Input type="number" min="1" value={capacity} onChange={(e) => setCapacity(e.target.value)} /></div>
              <div><Label>Price minor (optional)</Label><Input type="number" min="0" value={price} onChange={(e) => setPrice(e.target.value)} /></div>
              <div className="md:col-span-4"><Button size="sm" onClick={create} disabled={loading || !variantId.trim()}>Create slot</Button></div>
            </div>
            <div className="space-y-2 rounded-md border p-3">
              <Label>Bulk CSV import (starts_at,ends_at,capacity,price_minor)</Label>
              <Textarea placeholder={'2026-10-06T09:00,2026-10-06T10:00,10,50000\n2026-10-06T10:00,2026-10-06T11:00,8'} value={bulkCsv} onChange={(e) => setBulkCsv(e.target.value)} rows={4} />
              <Button size="sm" variant="outline" onClick={bulkCreate} disabled={loading || !variantId.trim()}>Import CSV</Button>
              <p className="text-xs text-muted-foreground">Each line creates one slot; overlap is rejected (422).</p>
            </div>
          </>
        )}
        {loading ? <p role="status" className="text-sm text-muted-foreground">Loading slots…</p> : error ? <ApiState error={error} onRetry={load} /> : grouped.length ? (
          <div className="space-y-3">{grouped.map(([day, daySlots]) => <div key={day} className="rounded-md border"><div className="bg-muted px-3 py-1 text-xs font-medium">{day} · {daySlots.length} slots</div><ul className="divide-y">{daySlots.map((s) => <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"><span className="font-medium">{new Date(s.starts_at).toLocaleTimeString()} → {new Date(s.ends_at).toLocaleTimeString()}</span><span className="text-muted-foreground">capacity {s.capacity} {s.price_minor != null ? `· ${s.price_minor} ${s.currency ?? ""}` : ""}</span><span className="text-xs text-muted-foreground">{s.id.slice(0, 8)}</span></li>)}</ul></div>)}</div>
        ) : variantId ? <p className="text-sm text-muted-foreground">No slots for this variant. Create one or bulk import.</p> : <p className="text-sm text-muted-foreground">Select variant to manage booking slots. Product must be type=booking.</p>}
        {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}
      </CardContent>
    </Card>
  );
}
