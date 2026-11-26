"use client";

import { useEffect, useRef, useState } from "react";
import { formatMinor } from "@/lib/utils";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { ApiState } from "@/modules/merchant/api-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Label } from "@/components/ui/label";
import api from "@/services/api";
import { fe1Service, type RecordValue } from "@/services/fe1.service";
import { useAuthStore } from "@/store/auth.store";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { idempotencyKey } from "@/services/merchant.service";
import { PriceRulesPanel } from "@/modules/promotions/price-rules-panel";

type Tab = "catalog" | "lifecycle" | "promotions" | "operations" | "sites" | "events" | "vendors" | "audit" | "jobs";

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : "The request was not accepted.";
}

function ListCard({ title, loading, error, items, empty, onRetry, render }: {
  title: string;
  loading: boolean;
  error: unknown;
  items: RecordValue[];
  empty: string;
  onRetry: () => void;
  render: (item: RecordValue, index: number) => React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader><CardTitle>{title}</CardTitle></CardHeader>
      <CardContent>
        {loading ? <p role="status">Loading...</p>
          : error ? <ApiState error={error} onRetry={onRetry} />
          : items.length ? <div className="space-y-2">{items.map(render)}</div>
          : <p className="text-sm text-muted-foreground">{empty}</p>}
      </CardContent>
    </Card>
  );
}

function CatalogTab() {
  const [types, setTypes] = useState<RecordValue[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const load = () => { setState("loading"); void fe1Service.productTypes().then((value) => { setTypes(value); setState("ready"); }).catch(() => setState("error")); };
  useEffect(load, []);
  return <div className="grid gap-4 lg:grid-cols-2">
    <Card><CardHeader><CardTitle>Product types</CardTitle></CardHeader><CardContent>
      {state === "loading" && <p role="status">Loading product types...</p>}
      {state === "error" && <ApiState error={new Error("Product types failed to load")} onRetry={load} />}
      {state === "ready" && (types.length ? <ul className="space-y-2">{types.map((item, index) => <li key={String(item.id ?? index)} className="rounded-md border p-3">{String(item.name ?? item.code ?? "Unnamed type")}</li>)}</ul> : <p className="text-sm text-muted-foreground">No product types are configured.</p>)}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Catalog taxonomy</CardTitle></CardHeader><CardContent className="space-y-3">
      <TaxonomyList path="/api/v2/catalog/brands" title="Brands" />
      <TaxonomyList path="/api/v2/catalog/categories" title="Categories" />
      <TaxonomyList path="/api/v2/catalog/tags" title="Tags" />
    </CardContent></Card>
  </div>;
}

function TaxonomyList({ path, title }: { path: string; title: string }) {
  const [items, setItems] = useState<RecordValue[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const load = () => {
    setState("loading");
    api.get<{ data?: RecordValue[] }>(path).then((res) => { setItems(res.data.data ?? []); setState("ready"); }).catch(() => setState("error"));
  };
  useEffect(load, [path]);
  if (state === "loading") return <p role="status" className="text-sm text-muted-foreground">Loading {title.toLowerCase()}...</p>;
  if (state === "error") return <ApiState error={new Error(`${title} failed to load`)} onRetry={load} />;
  return (
    <div>
      <p className="text-sm font-medium">{title} ({items.length})</p>
      {items.length ? (
        <ul className="mt-1 flex flex-wrap gap-2">{items.slice(0, 20).map((item, i) => <li key={String(item.id ?? i)}><Badge variant="outline">{String(item.name ?? item.code ?? item.id ?? i)}</Badge></li>)}</ul>
      ) : <p className="text-sm text-muted-foreground">No {title.toLowerCase()} returned.</p>}
    </div>
  );
}

function PromotionsTab() {
  const user = useAuthStore((state) => state.user);
  const canWrite = canOperateMerchant(user, "coupons");
  const [promotionView, setPromotionView] = useState<"coupons" | "price_rules">("coupons");
  const [coupons, setCoupons] = useState<RecordValue[]>([]);
  const [audit, setAudit] = useState<RecordValue[]>([]);
  const [code, setCode] = useState("");
  const [type, setType] = useState<"fixed" | "percent" | "buy_x_get_y">("percent");
  const [value, setValue] = useState("10");
  const [buyVariants, setBuyVariants] = useState("");
  const [buyQty, setBuyQty] = useState("1");
  const [getVariants, setGetVariants] = useState("");
  const [getQty, setGetQty] = useState("1");
  const [maxSets, setMaxSets] = useState("");
  const [currency, setCurrency] = useState("VND");
  const [minSpend, setMinSpend] = useState("");
  const [maxDiscount, setMaxDiscount] = useState("");
  const [usageLimit, setUsageLimit] = useState("");
  const [perCustomerLimit, setPerCustomerLimit] = useState("");
  const [exclusive, setExclusive] = useState(false);
  const [scope, setScope] = useState<"cart" | "item">("cart");
  const [productIds, setProductIds] = useState("");
  const [startsAt, setStartsAt] = useState("");
  const [endsAt, setEndsAt] = useState("");
  const [rateWindow, setRateWindow] = useState("");
  const [rateAttempts, setRateAttempts] = useState("");
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [advancedJson, setAdvancedJson] = useState("");
  const [bulkPattern, setBulkPattern] = useState("WELCOME{0000}");
  const [bulkCount, setBulkCount] = useState("3");
  const [bulkStart, setBulkStart] = useState("1");
  const [bulkResult, setBulkResult] = useState<RecordValue | null>(null);
  const [selectedVersions, setSelectedVersions] = useState<RecordValue[]>([]);
  const [selectedUsage, setSelectedUsage] = useState<RecordValue | null>(null);
  const [selectedCoupon, setSelectedCoupon] = useState<string>("");
  const [previewSubtotal, setPreviewSubtotal] = useState("100000");
  const [preview, setPreview] = useState<RecordValue>();
  const [error, setError] = useState<unknown>();
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const load = () => { setLoading(true); setError(undefined); void fe1Service.coupons().then((raw) => {
    if (Array.isArray(raw)) setCoupons(raw);
    else if (raw && typeof raw === "object" && Array.isArray((raw as RecordValue).data)) setCoupons((raw as RecordValue).data as RecordValue[]);
    else setCoupons([]);
  }).catch(setError).finally(() => setLoading(false)); };
  const loadAudit = () => { void api.get<{ data?: RecordValue[] }>("/api/admin/promotion-decisions/audit").then((res) => setAudit(res.data.data ?? [])).catch(() => setAudit([])); };
  useEffect(load, []);
  useEffect(loadAudit, []);
  const buildRules = () => {
    if (advancedOpen && advancedJson.trim()) {
      try { const parsed = JSON.parse(advancedJson); if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) return parsed as Record<string, unknown>; } catch { /* fallback to form */ }
    }
    const out: Record<string, unknown> = { type };
    if (type === "buy_x_get_y") {
      out.buy_variant_ids = buyVariants.split(",").map((s) => s.trim()).filter(Boolean);
      out.buy_qty = Math.max(1, Number(buyQty) || 1);
      out.get_variant_ids = getVariants.split(",").map((s) => s.trim()).filter(Boolean);
      out.get_qty = Math.max(1, Number(getQty) || 1);
      if (maxSets.trim() !== "") out.max_sets = Math.max(0, Number(maxSets) || 0);
    } else {
      out.value = Number(value) || 0;
    }
    if (currency.trim()) out.currency = currency.trim().toUpperCase();
    if (minSpend.trim() && Number(minSpend) >= 0) out.min_spend_minor = Number(minSpend);
    if (maxDiscount.trim() && Number(maxDiscount) >= 0) out.max_discount_minor = Number(maxDiscount);
    if (usageLimit.trim() && Number(usageLimit) >= 0) out.usage_limit = Number(usageLimit);
    if (perCustomerLimit.trim() && Number(perCustomerLimit) >= 0) out.per_customer_limit = Number(perCustomerLimit);
    if (exclusive) out.exclusive = true;
    if (scope !== "cart") {
      out.scope = scope;
      if (productIds.trim()) out.product_ids = productIds.split(",").map((s) => s.trim()).filter(Boolean);
    }
    if (startsAt) out.starts_at = new Date(startsAt).toISOString();
    if (endsAt) out.ends_at = new Date(endsAt).toISOString();
    if (rateWindow.trim() && Number(rateWindow) >= 0) out.rate_limit_window_seconds = Number(rateWindow);
    if (rateAttempts.trim() && Number(rateAttempts) >= 0) out.rate_limit_attempts = Number(rateAttempts);
    return out;
  };
  const rulesPreview = (() => { try { return JSON.stringify(buildRules(), null, 2); } catch { return "{}"; } })();
  const create = async () => {
    if (!canWrite || lock.current || !code.trim()) return;
    if (type === "buy_x_get_y") {
      if (!buyVariants.trim() || !getVariants.trim()) { setError(new Error("Combo needs buy variant IDs and gift variant IDs")); return; }
    } else if (!value.trim() || Number(value) < 0 || (type !== "fixed" && Number(value) > 100)) { setError(new Error("Value is invalid (percent must be 0-100)")); return; }
    lock.current = true; setBusy(true); setError(undefined);
    try {
      const rulesObj = buildRules();
      await fe1Service.createCoupon({ code: code.trim(), rules: JSON.stringify(rulesObj) });
      setCode(""); setValue("10"); setAdvancedJson(""); load();
    } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); }
  };
  const disable = async (id: string) => { if (!canWrite || lock.current) return; lock.current = true; setBusy(true); try { await fe1Service.disableCoupon(id); load(); } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); } };
  const enable = async (id: string) => { if (!canWrite || lock.current) return; lock.current = true; setBusy(true); try { await fe1Service.enableCoupon(id); load(); } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); } };
  const runPreview = async () => { if (!code.trim() || busy) return; setBusy(true); setError(undefined); try { setPreview(await fe1Service.previewPromotion({ code: code.trim(), subtotal_minor: Number(previewSubtotal) })); } catch (cause) { setError(cause); } finally { setBusy(false); } };
  const runBulk = async () => {
    if (!canWrite || lock.current || !bulkPattern.trim() || !bulkCount.trim()) return;
    const cnt = Number(bulkCount); if (!Number.isInteger(cnt) || cnt < 1 || cnt > 1000) { setError(new Error("Count must be 1-1000")); return; }
    lock.current = true; setBusy(true); setError(undefined); setBulkResult(null);
    try {
      const rulesObj = buildRules();
      const res = await fe1Service.bulkCoupons({ pattern: bulkPattern.trim(), count: cnt, start: Number(bulkStart) || 0, rules: JSON.stringify(rulesObj) });
      setBulkResult(res as RecordValue); load();
    } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); }
  };
  const showVersions = async (id: string) => { setSelectedCoupon(id); setSelectedVersions([]); setSelectedUsage(null); setBusy(true); try { setSelectedVersions(await fe1Service.couponVersions(id)); } catch (cause) { setError(cause); } finally { setBusy(false); } };
  const [pnl, setPnl] = useState<RecordValue[]>([]);
  const [pnlState, setPnlState] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const loadPnl = () => { setPnlState("loading"); void fe1Service.couponPnl().then((rows) => { setPnl(Array.isArray(rows) ? rows : []); setPnlState("ready"); }).catch(() => setPnlState("error")); };
  const showUsage = async (id: string) => { setSelectedCoupon(id); setSelectedUsage(null); setSelectedVersions([]); setBusy(true); try { const u = await fe1Service.couponUsage(id); setSelectedUsage(u as RecordValue); } catch (cause) { setError(cause); } finally { setBusy(false); } };
  return <div className="space-y-4">
    <div role="tablist" aria-label="Promotion sections" className="flex gap-2 border-b pb-2">
      <button type="button" role="tab" aria-selected={promotionView === "coupons"} onClick={() => setPromotionView("coupons")} className={`rounded-md px-3 py-2 text-sm font-medium ${promotionView === "coupons" ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}>Coupons</button>
      <button type="button" role="tab" aria-selected={promotionView === "price_rules"} onClick={() => setPromotionView("price_rules")} className={`rounded-md px-3 py-2 text-sm font-medium ${promotionView === "price_rules" ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}>Price rules</button>
    </div>
    {promotionView === "price_rules" ? <PriceRulesPanel /> : <div className="grid gap-4 lg:grid-cols-[1.15fr_1fr]">
     <Card><CardHeader><CardTitle>Coupon form builder</CardTitle><p className="text-xs text-muted-foreground">Structured fields → validated JSON · no raw textarea required · stacking & limits handled by backend</p></CardHeader><CardContent className="space-y-3">
       <div className="grid gap-3 md:grid-cols-2">
         <div><Label htmlFor="coupon-code">Code *</Label><Input id="coupon-code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} disabled={!canWrite || busy} placeholder="WELCOME10" /></div>
          <div><Label htmlFor="coupon-type">Type</Label><select id="coupon-type" value={type} onChange={(e) => setType(e.target.value as never)} disabled={!canWrite || busy} className="h-9 w-full rounded-md border bg-background px-3 text-sm"><option value="percent">Percent (0-100)</option><option value="fixed">Fixed (minor)</option><option value="buy_x_get_y">Buy X get Y</option></select></div>
          {type === "buy_x_get_y" ? <>
            <div><Label htmlFor="combo-buy">Buy variant IDs (comma-separated) *</Label><Input id="combo-buy" value={buyVariants} onChange={(e) => setBuyVariants(e.target.value)} disabled={!canWrite || busy} placeholder="v1, v2" /></div>
            <div><Label htmlFor="combo-buyqty">Buy qty (X)</Label><Input id="combo-buyqty" type="number" min="1" value={buyQty} onChange={(e) => setBuyQty(e.target.value)} disabled={!canWrite || busy} /></div>
            <div><Label htmlFor="combo-get">Gift variant IDs (comma-separated) *</Label><Input id="combo-get" value={getVariants} onChange={(e) => setGetVariants(e.target.value)} disabled={!canWrite || busy} placeholder="v3" /></div>
            <div><Label htmlFor="combo-getqty">Gift qty (Y)</Label><Input id="combo-getqty" type="number" min="1" value={getQty} onChange={(e) => setGetQty(e.target.value)} disabled={!canWrite || busy} /></div>
            <div><Label htmlFor="combo-maxsets">Max sets (0 = unlimited)</Label><Input id="combo-maxsets" type="number" min="0" value={maxSets} onChange={(e) => setMaxSets(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 2" /></div>
          </> : <div><Label htmlFor="coupon-value">Value *</Label><Input id="coupon-value" type="number" min="0" max={type === "percent" ? 100 : undefined} value={value} onChange={(e) => setValue(e.target.value)} disabled={!canWrite || busy} /></div>}
         <div><Label htmlFor="coupon-currency">Currency</Label><Input id="coupon-currency" value={currency} onChange={(e) => setCurrency(e.target.value)} disabled={!canWrite || busy} placeholder="VND" maxLength={3} /></div>
         <div><Label htmlFor="coupon-min">Min spend (minor)</Label><Input id="coupon-min" type="number" min="0" value={minSpend} onChange={(e) => setMinSpend(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 200000" /></div>
         <div><Label htmlFor="coupon-max">Max discount (minor)</Label><Input id="coupon-max" type="number" min="0" value={maxDiscount} onChange={(e) => setMaxDiscount(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 50000" /></div>
         <div><Label htmlFor="coupon-limit">Usage limit (global)</Label><Input id="coupon-limit" type="number" min="0" value={usageLimit} onChange={(e) => setUsageLimit(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 100" /></div>
         <div><Label htmlFor="coupon-per">Per-customer limit</Label><Input id="coupon-per" type="number" min="0" value={perCustomerLimit} onChange={(e) => setPerCustomerLimit(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 1" /></div>
         <div><Label htmlFor="coupon-scope">Scope</Label><select id="coupon-scope" value={scope} onChange={(e) => setScope(e.target.value as never)} disabled={!canWrite || busy} className="h-9 w-full rounded-md border bg-background px-3 text-sm"><option value="cart">Cart</option><option value="item">Item (filter products)</option></select></div>
         <div className="flex items-end gap-2"><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={exclusive} onChange={(e) => setExclusive(e.target.checked)} disabled={!canWrite || busy} /> Exclusive (no stacking)</label></div>
         {scope === "item" && <div className="md:col-span-2"><Label htmlFor="coupon-products">Product IDs (comma-separated, for item scope)</Label><Input id="coupon-products" value={productIds} onChange={(e) => setProductIds(e.target.value)} disabled={!canWrite || busy} placeholder="prod_1, prod_2" /></div>}
         <div><Label htmlFor="coupon-start">Starts at</Label><Input id="coupon-start" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} disabled={!canWrite || busy} /></div>
         <div><Label htmlFor="coupon-end">Ends at</Label><Input id="coupon-end" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} disabled={!canWrite || busy} /></div>
         <div><Label htmlFor="coupon-window">Rate window (sec)</Label><Input id="coupon-window" type="number" min="0" value={rateWindow} onChange={(e) => setRateWindow(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 3600" /></div>
         <div><Label htmlFor="coupon-attempts">Rate attempts</Label><Input id="coupon-attempts" type="number" min="0" value={rateAttempts} onChange={(e) => setRateAttempts(e.target.value)} disabled={!canWrite || busy} placeholder="e.g. 5" /></div>
       </div>
       <div className="rounded-md bg-muted p-2 text-xs"><p className="font-medium">JSON preview (sent to backend)</p><pre className="max-h-28 overflow-auto">{rulesPreview}</pre></div>
       <div className="flex items-center gap-2"><Button onClick={create} disabled={!canWrite || busy || !code.trim()}>{busy ? "Saving..." : "Create coupon"}</Button><Button variant="outline" onClick={() => setAdvancedOpen((v) => !v)} disabled={busy}>{advancedOpen ? "Hide advanced JSON" : "Advanced JSON"}</Button></div>
       {advancedOpen && <div><Label htmlFor="coupon-adv">Advanced rules JSON (overrides form when valid)</Label><Textarea id="coupon-adv" value={advancedJson} onChange={(e) => setAdvancedJson(e.target.value)} disabled={!canWrite || busy} placeholder='{"type":"percent","value":10,"exclusive":true}' rows={4} /><p className="text-xs text-muted-foreground">Power users: paste full rules object; validation still runs server-side. Keep presentation-only.</p></div>}
               <div className="border rounded-md p-3 space-y-2 bg-muted/30"><h4 className="font-medium text-sm">Bulk create (pattern)</h4><p className="text-xs text-muted-foreground">Pattern needs single {"{0000}"} placeholder, e.g. WELCOME{"{0000}"} to WELCOME0001</p><div className="grid gap-2 md:grid-cols-3"><div><Label htmlFor="bulk-pattern">Pattern</Label><Input id="bulk-pattern" value={bulkPattern} onChange={(e) => setBulkPattern(e.target.value)} disabled={!canWrite || busy} /></div><div><Label htmlFor="bulk-count">Count (1-1000)</Label><Input id="bulk-count" type="number" min="1" max="1000" value={bulkCount} onChange={(e) => setBulkCount(e.target.value)} disabled={!canWrite || busy} /></div><div><Label htmlFor="bulk-start">Start</Label><Input id="bulk-start" type="number" min="0" value={bulkStart} onChange={(e) => setBulkStart(e.target.value)} disabled={!canWrite || busy} /></div></div><Button variant="outline" size="sm" onClick={runBulk} disabled={!canWrite || busy || !bulkPattern.trim()}>Create bulk ({bulkCount} codes)</Button>{bulkResult && <pre className="max-h-28 overflow-auto rounded-md bg-muted p-2 text-xs">{JSON.stringify(bulkResult, null, 2)}</pre>}</div>
        <div className="border-t pt-3"><Label htmlFor="coupon-preview-subtotal">Preview subtotal (minor)</Label><Input id="coupon-preview-subtotal" type="number" min="0" value={previewSubtotal} onChange={(e) => setPreviewSubtotal(e.target.value)} disabled={busy} /><Button className="mt-2" variant="outline" onClick={runPreview} disabled={busy || !code.trim()}>Preview server discount</Button>{preview && <pre className="mt-2 max-h-32 overflow-auto rounded-md bg-muted p-2 text-xs">{JSON.stringify(preview, null, 2)}</pre>}</div>{!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot change coupons.</p>}{error ? <ApiState error={error} onRetry={load} /> : null}</CardContent></Card>
    <div className="space-y-4">
      <Card><CardHeader><CardTitle>Coupons</CardTitle></CardHeader><CardContent>{loading ? <p role="status">Loading coupons...</p> : error ? <ApiState error={error} onRetry={load} /> : coupons.length ? <div className="space-y-2">{coupons.map((coupon, index) => { const id = String(coupon.id ?? coupon.code ?? index); const rulesRaw = String(coupon.rules ?? ""); let summary = ""; try { const r = JSON.parse(rulesRaw); const head = r.type === "buy_x_get_y" ? `buy ${r.buy_qty ?? 1} get ${r.get_qty ?? 1}` : `${r.type ?? "?"} ${r.value ?? ""}`; summary = `${head} · ${r.exclusive ? "exclusive" : "stackable"}${r.usage_limit ? ` · limit ${r.usage_limit}` : ""}`; } catch { summary = rulesRaw.slice(0, 60); } return           <div key={id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"><div><span className="font-medium">{String(coupon.code ?? id)}</span><p className="text-xs text-muted-foreground">{summary || String(coupon.status ?? "active")}</p></div><div className="flex flex-wrap items-center gap-2"><Badge>{String(coupon.state ?? coupon.status ?? "active")}</Badge><Button size="sm" variant="outline" disabled={!canWrite || busy} onClick={() => showVersions(String(coupon.id))}>Versions</Button><Button size="sm" variant="outline" disabled={!canWrite || busy} onClick={() => showUsage(String(coupon.id))}>Usage</Button><Button size="sm" variant="outline" disabled={!canWrite || busy || (coupon.state ?? coupon.status) === "disabled"} onClick={() => disable(String(coupon.id))}>Disable</Button><Button size="sm" variant="outline" disabled={!canWrite || busy || (coupon.state ?? coupon.status) === "active"} onClick={() => enable(String(coupon.id))}>Enable</Button></div></div>; })}</div> : <p className="text-sm text-muted-foreground">No coupons found.</p>}</CardContent></Card>
      <Card><CardHeader><CardTitle>Coupon P&amp;L</CardTitle></CardHeader><CardContent>{pnlState === "idle" ? <Button size="sm" variant="outline" onClick={loadPnl} disabled={busy}>Load P&amp;L</Button> : pnlState === "loading" ? <p role="status">Loading P&amp;L...</p> : pnlState === "error" ? <p role="alert" className="text-sm text-muted-foreground">P&amp;L unavailable. <Button size="sm" variant="outline" onClick={loadPnl}>Retry</Button></p> : pnl.length ? <div className="space-y-2">{pnl.map((row, i) => { const currency = String(row.currency ?? "VND"); const money = (v: unknown) => formatMinor(Number(v ?? 0), currency); return <div key={String(row.coupon_id ?? i)} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm"><div><span className="font-medium">{String(row.code ?? row.coupon_id)}</span><p className="text-xs text-muted-foreground">{String(row.uses ?? 0)} uses · {String(row.orders ?? 0)} orders</p></div><div className="text-right"><p>Revenue {money(row.revenue_minor)}</p><p className="text-xs text-muted-foreground">Discount -{money(row.discount_minor)} · Net {money(row.net_minor)}</p></div></div>; })}</div> : <p className="text-sm text-muted-foreground">No coupon activity yet.</p>}</CardContent></Card>
      {selectedCoupon && (selectedVersions.length > 0 || selectedUsage) && <Card><CardHeader><CardTitle>Coupon detail -- {selectedCoupon}</CardTitle></CardHeader><CardContent className="space-y-3">{selectedVersions.length > 0 && <div><h4 className="font-medium text-sm">Versions ({selectedVersions.length})</h4><div className="space-y-1">{selectedVersions.map((v,i) => <pre key={String(v.id ?? i)} className="rounded-md bg-muted p-2 text-xs">{JSON.stringify(v, null, 2)}</pre>)}</div></div>}{selectedUsage && <div><h4 className="font-medium text-sm">Usage analytics</h4><pre className="rounded-md bg-muted p-2 text-xs">{JSON.stringify(selectedUsage, null, 2)}</pre></div>}</CardContent></Card>}<Card><CardHeader><CardTitle>Promotion decision audit</CardTitle></CardHeader><CardContent>{audit.length ? <div className="space-y-2">{audit.slice(0, 20).map((row, i) => <div key={String(row.id ?? i)} className="rounded-md border p-3 text-sm"><p className="font-medium">{String(row.code ?? row.promotion ?? "Decision")}</p><p className="text-xs text-muted-foreground">{String(row.created_at ?? row.decided_at ?? "")}</p></div>)}</div> : <p className="text-sm text-muted-foreground">No promotion decisions recorded.</p>}</CardContent></Card>
    </div></div>}
  </div>;
}

function LifecycleTab() {
  const user = useAuthStore((state) => state.user);
  const canWrite = canOperateMerchant(user, "products");
  const [productId, setProductId] = useState("");
  const [status, setStatus] = useState("draft");
  const [approvalStatus, setApprovalStatus] = useState<"pending" | "approved" | "rejected">("pending");
  const [version, setVersion] = useState("");
  const [versions, setVersions] = useState<RecordValue[]>([]);
  const [pageId, setPageId] = useState("");
  const [publishAt, setPublishAt] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>();
  const [message, setMessage] = useState("");
  const run = async (action: () => Promise<unknown>) => {
    if (!canWrite || busy) return;
    setBusy(true); setError(undefined); setMessage("");
    try { await action(); setMessage("Accepted. The backend is authoritative; reload before the next edit."); } catch (cause) { setError(cause); } finally { setBusy(false); }
  };
  const loadVersions = async () => {
    if (!productId.trim() || busy) return;
    setBusy(true); setError(undefined);
    try {
      const res = await api.get<{ data?: RecordValue[] }>(`/api/v2/admin/products/${encodeURIComponent(productId.trim())}/versions`);
      setVersions(res.data.data ?? []);
    } catch (cause) { setError(cause); } finally { setBusy(false); }
  };
  return <div className="grid gap-4 lg:grid-cols-2">
    <Card><CardHeader><CardTitle>Product lifecycle</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="lifecycle-product" className="text-sm font-medium">Product ID</label><Input id="lifecycle-product" value={productId} onChange={(event) => setProductId(event.target.value)} placeholder="Product ID" />
      <label htmlFor="lifecycle-status" className="text-sm font-medium">Status</label><select id="lifecycle-status" value={status} onChange={(event) => setStatus(event.target.value)} className="h-9 w-full rounded-md border bg-background px-3 text-sm"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select>
      <label htmlFor="lifecycle-version" className="text-sm font-medium">Version (for conflict detection)</label><Input id="lifecycle-version" inputMode="numeric" value={version} onChange={(event) => setVersion(event.target.value)} placeholder="Optional version" />
      <Button disabled={!canWrite || busy || !productId.trim()} onClick={() => run(() => fe1Service.updateProductLifecycle(productId.trim(), { status, ...(version ? { version: Number(version) } : {}) }))}>{busy ? "Saving..." : "Update lifecycle"}</Button>
      <label htmlFor="product-approval" className="text-sm font-medium">Approval status</label><select id="product-approval" value={approvalStatus} onChange={(event) => setApprovalStatus(event.target.value as typeof approvalStatus)} className="h-9 w-full rounded-md border bg-background px-3 text-sm"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select>
      <Button variant="outline" disabled={!canWrite || busy || !productId.trim()} onClick={() => run(() => fe1Service.approveProduct(productId.trim(), { approval_status: approvalStatus, ...(version ? { version: Number(version) } : {}) }))}>Save approval</Button>
      <Button variant="outline" disabled={busy || !productId.trim()} onClick={loadVersions}>Load versions</Button>
      {versions.length ? <ul className="space-y-1">{versions.slice(0, 10).map((v, i) => <li key={String(v.id ?? i)} className="rounded border p-2 text-xs">v{String(v.version ?? i)} · {String(v.status ?? v.state ?? "")}</li>)}</ul> : null}
      {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot change lifecycle state.</p>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Page publishing</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="publish-page" className="text-sm font-medium">Page ID</label><Input id="publish-page" value={pageId} onChange={(event) => setPageId(event.target.value)} placeholder="Page ID" />
      <label htmlFor="publish-at" className="text-sm font-medium">Schedule time (optional)</label><Input id="publish-at" type="datetime-local" value={publishAt} onChange={(event) => setPublishAt(event.target.value)} />
      <p className="text-xs text-muted-foreground">Scheduling is passed only when the publish route accepts it. If the backend rejects it, no local publish state is assumed.</p>
      <Button disabled={!canWrite || busy || !pageId.trim()} onClick={() => run(() => fe1Service.publishPage(pageId.trim(), publishAt ? { publish_at: new Date(publishAt).toISOString() } : {}))}>{busy ? "Publishing..." : publishAt ? "Schedule publish" : "Publish page"}</Button>
      {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot change lifecycle state.</p>}
    </CardContent></Card>
    {message && <p role="status" className="text-sm text-emerald-700 lg:col-span-2">{message}</p>}
    {error ? <div className="lg:col-span-2"><ApiState error={error} onRetry={() => undefined} /></div> : null}
  </div>;
}

function SitesTab() {
  const [stores, setStores] = useState<RecordValue[]>([]);
  const [selected, setSelected] = useState("");
  const [channels, setChannels] = useState<RecordValue[]>([]);
  const [error, setError] = useState<unknown>();
  const [loading, setLoading] = useState(true);
  const load = () => { setLoading(true); void fe1Service.stores().then((value) => { setStores(value); setSelected((current) => current || String(value[0]?.id ?? "")); }).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);
  useEffect(() => { if (!selected) return; void fe1Service.storeChannels(selected).then(setChannels).catch(setError); }, [selected]);
  return <div className="grid gap-4 lg:grid-cols-2"><Card><CardHeader><CardTitle>Sites and stores</CardTitle></CardHeader><CardContent>{loading ? <p role="status">Loading sites...</p> : error ? <ApiState error={error} onRetry={load} /> : stores.length ? <div className="space-y-2">{stores.map((store, index) => { const id = String(store.id ?? index); return <button type="button" key={id} onClick={() => setSelected(id)} className={`block w-full rounded-md border p-3 text-left ${selected === id ? "border-primary bg-muted" : ""}`} aria-pressed={selected === id}><span className="font-medium">{String(store.name ?? store.code ?? id)}</span><span className="ml-2 text-xs text-muted-foreground">{String(store.status ?? "active")}</span></button>; })}</div> : <p className="text-sm text-muted-foreground">No stores found.</p>}</CardContent></Card><Card><CardHeader><CardTitle>Channels</CardTitle></CardHeader><CardContent>{selected ? channels.length ? <ul className="space-y-2">{channels.map((channel, index) => <li className="rounded-md border p-3" key={String(channel.id ?? index)}>{String(channel.name ?? channel.code ?? channel.id ?? index)}</li>)}</ul> : <p className="text-sm text-muted-foreground">No channels returned for this site.</p> : <p className="text-sm text-muted-foreground">Select a site to load channels.</p>}</CardContent></Card></div>;
}

function EventsTab() {
  const [events, setEvents] = useState<RecordValue[]>([]); const [loading, setLoading] = useState(true); const [error, setError] = useState<unknown>();
  const load = () => { setLoading(true); void fe1Service.events({ limit: 50 }).then(setEvents).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);
  return <div className="grid gap-4 lg:grid-cols-2"><Card><CardHeader><CardTitle>Audit and event view</CardTitle></CardHeader><CardContent>{loading ? <p role="status">Loading events...</p> : error ? <ApiState error={error} onRetry={load} /> : events.length ? <div className="space-y-2">{events.map((event, index) => <div className="rounded-md border p-3" key={String(event.id ?? event.event_id ?? index)}><p className="font-medium">{String(event.name ?? event.event ?? "Unnamed event")}</p><p className="text-xs text-muted-foreground">{String(event.created_at ?? event.occurred_at ?? "Time unavailable")}</p></div>)}</div> : <p className="text-sm text-muted-foreground">No events found.</p>}</CardContent></Card>
  <NotificationEventsCard /></div>;
}

function NotificationEventsCard() {
  const [items, setItems] = useState<RecordValue[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const load = () => {
    setLoading(true);
    api.get<{ data?: RecordValue[] }>("/api/v2/admin/notification-events", { params: { limit: 50 } })
      .then((res) => setItems(res.data.data ?? []))
      .catch(setError)
      .finally(() => setLoading(false));
  };
  useEffect(load, []);
  return <ListCard title="Notification events" loading={loading} error={error} items={items} empty="No notification events found." onRetry={load} render={(row, i) => <div key={String(row.id ?? i)} className="rounded-md border p-3 text-sm"><p className="font-medium">{String(row.event_name ?? row.event ?? "Event")}</p><p className="text-xs text-muted-foreground">{String(row.state ?? row.status ?? "")} · {String(row.created_at ?? "")}</p></div>} />;
}

function OperationsTab() {
  const [dataset, setDataset] = useState("products"); const [jobId, setJobId] = useState(""); const [job, setJob] = useState<RecordValue>(); const [message, setMessage] = useState(""); const [busy, setBusy] = useState(false); const [error, setError] = useState<unknown>();
  const run = async (action: () => Promise<RecordValue | undefined>) => { if (busy) return; setBusy(true); setError(undefined); setMessage(""); try { const result = await action(); setMessage(`Accepted${result?.id ? `: ${String(result.id)}` : ""}. Track status using the returned operation id.`); } catch (cause) { setError(cause); } finally { setBusy(false); } };
  return <div className="grid gap-4 lg:grid-cols-2">
    <Card><CardHeader><CardTitle>Import catalog</CardTitle></CardHeader><CardContent className="space-y-3"><p className="text-sm text-muted-foreground">The endpoint accepts a source reference, not a browser file upload. Validate first, then run the import.</p><div className="flex flex-wrap gap-2"><Button disabled={busy} onClick={() => run(() => fe1Service.importCatalog({ format: "csv", source: "admin-upload", validate_only: true }))}>Validate CSV import</Button><Button variant="outline" disabled={busy} onClick={() => run(() => fe1Service.importCatalog({ format: "csv", source: "admin-upload" }))}>Run import</Button></div></CardContent></Card>
    <Card><CardHeader><CardTitle>Export status</CardTitle></CardHeader><CardContent className="space-y-3"><label htmlFor="export-dataset" className="text-sm font-medium">Dataset</label><select id="export-dataset" value={dataset} onChange={(event) => setDataset(event.target.value)} className="h-9 w-full rounded-md border bg-background px-3 text-sm"><option value="products">Products</option><option value="orders">Orders</option><option value="customers">Customers</option></select><Button disabled={busy} onClick={() => run(() => fe1Service.startExport({ dataset, format: "csv" }))}>{busy ? "Starting..." : "Start export"}</Button>{message ? <p role="status" className="text-sm text-emerald-700">{message}</p> : null}{error ? <ApiState error={error} onRetry={() => run(() => fe1Service.startExport({ dataset, format: "csv" }))} /> : null}</CardContent></Card>
      <Card><CardHeader><CardTitle>Async export job</CardTitle></CardHeader><CardContent className="space-y-3"><label htmlFor="export-job-id" className="text-sm font-medium">Operation ID</label><Input id="export-job-id" value={jobId} onChange={(event) => setJobId(event.target.value)} placeholder="Returned operation ID" /><div className="flex flex-wrap gap-2"><Button variant="outline" disabled={busy || !jobId.trim()} onClick={() => { setBusy(true); setError(undefined); void fe1Service.exportStatus(jobId.trim()).then(setJob).catch(setError).finally(() => setBusy(false)); }}>Refresh status</Button><Button variant="outline" disabled={busy || !jobId.trim()} onClick={() => run(() => fe1Service.retryExport(jobId.trim()))}>Retry failed job</Button></div>{job && <pre className="max-h-40 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(job, null, 2)}</pre>}{error ? <ApiState error={error} onRetry={() => undefined} /> : null}</CardContent></Card>
      <ReservationsCard />
  </div>;
}

function ReservationsCard() {
  const user = useAuthStore((s) => s.user);
  const canWrite = canOperateMerchant(user, "inventory");
  const [variantId, setVariantId] = useState("");
  const [qty, setQty] = useState("1");
  const [cartId, setCartId] = useState("");
  const [reservationId, setReservationId] = useState("");
  const [fromSource, setFromSource] = useState("");
  const [toSource, setToSource] = useState("");
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);
  const act = (label: string, fn: () => Promise<unknown>) => {
    if (!canWrite || busy) return;
    setConfirmation({ title: label, description: `${label}? This writes to inventory and cannot be undone from the console.`, run: () => void execute(label, fn) });
  };
  const execute = async (label: string, fn: () => Promise<unknown>) => {
    setBusy(true); setNotice(null);
    try { const out = await fn(); const id = (out as { id?: unknown })?.id; setNotice(`${label} accepted${typeof id === "string" ? `: ${id}` : ""}.`); }
    catch (cause) { setNotice(messageOf(cause)); } finally { setBusy(false); }
  };
  return (
    <Card>
      <CardHeader><CardTitle>Reservations and transfers</CardTitle></CardHeader>
      <CardContent className="space-y-3">
        <div className="grid gap-2 md:grid-cols-3">
          <div><Label htmlFor="rsv-variant">Variant</Label><Input id="rsv-variant" value={variantId} onChange={(e) => setVariantId(e.target.value)} /></div>
          <div><Label htmlFor="rsv-qty">Qty</Label><Input id="rsv-qty" inputMode="numeric" value={qty} onChange={(e) => setQty(e.target.value)} /></div>
          <div><Label htmlFor="rsv-cart">Cart ID</Label><Input id="rsv-cart" value={cartId} onChange={(e) => setCartId(e.target.value)} /></div>
        </div>
        <Button disabled={!canWrite || busy || !variantId.trim() || !cartId.trim()} onClick={() => act("Create reservation", async () => {
          const res = await api.post<{ data: unknown }>("/api/v2/admin/warehouses/reservations", { variant_id: variantId.trim(), qty: Number(qty), cart_id: cartId.trim() }, { idempotencyKey: idempotencyKey() });
          const id = (res.data.data as { id?: unknown })?.id;
          if (typeof id === "string") setReservationId(id);
          return res.data.data;
        })}>Reserve</Button>
        <div className="flex flex-wrap gap-2 border-t pt-3">
          <Input aria-label="Reservation ID" value={reservationId} onChange={(e) => setReservationId(e.target.value)} placeholder="reservation id" className="max-w-xs" />
          <Button variant="outline" disabled={!canWrite || busy || !reservationId.trim()} onClick={() => act("Release reservation", () => api.post(`/api/v2/admin/warehouses/reservations/${encodeURIComponent(reservationId.trim())}/release`, undefined, { idempotencyKey: idempotencyKey() }))}>Release</Button>
        </div>
        <div className="grid gap-2 border-t pt-3 md:grid-cols-3">
          <div><Label htmlFor="xfer-from">From source</Label><Input id="xfer-from" value={fromSource} onChange={(e) => setFromSource(e.target.value)} /></div>
          <div><Label htmlFor="xfer-to">To source</Label><Input id="xfer-to" value={toSource} onChange={(e) => setToSource(e.target.value)} /></div>
          <div className="flex items-end"><Button variant="outline" disabled={!canWrite || busy || !fromSource.trim() || !toSource.trim() || !variantId.trim()} onClick={() => act("Transfer inventory", () => api.post("/api/v2/admin/warehouses/transfers", { from_source_id: fromSource.trim(), to_source_id: toSource.trim(), qty: Number(qty), variant_id: variantId.trim() }, { idempotencyKey: idempotencyKey() }))}>Transfer</Button></div>
        </div>
        {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot change inventory.</p>}
        {notice && <p role="status" className="text-sm text-muted-foreground">{notice}</p>}
        <ConfirmDialog
          open={confirmation !== null}
          title={confirmation?.title ?? ""}
          description={confirmation?.description}
          isPending={busy}
          onCancel={() => setConfirmation(null)}
          onConfirm={() => {
            const action = confirmation;
            setConfirmation(null);
            action?.run();
          }}
        />
      </CardContent>
    </Card>
  );
}

function VendorsTab() {
  const user = useAuthStore((s) => s.user);
  const canWrite = canOperateMerchant(user, "vendors");
  const [vendors, setVendors] = useState<RecordValue[]>([]);
  const [commissions, setCommissions] = useState<RecordValue[]>([]);
  const [sellerOrders, setSellerOrders] = useState<RecordValue[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);
  const load = () => {
    setLoading(true); setError(undefined);
    Promise.all([
      api.get<{ data?: RecordValue[] }>("/api/v2/admin/vendors", { params: { limit: 50 } }),
      api.get<{ data?: RecordValue[] }>("/api/v2/admin/commissions", { params: { limit: 50 } }),
      api.get<{ data?: RecordValue[] }>("/api/v2/admin/seller-orders", { params: { limit: 50 } }),
    ]).then(([v, c, s]) => { setVendors(v.data.data ?? []); setCommissions(c.data.data ?? []); setSellerOrders(s.data.data ?? []); })
      .catch(setError).finally(() => setLoading(false));
  };
  useEffect(load, []);
  const onboard = () => {
    if (!canWrite || busy || !code.trim() || !name.trim()) return;
    const vendorCode = code.trim();
    const vendorName = name.trim();
    setConfirmation({
      title: "Onboard vendor",
      description: `Onboard vendor ${vendorCode}? This creates a vendor record in the current commerce scope.`,
      run: () => void executeOnboard(vendorCode, vendorName),
    });
  };
  const executeOnboard = async (vendorCode: string, vendorName: string) => {
    setBusy(true);
    try {
      await api.post("/api/v2/admin/vendors", { code: vendorCode, name: vendorName }, { idempotencyKey: idempotencyKey() });
      setCode(""); setName(""); load();
    } catch (cause) { setError(cause); } finally { setBusy(false); }
  };
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card><CardHeader><CardTitle>Onboard vendor</CardTitle></CardHeader><CardContent className="space-y-3">
        <div><Label htmlFor="vendor-code">Code</Label><Input id="vendor-code" value={code} onChange={(e) => setCode(e.target.value)} disabled={!canWrite || busy} /></div>
        <div><Label htmlFor="vendor-name">Name</Label><Input id="vendor-name" value={name} onChange={(e) => setName(e.target.value)} disabled={!canWrite || busy} /></div>
        <Button onClick={onboard} disabled={!canWrite || busy || !code.trim() || !name.trim()}>Onboard</Button>
        {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot onboard vendors.</p>}
        {error ? <ApiState error={error} onRetry={load} /> : null}
      </CardContent></Card>
      <ListCard title={`Vendors (${vendors.length})`} loading={loading} error={error} items={vendors} empty="No vendors found." onRetry={load} render={(row, i) => <div key={String(row.id ?? i)} className="rounded-md border p-3 text-sm"><p className="font-medium">{String(row.name ?? row.code ?? "Vendor")}</p><p className="text-xs text-muted-foreground">{String(row.status ?? row.state ?? "")}</p></div>} />
      <ListCard title={`Commissions (${commissions.length})`} loading={loading} error={error} items={commissions} empty="No commission entries found." onRetry={load} render={(row, i) => <div key={String(row.id ?? i)} className="rounded-md border p-3 text-sm"><p className="font-medium">{String(row.id ?? "Entry")}</p><p className="text-xs text-muted-foreground">{String(row.gross_minor ?? "")} · {String(row.currency ?? "")}</p></div>} />
      <ListCard title={`Seller orders (${sellerOrders.length})`} loading={loading} error={error} items={sellerOrders} empty="No seller orders found." onRetry={load} render={(row, i) => <div key={String(row.id ?? i)} className="rounded-md border p-3 text-sm"><p className="font-medium">{String(row.order_id ?? row.id ?? "Order")}</p><p className="text-xs text-muted-foreground">{String(row.state ?? row.status ?? "")}</p></div>} />
      <ConfirmDialog
        open={confirmation !== null}
        title={confirmation?.title ?? ""}
        description={confirmation?.description}
        isPending={busy}
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

function AuditTab() {
  const [logs, setLogs] = useState<RecordValue[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const load = () => {
    setLoading(true);
    api.get<{ data?: RecordValue[] }>("/api/v2/admin/audit-logs", { params: { limit: 50 } })
      .then((res) => setLogs(res.data.data ?? []))
      .catch(setError)
      .finally(() => setLoading(false));
  };
  useEffect(load, []);
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <ListCard title={`Audit log (${logs.length})`} loading={loading} error={error} items={logs} empty="No audit entries found." onRetry={load} render={(row, i) => <div key={String(row.id ?? i)} className="rounded-md border p-3 text-sm"><p className="font-medium">{String(row.action ?? row.event ?? "Action")}</p><p className="text-xs text-muted-foreground">{String(row.created_at ?? "")} · {String(row.actor ?? "")}</p></div>} />
      <NotificationEventsCard />
    </div>
  );
}

function JobsTab() {
  const [name, setName] = useState("export");
  const [payloadRaw, setPayloadRaw] = useState("{}");
  const [jobId, setJobId] = useState("");
  const [action, setAction] = useState("cancel");
  const [result, setResult] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);
  const act = (label: string, fn: () => Promise<unknown>) => {
    if (busy) return;
    setConfirmation({ title: label, description: `${label}? This submits a backend job operation.`, run: () => void execute(label, fn) });
  };
  const execute = async (label: string, fn: () => Promise<unknown>) => {
    setBusy(true); setNotice(null);
    try { const out = await fn(); setResult(out); setNotice(`${label} accepted.`); }
    catch (cause) { setNotice(messageOf(cause)); } finally { setBusy(false); }
  };
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card><CardHeader><CardTitle>Enqueue job</CardTitle></CardHeader><CardContent className="space-y-3">
        <div><Label htmlFor="job-name">Job name</Label><Input id="job-name" value={name} onChange={(e) => setName(e.target.value)} /></div>
        <div><Label htmlFor="job-payload">Payload JSON</Label><Textarea id="job-payload" value={payloadRaw} onChange={(e) => setPayloadRaw(e.target.value)} rows={3} /></div>
        <Button disabled={busy || !name.trim()} onClick={() => act("Enqueue job", async () => {
          let payload: Record<string, unknown> | undefined;
          try { payload = payloadRaw.trim() ? JSON.parse(payloadRaw) : undefined; } catch { throw new Error("Payload must be valid JSON."); }
          const res = await api.post<{ data: unknown }>("/api/v2/admin/jobs", { name: name.trim(), ...(payload ? { payload } : {}) }, { idempotencyKey: idempotencyKey() });
          const id = (res.data.data as { id?: unknown })?.id;
          if (typeof id === "string") setJobId(id);
          return res.data.data;
        })}>Enqueue</Button>
      </CardContent></Card>
      <Card><CardHeader><CardTitle>Job status</CardTitle></CardHeader><CardContent className="space-y-3">
        <div><Label htmlFor="job-id">Job ID</Label><Input id="job-id" value={jobId} onChange={(e) => setJobId(e.target.value)} /></div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={busy || !jobId.trim()} onClick={() => act("Refresh job", async () => {
            const res = await api.get<{ data: unknown }>(`/api/v2/admin/jobs/${encodeURIComponent(jobId.trim())}`);
            return res.data.data;
          })}>Refresh</Button>
          <select aria-label="Job action" value={action} onChange={(e) => setAction(e.target.value)} className="h-10 rounded-md border bg-background px-3 text-sm">
            <option value="cancel">cancel</option>
            <option value="retry">retry</option>
          </select>
          <Button variant="outline" disabled={busy || !jobId.trim()} onClick={() => act(`${action} job`, async () => {
            const res = await api.post<{ data: unknown }>(`/api/v2/admin/jobs/${encodeURIComponent(jobId.trim())}/${encodeURIComponent(action)}`, undefined, { idempotencyKey: idempotencyKey() });
            return res.data.data;
          })}>Apply action</Button>
        </div>
        {notice && <p role="status" className="text-sm text-muted-foreground">{notice}</p>}
        {result ? <pre className="max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre> : null}
      </CardContent></Card>
      <ConfirmDialog
        open={confirmation !== null}
        title={confirmation?.title ?? ""}
        description={confirmation?.description}
        isPending={busy}
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

function CommercePageContent() {
  const [tab, setTab] = useState<Tab>("catalog");
  const tabs: Tab[] = ["catalog", "lifecycle", "promotions", "operations", "sites", "events", "vendors", "audit", "jobs"];
  return <div className="space-y-6"><PageHeader title="Commerce management" description="Contract-backed lifecycle, promotion, site, operations, and event controls" /><div role="tablist" aria-label="Commerce management sections" className="flex max-w-full gap-2 overflow-x-auto border-b pb-2">{tabs.map((item) => <button key={item} type="button" role="tab" aria-selected={tab === item} onClick={() => setTab(item)} className={`rounded-md px-3 py-2 text-sm font-medium ${tab === item ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}>{item[0].toUpperCase() + item.slice(1)}</button>)}</div>{tab === "catalog" ? <CatalogTab /> : tab === "lifecycle" ? <LifecycleTab /> : tab === "promotions" ? <PromotionsTab /> : tab === "operations" ? <OperationsTab /> : tab === "sites" ? <SitesTab /> : tab === "events" ? <EventsTab /> : tab === "vendors" ? <VendorsTab /> : tab === "audit" ? <AuditTab /> : <JobsTab />}</div>;
}

export default function CommercePage() { return <CapabilityGate capability="products"><CommercePageContent /></CapabilityGate>; }
