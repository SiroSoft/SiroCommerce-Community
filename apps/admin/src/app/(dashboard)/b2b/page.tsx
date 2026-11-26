"use client";

import { useEffect, useRef, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { RoleGate } from "@/components/capability-gate";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { ApiState } from "@/modules/merchant/api-state";
import { b2bService, type B2bRecord } from "@/services/b2b.service";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { confirmAction, parseNonNegativeInt, parsePositiveInt, validateMinorAmount, validateRequiredId } from "@/lib/write-safety";
import { useAuthStore } from "@/store/auth.store";
import { fe1Service } from "@/services/fe1.service";
import { idempotencyKey } from "@/services/merchant.service";
import api from "@/services/api";

type Tab = "accounts" | "quotes" | "vendors" | "sites" | "orders";

const unwrap = <T,>(envelope: unknown): T => {
  const body = envelope as { data?: T };
  return (body && typeof body === "object" && "data" in body ? body.data : envelope) as T;
};

function ActionError({ error, retry }: { error: unknown; retry: () => void }) {
  return <ApiState error={error} onRetry={retry} />;
}

function AccountsTab() {
  const user = useAuthStore((state) => state.user);
  const canWrite = canOperateMerchant(user, "b2b");
  const [companyId, setCompanyId] = useState("");
  const [companyName, setCompanyName] = useState("");
  const [variantId, setVariantId] = useState("");
  const [catalog, setCatalog] = useState("");
  const [price, setPrice] = useState("");
  const [creditLimit, setCreditLimit] = useState("");
  const [creditCurrency, setCreditCurrency] = useState("USD");
  const [creditTerms, setCreditTerms] = useState("30");
  const [contractNumber, setContractNumber] = useState("");
  const [priceListCode, setPriceListCode] = useState("");
  const [priceListCurrency, setPriceListCurrency] = useState("USD");
  const [tierListId, setTierListId] = useState("");
  const [tierVariant, setTierVariant] = useState("");
  const [tierQty, setTierQty] = useState("1");
  const [tierPrice, setTierPrice] = useState("");
  const [termsMinOrder, setTermsMinOrder] = useState("");
  const [termsCreditLimit, setTermsCreditLimit] = useState("");
  const [termsPaymentDays, setTermsPaymentDays] = useState("");
  const [termsCurrency, setTermsCurrency] = useState("USD");
  const [credit, setCredit] = useState<B2bRecord>();
  const [result, setResult] = useState<B2bRecord | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>();
  const [message, setMessage] = useState("");
  const [validation, setValidation] = useState("");
  const lock = useRef(false);
  const dirty = [companyId, companyName, variantId, catalog, price, creditLimit, contractNumber, priceListCode, tierListId, tierVariant, tierPrice, termsMinOrder, termsCreditLimit, termsPaymentDays].some(Boolean);

  const run = async (action: () => Promise<unknown>) => {
    if (!canWrite || lock.current) return;
    lock.current = true;
    setBusy(true);
    setError(undefined);
    setMessage("");
    setValidation("");
    try {
      const value = await action();
      setResult((value ?? null) as B2bRecord | null);
      setMessage("Accepted by the backend. No local state was assumed; reload to verify.");
    } catch (cause) {
      setError(cause);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  };

  const failValidation = (text: string) => {
    setValidation(text);
    setMessage("");
  };

  return <div className="grid gap-4 lg:grid-cols-2">
    <Card><CardHeader><CardTitle>Organization and group</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="company-name" className="text-sm font-medium">Organization name</label>
      <Input id="company-name" value={companyName} onChange={(event) => setCompanyName(event.target.value)} placeholder="Acme Procurement" disabled={!canWrite || busy} />
      <Button disabled={!canWrite || busy || !companyName.trim()} onClick={() => run(() => b2bService.createCompany({ name: companyName.trim(), status: "active" }))}>Create organization</Button>
      <label htmlFor="company-id" className="block text-sm font-medium">Organization ID</label>
      <Input id="company-id" value={companyId} onChange={(event) => setCompanyId(event.target.value)} placeholder="Required for organization actions" disabled={busy} />
      {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot change B2B organizations.</p>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Negotiated catalog pricing</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="b2b-variant" className="text-sm font-medium">Variant ID</label><Input id="b2b-variant" value={variantId} onChange={(event) => setVariantId(event.target.value)} disabled={!canWrite || busy} />
      <label htmlFor="b2b-price" className="text-sm font-medium">Unit price in minor units</label><Input id="b2b-price" type="number" min="0" value={price} onChange={(event) => setPrice(event.target.value)} disabled={!canWrite || busy} />
      <Button disabled={!canWrite || busy || !companyId.trim() || !variantId.trim() || !price} onClick={() => { const amount = validateMinorAmount(price, "Unit price"); if (!amount.ok) { failValidation(amount.error); return; } if (!companyId.trim() || !variantId.trim()) { failValidation("Organization ID and variant ID are required."); return; } void run(() => b2bService.setNegotiatedPrice(companyId.trim(), { variant_id: variantId.trim(), unit_price_minor: amount.value })); }}>Save negotiated price</Button>
      <p className="text-xs text-muted-foreground">This is the contract-backed negotiated-price route, not a price-list editor.</p>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Catalog ownership</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="catalog-code" className="text-sm font-medium">Catalog code</label><Input id="catalog-code" value={catalog} onChange={(event) => setCatalog(event.target.value)} disabled={!canWrite || busy} />
      <Button disabled={!canWrite || busy || !companyId.trim() || !catalog.trim()} onClick={() => run(() => b2bService.createCatalog(companyId.trim(), { code: catalog.trim(), name: catalog.trim() }))}>Assign catalog</Button>
      <label htmlFor="catalog-variant" className="text-sm font-medium">Catalog item variant ID</label><Input id="catalog-variant" value={variantId} onChange={(event) => setVariantId(event.target.value)} disabled={!canWrite || busy} />
      <Button variant="outline" disabled={!canWrite || busy || !companyId.trim() || !catalog.trim() || !variantId.trim()} onClick={() => run(() => b2bService.addCatalogItem(companyId.trim(), catalog.trim(), { variant_id: variantId.trim() }))}>Add catalog item</Button>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Credit read</CardTitle></CardHeader><CardContent className="space-y-3">
      <Button variant="outline" disabled={busy || !companyId.trim()} onClick={() => { setBusy(true); setError(undefined); void b2bService.credit(companyId.trim()).then(setCredit).catch(setError).finally(() => setBusy(false)); }}>Load credit account</Button>
      {credit ? <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(credit, null, 2)}</pre> : <p className="text-sm text-muted-foreground">No credit data loaded.</p>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Credit account</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="credit-limit" className="text-sm font-medium">Credit limit in minor units</label><Input id="credit-limit" type="number" min="1" value={creditLimit} onChange={(event) => setCreditLimit(event.target.value)} disabled={!canWrite || busy} />
      <div className="grid grid-cols-2 gap-3">
        <div><label htmlFor="credit-currency" className="text-sm font-medium">Currency</label><Input id="credit-currency" value={creditCurrency} onChange={(event) => setCreditCurrency(event.target.value)} disabled={!canWrite || busy} /></div>
        <div><label htmlFor="credit-terms" className="text-sm font-medium">Payment terms (days)</label><Input id="credit-terms" type="number" min="0" value={creditTerms} onChange={(event) => setCreditTerms(event.target.value)} disabled={!canWrite || busy} /></div>
      </div>
      <Button disabled={!canWrite || busy || !companyId.trim() || !creditLimit} onClick={() => { const limit = validateMinorAmount(creditLimit, "Credit limit"); if (!limit.ok) { failValidation(limit.error); return; } if (!companyId.trim()) { failValidation("Organization ID is required."); return; } void run(() => api.post(`/api/admin/b2b/companies/${encodeURIComponent(companyId.trim())}/credit-account`, { limit_minor: limit.value, currency: creditCurrency.trim() || undefined, payment_terms_days: creditTerms === "" ? undefined : Number(creditTerms) }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap(response.data))); }}>Create credit account</Button>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Contracts</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="contract-number" className="text-sm font-medium">Contract number</label><Input id="contract-number" value={contractNumber} onChange={(event) => setContractNumber(event.target.value)} placeholder="CTR-2026-001" disabled={!canWrite || busy} />
      <Button disabled={!canWrite || busy || !companyId.trim() || !contractNumber.trim()} onClick={() => run(() => api.post(`/api/admin/b2b/companies/${encodeURIComponent(companyId.trim())}/contracts`, { contract_number: contractNumber.trim() }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap(response.data)))}>Create contract</Button>
      <p className="text-xs text-muted-foreground">Contract approval lives under the Quotes tab.</p>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Price lists</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="price-list-code" className="text-sm font-medium">Price list code</label><Input id="price-list-code" value={priceListCode} onChange={(event) => setPriceListCode(event.target.value)} placeholder="ENTERPRISE-USD" disabled={!canWrite || busy} />
      <label htmlFor="price-list-currency" className="text-sm font-medium">Currency</label><Input id="price-list-currency" value={priceListCurrency} onChange={(event) => setPriceListCurrency(event.target.value)} disabled={!canWrite || busy} />
      <Button disabled={!canWrite || busy || !companyId.trim() || !priceListCode.trim()} onClick={() => run(() => api.post(`/api/admin/b2b/companies/${encodeURIComponent(companyId.trim())}/price-lists`, { code: priceListCode.trim(), currency: priceListCurrency.trim() || undefined }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap(response.data)))}>Create price list</Button>
      <div className="border-t pt-3 space-y-3">
        <label htmlFor="tier-list" className="text-sm font-medium">Price list ID (for tier)</label><Input id="tier-list" value={tierListId} onChange={(event) => setTierListId(event.target.value)} disabled={!canWrite || busy} />
        <label htmlFor="tier-variant" className="text-sm font-medium">Variant ID</label><Input id="tier-variant" value={tierVariant} onChange={(event) => setTierVariant(event.target.value)} disabled={!canWrite || busy} />
        <div className="grid grid-cols-2 gap-3">
          <div><label htmlFor="tier-qty" className="text-sm font-medium">Minimum qty</label><Input id="tier-qty" type="number" min="1" value={tierQty} onChange={(event) => setTierQty(event.target.value)} disabled={!canWrite || busy} /></div>
          <div><label htmlFor="tier-price" className="text-sm font-medium">Unit price (minor)</label><Input id="tier-price" type="number" min="0" value={tierPrice} onChange={(event) => setTierPrice(event.target.value)} disabled={!canWrite || busy} /></div>
        </div>
        <Button variant="outline" disabled={!canWrite || busy || !tierListId.trim() || !tierVariant.trim() || !tierPrice} onClick={() => { const qty = parsePositiveInt(tierQty); const unit = validateMinorAmount(tierPrice, "Unit price"); if (qty === null) { failValidation("Minimum qty must be a positive whole number."); return; } if (!unit.ok) { failValidation(unit.error); return; } if (!tierListId.trim() || !tierVariant.trim()) { failValidation("Price list ID and variant ID are required."); return; } void run(() => api.post(`/api/admin/b2b/price-lists/${encodeURIComponent(tierListId.trim())}/tiers`, { variant_id: tierVariant.trim(), minimum_qty: qty, unit_price_minor: unit.value }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap(response.data))); }}>Save price tier</Button>
      </div>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Commercial terms</CardTitle></CardHeader><CardContent className="space-y-3">
      <div className="grid grid-cols-2 gap-3">
        <div><label htmlFor="terms-min-order" className="text-sm font-medium">Min order (minor)</label><Input id="terms-min-order" type="number" min="0" value={termsMinOrder} onChange={(event) => setTermsMinOrder(event.target.value)} disabled={!canWrite || busy} /></div>
        <div><label htmlFor="terms-credit" className="text-sm font-medium">Credit limit (minor)</label><Input id="terms-credit" type="number" min="0" value={termsCreditLimit} onChange={(event) => setTermsCreditLimit(event.target.value)} disabled={!canWrite || busy} /></div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div><label htmlFor="terms-days" className="text-sm font-medium">Payment terms (days)</label><Input id="terms-days" type="number" min="0" value={termsPaymentDays} onChange={(event) => setTermsPaymentDays(event.target.value)} disabled={!canWrite || busy} /></div>
        <div><label htmlFor="terms-currency" className="text-sm font-medium">Currency</label><Input id="terms-currency" value={termsCurrency} onChange={(event) => setTermsCurrency(event.target.value)} disabled={!canWrite || busy} /></div>
      </div>
      <Button variant="outline" disabled={!canWrite || busy || !companyId.trim()} onClick={() => run(() => api.put(`/api/admin/b2b/companies/${encodeURIComponent(companyId.trim())}/terms`, { minimum_order_minor: termsMinOrder === "" ? undefined : Number(termsMinOrder), credit_limit_minor: termsCreditLimit === "" ? undefined : Number(termsCreditLimit), payment_terms_days: termsPaymentDays === "" ? undefined : Number(termsPaymentDays), currency: termsCurrency.trim() || undefined }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap(response.data)))}>Save commercial terms</Button>
    </CardContent></Card>
    {dirty && <p role="status" className="lg:col-span-2 text-sm text-amber-700">Unsaved changes are present. Submit or clear before leaving.</p>}
    {validation && <p role="alert" className="lg:col-span-2 text-sm text-destructive">{validation}</p>}
    {message && <p role="status" className="lg:col-span-2 text-sm text-emerald-700">{message}</p>}
    {result && <pre className="lg:col-span-2 max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre>}
    {error !== undefined ? <div className="lg:col-span-2"><ActionError error={error} retry={() => setError(undefined)} /></div> : null}
  </div>;
}

const BULK_MAX_ROWS = 200;

function splitCsvLine(line: string): string[] {
  const out: string[] = [];
  let cur = "";
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (inQuotes) {
      if (ch === '"') {
        if (line[i + 1] === '"') { cur += '"'; i++; }
        else inQuotes = false;
      } else cur += ch;
    } else if (ch === '"') inQuotes = true;
    else if (ch === ",") { out.push(cur); cur = ""; }
    else cur += ch;
  }
  out.push(cur);
  return out;
}

function parseBulkCsv(raw: string): { ok: true; lines: { variant_id: string; qty: number }[] } | { ok: false; error: string } {
  const rows = raw.split(/\r?\n/).map((l) => l.trim()).filter((l) => l !== "");
  if (rows.length === 0) return { ok: false, error: "CSV needs header variant_id,qty + at least 1 row" };
  const header = splitCsvLine(rows[0].toLowerCase()).map((h) => h.trim());
  if (header.length < 2 || header[0] !== "variant_id" || header[1] !== "qty") return { ok: false, error: "CSV header must be variant_id,qty" };
  const dataRows = rows.slice(1);
  if (dataRows.length === 0) return { ok: false, error: "CSV needs header variant_id,qty + at least 1 row" };
  if (dataRows.length > BULK_MAX_ROWS) return { ok: false, error: `Bulk is limited to ${BULK_MAX_ROWS} rows (got ${dataRows.length})` };
  const lines: { variant_id: string; qty: number }[] = [];
  for (let i = 0; i < dataRows.length; i++) {
    const cols = splitCsvLine(dataRows[i]);
    const variant_id = (cols[0] ?? "").trim();
    const qtyRaw = (cols[1] ?? "").trim();
    if (!variant_id) return { ok: false, error: `Row ${i + 2}: variant_id is required` };
    if (!/^\d+$/.test(qtyRaw)) return { ok: false, error: `Row ${i + 2}: qty must be a positive integer` };
    const qty = Number(qtyRaw);
    if (!Number.isSafeInteger(qty) || qty < 1 || qty > 10000) return { ok: false, error: `Row ${i + 2}: qty must be 1-10000` };
    lines.push({ variant_id, qty });
  }
  return { ok: true, lines };
}

function QuotesTab() {
  const user = useAuthStore((state) => state.user);
  const canWrite = canOperateMerchant(user, "b2b");
  const [companyId, setCompanyId] = useState("");
  const [quoteId, setQuoteId] = useState("");
  const [orderId, setOrderId] = useState("");
  const [contractId, setContractId] = useState("");
  const [quote, setQuote] = useState<B2bRecord>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>();
  const [notice, setNotice] = useState("");
  const [validation, setValidation] = useState("");
  const lock = useRef(false);
  const run = async (action: () => Promise<unknown>, success: string) => {
    if (!canWrite || lock.current) return;
    lock.current = true; setBusy(true); setError(undefined); setNotice(""); setValidation("");
    try { await action(); setNotice(success); } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); }
  };
  const refresh = async () => {
    const id = validateRequiredId(quoteId, "Quote ID");
    if (!id.ok || lock.current) return;
    lock.current = true; setBusy(true); setError(undefined); setNotice("");
    try { setQuote(await b2bService.quote(id.value)); } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); }
  };
  const [bulkCsv, setBulkCsv] = useState("variant_id,qty\nv1,2\nv2,5");
  const [bulkCurrency, setBulkCurrency] = useState("USD");
  return <div className="grid gap-4 lg:grid-cols-2">
    <Card><CardHeader><CardTitle>Quote request</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="quote-company" className="text-sm font-medium">Organization ID</label><Input id="quote-company" value={companyId} onChange={(event) => setCompanyId(event.target.value)} disabled={busy} />
      <p className="text-xs text-muted-foreground">Line schema is intentionally not guessed. Use bulk CSV below.</p>
      <div className="space-y-2 rounded-md border p-3">
        <Label htmlFor="bulk-csv">Bulk quote CSV (variant_id,qty, max 200 rows)</Label><Textarea id="bulk-csv" value={bulkCsv} onChange={(e) => setBulkCsv(e.target.value)} rows={4} disabled={busy} />
        <div className="grid grid-cols-2 gap-2"><div><Label>Currency</Label><Input value={bulkCurrency} onChange={(e) => setBulkCurrency(e.target.value)} disabled={busy} /></div><Button disabled={!canWrite || busy || !companyId.trim()} onClick={() => {
          const parsed = parseBulkCsv(bulkCsv);
          if (!parsed.ok) { setValidation(parsed.error); return; }
          void run(() => b2bService.createQuote(companyId.trim(), { currency: bulkCurrency.trim()||"USD", lines: parsed.lines as unknown as Record<string, never>[] }), `Bulk quote created (${parsed.lines.length} lines).`);
        }}>Create bulk quote</Button></div>
        <Button variant="outline" disabled={!canWrite || busy || !companyId.trim()} onClick={() => {
          const parsed = parseBulkCsv(bulkCsv);
          if (!parsed.ok) { setValidation(parsed.error); return; }
          void run(() => api.post(`/api/v2/b2b/companies/${encodeURIComponent(companyId.trim())}/orders/validate-bulk`, { lines: parsed.lines }, { idempotencyKey: idempotencyKey() }).then(r=>unwrap(r.data)), `Bulk validated (${parsed.lines.length} lines).`);
        }}>Validate bulk</Button>
        {validation && <p role="alert" className="text-sm text-destructive">{validation}</p>}
      </div>
      <Button disabled={!canWrite || busy || !companyId.trim()} onClick={() => void run(() => b2bService.createQuote(companyId.trim(), { currency: "USD", lines: [] }), "Quote request created.")}>Create quote request</Button>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Quote approval</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="quote-id" className="text-sm font-medium">Quote ID</label><Input id="quote-id" value={quoteId} onChange={(event) => setQuoteId(event.target.value)} disabled={busy} />
      <div className="flex flex-wrap gap-2"><Button variant="outline" disabled={busy || !quoteId.trim()} onClick={() => void refresh()}>{busy ? "Working..." : "Refresh quote"}</Button><Button disabled={!canWrite || busy || !quoteId.trim()} onClick={() => { if (!confirmAction(`Approve quote ${quoteId.trim()}?`)) return; void run(() => b2bService.quoteAction(quoteId.trim(), "approve"), "Quote approved."); }}>{busy ? "Working..." : "Approve quote"}</Button><Button variant="outline" disabled={!canWrite || busy || !quoteId.trim()} onClick={() => { if (!confirmAction(`Reject quote ${quoteId.trim()}?`)) return; void run(() => b2bService.quoteAction(quoteId.trim(), "reject"), "Quote rejected."); }}>Reject quote</Button><Button variant="outline" disabled={!canWrite || busy || !quoteId.trim()} onClick={() => { if (!confirmAction(`Convert quote ${quoteId.trim()} to an order?`)) return; void run(() => b2bService.convertQuote(quoteId.trim()), "Quote conversion accepted."); }}>Convert quote</Button></div>
      {quote ? <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(quote, null, 2)}</pre> : <p className="text-sm text-muted-foreground">No quote loaded.</p>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Order and contract approvals</CardTitle></CardHeader><CardContent className="space-y-3">
      <label htmlFor="b2b-order" className="text-sm font-medium">B2B order ID</label><Input id="b2b-order" value={orderId} onChange={(event) => setOrderId(event.target.value)} disabled={busy} /><div className="flex flex-wrap gap-2"><Button disabled={!canWrite || busy || !orderId.trim()} onClick={() => { if (!confirmAction(`Approve B2B order ${orderId.trim()}?`)) return; void run(() => b2bService.approveOrder(orderId.trim()), "B2B order approved."); }}>Approve order</Button><Button variant="outline" disabled={!canWrite || busy || !orderId.trim()} onClick={() => { if (!confirmAction(`Approve B2B order ${orderId.trim()} for the organization?`)) return; void run(() => b2bService.approveOrder(orderId.trim(), true), "B2B order approved for the organization."); }}>Approve for organization</Button></div>
      <label htmlFor="b2b-contract" className="block text-sm font-medium">Contract ID</label><Input id="b2b-contract" value={contractId} onChange={(event) => setContractId(event.target.value)} disabled={busy} /><Button variant="outline" disabled={!canWrite || busy || !contractId.trim()} onClick={() => { if (!confirmAction(`Approve contract ${contractId.trim()}?`)) return; void run(() => b2bService.approveContract(contractId.trim()), "Contract approved."); }}>Approve contract</Button>
    </CardContent></Card>
    {notice && <p role="status" className="lg:col-span-2 text-sm text-emerald-700">{notice}</p>}
    {error !== undefined ? <div className="lg:col-span-2"><ActionError error={error} retry={() => setError(undefined)} /></div> : null}
  </div>;
}

function downloadCsv(rows: B2bRecord[]) {
  const columns = Array.from(new Set(rows.flatMap((row) => Object.keys(row))));
  const csv = [columns, ...rows.map((row) => columns.map((column) => JSON.stringify(row[column] ?? "")))]
    .map((row) => row.join(",")).join("\n");
  const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
  const link = document.createElement("a"); link.href = url; link.download = "vendors.csv"; link.click(); URL.revokeObjectURL(url);
}

function VendorsTab() {
  const user = useAuthStore((state) => state.user);
  const canWrite = canOperateMerchant(user, "b2b");
  const [vendors, setVendors] = useState<B2bRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const [onboardCode, setOnboardCode] = useState("");
  const [onboardName, setOnboardName] = useState("");
  const [vendorId, setVendorId] = useState("");
  const [vendorDetail, setVendorDetail] = useState<B2bRecord | null>(null);
  const [vendorStatus, setVendorStatus] = useState("active");
  const [catalogProduct, setCatalogProduct] = useState("");
  const [catalogVisibility, setCatalogVisibility] = useState("visible");
  const [commissions, setCommissions] = useState<B2bRecord[]>([]);
  const [commissionVendor, setCommissionVendor] = useState("");
  const [commissionGross, setCommissionGross] = useState("");
  const [commissionRate, setCommissionRate] = useState("");
  const [commissionCurrency, setCommissionCurrency] = useState("USD");
  const [commissionId, setCommissionId] = useState("");
  const [commissionDetail, setCommissionDetail] = useState<B2bRecord | null>(null);
  const [payoutVendor, setPayoutVendor] = useState("");
  const [payoutAmount, setPayoutAmount] = useState("");
  const [payoutCurrency, setPayoutCurrency] = useState("USD");
  const [payoutId, setPayoutId] = useState("");
  const [payoutDetail, setPayoutDetail] = useState<B2bRecord | null>(null);
  const [payoutState, setPayoutState] = useState("eligible");
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<unknown>();
  const [message, setMessage] = useState("");
  const load = () => { setLoading(true); setError(undefined); void b2bService.vendors().then(setVendors).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);

  const run = async (action: () => Promise<B2bRecord | null | undefined>, apply?: (value: B2bRecord) => void) => {
    if (!canWrite || busy) return;
    setBusy(true);
    setActionError(undefined);
    setMessage("");
    try {
      const value = await action();
      if (value && apply) apply(value);
      setMessage("Accepted by the backend. Reload lists to verify.");
    } catch (cause) {
      setActionError(cause);
    } finally {
      setBusy(false);
    }
  };

  const loadCommissions = () => {
    setBusy(true);
    setActionError(undefined);
    void api.get<{ data: B2bRecord[] }>("/api/v2/admin/commissions", { params: { limit: 50, offset: 0 } })
      .then((response) => setCommissions(unwrap<B2bRecord[]>(response.data) ?? []))
      .catch(setActionError)
      .finally(() => setBusy(false));
  };

  return <div className="space-y-4">
    <div className="flex flex-wrap justify-end gap-2"><Button variant="outline" onClick={load}>Refresh vendors</Button><Button variant="outline" disabled={!vendors.length} onClick={() => downloadCsv(vendors)}>Export CSV</Button></div>
    <Card><CardHeader><CardTitle>Vendor status and catalog ownership</CardTitle></CardHeader><CardContent>{loading ? <p role="status">Loading vendors...</p> : error ? <ActionError error={error} retry={load} /> : vendors.length ? <div className="overflow-x-auto"><table className="w-full min-w-[640px] text-left text-sm"><caption className="sr-only">Vendors</caption><thead><tr className="border-b"><th scope="col" className="p-3">Vendor</th><th scope="col" className="p-3">Status</th><th scope="col" className="p-3">Catalog owner</th><th scope="col" className="p-3">Onboarding</th></tr></thead><tbody>{vendors.map((vendor, index) => <tr key={String(vendor.id ?? index)} className="border-b"><td className="p-3">{String(vendor.name ?? vendor.code ?? vendor.id ?? "Unnamed")}</td><td className="p-3"><Badge>{String(vendor.status ?? "unknown")}</Badge></td><td className="p-3">{String(vendor.catalog_owner ?? vendor.catalog_owner_id ?? "Not reported")}</td><td className="p-3">{String(vendor.onboarding_status ?? "Not reported")}</td></tr>)}</tbody></table></div> : <p className="text-sm text-muted-foreground">No vendors returned for the current scope.</p>}</CardContent></Card>
    <div className="grid gap-4 lg:grid-cols-2">
      <Card><CardHeader><CardTitle>Vendor onboarding</CardTitle></CardHeader><CardContent className="space-y-3">
        <label htmlFor="vendor-code" className="text-sm font-medium">Vendor code</label><Input id="vendor-code" value={onboardCode} onChange={(event) => setOnboardCode(event.target.value)} placeholder="acme-supply" disabled={!canWrite || busy} />
        <label htmlFor="vendor-name" className="text-sm font-medium">Vendor name</label><Input id="vendor-name" value={onboardName} onChange={(event) => setOnboardName(event.target.value)} placeholder="Acme Supply Co." disabled={!canWrite || busy} />
        <Button disabled={!canWrite || busy || !onboardCode.trim() || !onboardName.trim()} onClick={() => void run(() => api.post("/api/v2/admin/vendors", { code: onboardCode.trim(), name: onboardName.trim() }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap<B2bRecord>(response.data)), () => load())}>Onboard vendor</Button>
        {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot onboard vendors.</p>}
      </CardContent></Card>
      <Card><CardHeader><CardTitle>Vendor detail and status</CardTitle></CardHeader><CardContent className="space-y-3">
        <label htmlFor="vendor-id" className="text-sm font-medium">Vendor ID</label><Input id="vendor-id" value={vendorId} onChange={(event) => setVendorId(event.target.value)} disabled={busy} />
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={busy || !vendorId.trim()} onClick={() => void run(() => api.get(`/api/v2/admin/vendors/${encodeURIComponent(vendorId.trim())}`).then((response) => unwrap<B2bRecord>(response.data)), setVendorDetail)}>Load vendor</Button>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="grid gap-1 text-sm">Status<select aria-label="Vendor status" className="h-10 rounded-md border bg-background px-3" value={vendorStatus} onChange={(event) => setVendorStatus(event.target.value)} disabled={!canWrite || busy}><option value="active">active</option><option value="suspended">suspended</option><option value="disabled">disabled</option></select></label>
          <Button variant="outline" disabled={!canWrite || busy || !vendorId.trim()} onClick={() => { if (!confirmAction(`Set vendor ${vendorId.trim()} status to "${vendorStatus}"?`)) return; void run(() => api.post(`/api/v2/admin/vendors/${encodeURIComponent(vendorId.trim())}/status`, { status: vendorStatus }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap<B2bRecord>(response.data)), setVendorDetail); }}>Update status</Button>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="grid gap-1 text-sm">Product ID<Input aria-label="Catalog product ID" className="max-w-[12rem]" value={catalogProduct} onChange={(event) => setCatalogProduct(event.target.value)} disabled={!canWrite || busy} /></label>
          <label className="grid gap-1 text-sm">Visibility<select aria-label="Catalog visibility" className="h-10 rounded-md border bg-background px-3" value={catalogVisibility} onChange={(event) => setCatalogVisibility(event.target.value)} disabled={!canWrite || busy}><option value="visible">visible</option><option value="hidden">hidden</option></select></label>
          <Button variant="outline" disabled={!canWrite || busy || !vendorId.trim() || !catalogProduct.trim()} onClick={() => void run(() => api.post(`/api/v2/admin/vendors/${encodeURIComponent(vendorId.trim())}/catalog`, { product_id: catalogProduct.trim(), visibility: catalogVisibility }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap<B2bRecord>(response.data)))}>Assign catalog ownership</Button>
        </div>
        {vendorDetail ? <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(vendorDetail, null, 2)}</pre> : <p className="text-sm text-muted-foreground">No vendor loaded.</p>}
      </CardContent></Card>
    </div>
    <div className="grid gap-4 lg:grid-cols-2">
      <Card><CardHeader><CardTitle>Commission ledger</CardTitle></CardHeader><CardContent className="space-y-3">
        <Button variant="outline" onClick={loadCommissions} disabled={busy}>Load commission ledger</Button>
        {commissions.length > 0 && <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Commission ledger</caption><thead><tr className="border-b"><th scope="col" className="p-2">Entry</th><th scope="col" className="p-2">Vendor</th><th scope="col" className="p-2">Gross</th><th scope="col" className="p-2">Rate (bps)</th></tr></thead><tbody>{commissions.map((entry, index) => <tr key={String(entry.id ?? index)} className="border-b"><td className="p-2 font-mono text-xs">{String(entry.id ?? index)}</td><td className="p-2">{String(entry.vendor_id ?? "-")}</td><td className="p-2">{String(entry.gross_minor ?? "-")} {String(entry.currency ?? "")}</td><td className="p-2">{String(entry.rate_bps ?? "-")}</td></tr>)}</tbody></table></div>}
        <div className="border-t pt-3 space-y-3">
          <label htmlFor="commission-vendor" className="text-sm font-medium">Vendor ID</label><Input id="commission-vendor" value={commissionVendor} onChange={(event) => setCommissionVendor(event.target.value)} disabled={!canWrite || busy} />
          <div className="grid grid-cols-3 gap-3">
            <div><label htmlFor="commission-gross" className="text-sm font-medium">Gross (minor)</label><Input id="commission-gross" type="number" min="0" value={commissionGross} onChange={(event) => setCommissionGross(event.target.value)} disabled={!canWrite || busy} /></div>
            <div><label htmlFor="commission-rate" className="text-sm font-medium">Rate (bps)</label><Input id="commission-rate" type="number" min="0" value={commissionRate} onChange={(event) => setCommissionRate(event.target.value)} disabled={!canWrite || busy} /></div>
            <div><label htmlFor="commission-currency" className="text-sm font-medium">Currency</label><Input id="commission-currency" value={commissionCurrency} onChange={(event) => setCommissionCurrency(event.target.value)} disabled={!canWrite || busy} /></div>
          </div>
          <Button variant="outline" disabled={!canWrite || busy || !commissionVendor.trim() || !commissionGross || !commissionRate} onClick={() => { const gross = validateMinorAmount(commissionGross, "Gross amount"); const rate = parseNonNegativeInt(commissionRate); if (!gross.ok || rate === null || rate > 100000 || !commissionVendor.trim()) { setActionError(new Error(gross.ok ? "Rate must be a whole number of basis points between 0 and 100000." : gross.error)); return; } if (!confirmAction(`Post a commission entry for vendor ${commissionVendor.trim()}?`)) return; void run(() => api.post("/api/v2/admin/commissions", { vendor_id: commissionVendor.trim(), gross_minor: gross.value, rate_bps: rate, currency: commissionCurrency.trim() || "USD" }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap<B2bRecord>(response.data)), () => loadCommissions()); }}>Post commission entry</Button>
          <div className="flex flex-wrap gap-2">
            <Input aria-label="Commission entry ID" placeholder="Entry ID" value={commissionId} onChange={(event) => setCommissionId(event.target.value)} disabled={busy} className="max-w-[12rem]" />
            <Button variant="outline" size="sm" disabled={busy || !commissionId.trim()} onClick={() => void run(() => api.get(`/api/v2/admin/commissions/${encodeURIComponent(commissionId.trim())}`).then((response) => unwrap<B2bRecord>(response.data)), setCommissionDetail)}>Load entry</Button>
          </div>
          {commissionDetail && <pre className="max-h-32 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(commissionDetail, null, 2)}</pre>}
        </div>
      </CardContent></Card>
      <Card><CardHeader><CardTitle>Payouts</CardTitle></CardHeader><CardContent className="space-y-3">
        <label htmlFor="payout-vendor" className="text-sm font-medium">Vendor ID</label><Input id="payout-vendor" value={payoutVendor} onChange={(event) => setPayoutVendor(event.target.value)} disabled={!canWrite || busy} />
        <div className="grid grid-cols-2 gap-3">
          <div><label htmlFor="payout-amount" className="text-sm font-medium">Amount (minor)</label><Input id="payout-amount" type="number" min="0" value={payoutAmount} onChange={(event) => setPayoutAmount(event.target.value)} disabled={!canWrite || busy} /></div>
          <div><label htmlFor="payout-currency" className="text-sm font-medium">Currency</label><Input id="payout-currency" value={payoutCurrency} onChange={(event) => setPayoutCurrency(event.target.value)} disabled={!canWrite || busy} /></div>
        </div>
        <Button variant="outline" disabled={!canWrite || busy || !payoutVendor.trim() || !payoutAmount} onClick={() => { const amount = validateMinorAmount(payoutAmount, "Payout amount"); if (!amount.ok || !payoutVendor.trim()) { setActionError(new Error(amount.ok ? "Vendor ID is required." : amount.error)); return; } if (!confirmAction(`Create a payout of ${amount.value} (minor units) for vendor ${payoutVendor.trim()}?`)) return; void run(() => api.post("/api/v2/admin/payouts", { vendor_id: payoutVendor.trim(), amount_minor: amount.value, currency: payoutCurrency.trim() || "USD" }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap<B2bRecord>(response.data)), setPayoutDetail); }}>Create payout</Button>
        <div className="flex flex-wrap gap-2">
          <Input aria-label="Payout ID" placeholder="Payout ID" value={payoutId} onChange={(event) => setPayoutId(event.target.value)} disabled={busy} className="max-w-[12rem]" />
          <Button variant="outline" size="sm" disabled={busy || !payoutId.trim()} onClick={() => void run(() => api.get(`/api/v2/admin/payouts/${encodeURIComponent(payoutId.trim())}`).then((response) => unwrap<B2bRecord>(response.data)), setPayoutDetail)}>Load payout</Button>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="grid gap-1 text-sm">State<select aria-label="Payout state" className="h-10 rounded-md border bg-background px-3" value={payoutState} onChange={(event) => setPayoutState(event.target.value)} disabled={!canWrite || busy}><option value="eligible">eligible</option><option value="held">held</option></select></label>
          <Button variant="outline" size="sm" disabled={!canWrite || busy || !payoutId.trim()} onClick={() => { if (!confirmAction(`Move payout ${payoutId.trim()} to "${payoutState}"?`)) return; void run(() => api.post(`/api/v2/admin/payouts/${encodeURIComponent(payoutId.trim())}/transition`, { state: payoutState }, { idempotencyKey: idempotencyKey() }).then((response) => unwrap<B2bRecord>(response.data)), setPayoutDetail); }}>Transition payout</Button>
        </div>
        <p className="text-xs text-muted-foreground">Provider execution states are blocked by the backend: no live payout provider is configured.</p>
        {payoutDetail ? <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(payoutDetail, null, 2)}</pre> : <p className="text-sm text-muted-foreground">No payout loaded.</p>}
      </CardContent></Card>
    </div>
    {message && <p role="status" className="text-sm text-emerald-700">{message}</p>}
    {actionError !== undefined ? <ActionError error={actionError} retry={() => setActionError(undefined)} /> : null}
  </div>;
}

function SitesTab() {
  const [stores, setStores] = useState<B2bRecord[]>([]);
  const [channels, setChannels] = useState<B2bRecord[]>([]);
  const [selected, setSelected] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const load = () => { setLoading(true); setError(undefined); void fe1Service.stores().then((value) => { setStores(value); setSelected((current) => current || String(value[0]?.id ?? "")); }).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);
  useEffect(() => { if (selected) void fe1Service.storeChannels(selected).then(setChannels).catch(setError); }, [selected]);
  return <div className="grid gap-4 lg:grid-cols-2"><Card><CardHeader><CardTitle>Stores</CardTitle></CardHeader><CardContent>{loading ? <p role="status">Loading stores...</p> : error ? <ActionError error={error} retry={load} /> : stores.length ? <div className="space-y-2">{stores.map((store, index) => { const id = String(store.id ?? index); return <button type="button" key={id} onClick={() => setSelected(id)} aria-pressed={selected === id} className={`block w-full rounded-md border p-3 text-left ${selected === id ? "border-primary bg-muted" : ""}`}>{String(store.name ?? store.code ?? id)} <span className="text-xs text-muted-foreground">{String(store.status ?? "unknown")}</span></button>; })}</div> : <p className="text-sm text-muted-foreground">No stores returned for the current scope.</p>}</CardContent></Card><Card><CardHeader><CardTitle>Channels</CardTitle></CardHeader><CardContent>{selected ? channels.length ? <ul className="space-y-2">{channels.map((channel, index) => <li className="rounded-md border p-3" key={String(channel.id ?? index)}>{String(channel.name ?? channel.code ?? channel.id ?? index)}</li>)}</ul> : <p className="text-sm text-muted-foreground">No channels returned for this store.</p> : <p className="text-sm text-muted-foreground">Select a store to load channels.</p>}</CardContent></Card></div>;
}

function OrdersTab() {
  const canWrite = canOperateMerchant(useAuthStore((s) => s.user), "b2b");
  const [companyId, setCompanyId] = useState("");
  const [orderId, setOrderId] = useState("");
  const [poNumber, setPoNumber] = useState("");
  const [poTotal, setPoTotal] = useState("");
  const [bulkLines, setBulkLines] = useState('[{"variant_id":"v1","qty":2}]');
  const [result, setResult] = useState<unknown>(null);
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  const run = async (fn: () => Promise<unknown>) => { if (!canWrite || busy) return; setBusy(true); setError(undefined); try { const r = await fn(); setResult(r); } catch (e) { setError(e); } finally { setBusy(false); } };
  return <div className="grid gap-4 lg:grid-cols-2">
    <Card><CardHeader><CardTitle>B2B orders</CardTitle><CardDescription>Approve / bulk validate</CardDescription></CardHeader><CardContent className="space-y-3">
      <Label>Company ID</Label><Input value={companyId} onChange={(e) => setCompanyId(e.target.value)} placeholder="company id" disabled={busy} />
      <Label>B2B Order ID</Label><Input value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="order id" disabled={busy} />
      <div className="flex flex-wrap gap-2"><Button disabled={!canWrite || busy || !orderId.trim()} onClick={() => run(() => api.post(`/api/v2/b2b/orders/${encodeURIComponent(orderId.trim())}/approve`, {}, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data)))}>Approve</Button><Button variant="outline" disabled={!canWrite || busy || !orderId.trim()} onClick={() => run(() => api.post(`/api/v2/b2b/orders/${encodeURIComponent(orderId.trim())}/approve-company`, {}, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data)))}>Approve for company</Button><Button variant="outline" disabled={!canWrite || busy || !orderId.trim()} onClick={() => run(() => api.post(`/api/v2/b2b/orders/${encodeURIComponent(orderId.trim())}/transition`, { state: "draft" }, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data)))}>To draft</Button></div>
      <Label>Bulk lines JSON</Label><Textarea value={bulkLines} onChange={(e) => setBulkLines(e.target.value)} rows={3} disabled={busy} />
      <Button variant="outline" disabled={!canWrite || busy || !companyId.trim()} onClick={() => { let lines: unknown; try { lines = JSON.parse(bulkLines); } catch { setError(new Error("Invalid JSON")); return; } run(() => api.post(`/api/v2/b2b/companies/${encodeURIComponent(companyId.trim())}/orders/validate-bulk`, { lines }, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data))); }}>Validate bulk</Button>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Purchase orders</CardTitle><CardDescription>PO linked to contract</CardDescription></CardHeader><CardContent className="space-y-3">
      <Label>Company ID</Label><Input value={companyId} onChange={(e) => setCompanyId(e.target.value)} placeholder="company id" disabled={busy} />
      <Label>PO number</Label><Input value={poNumber} onChange={(e) => setPoNumber(e.target.value)} placeholder="PO-2026-001" disabled={busy} />
      <Label>Total minor</Label><Input type="number" value={poTotal} onChange={(e) => setPoTotal(e.target.value)} placeholder="100000" disabled={busy} />
      <Button disabled={!canWrite || busy || !companyId.trim() || !poNumber.trim() || !poTotal} onClick={() => { const v = validateMinorAmount(poTotal, "Total"); if (!v.ok) { setError(new Error(v.error)); return; } run(() => api.post(`/api/v2/b2b/companies/${encodeURIComponent(companyId.trim())}/purchase-orders`, { po_number: poNumber.trim(), total_minor: v.value }, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data))); }}>Create PO</Button>
      <Label>PO ID (transition)</Label><Input value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="po id" disabled={busy} />
      <div className="flex flex-wrap gap-2"><Button variant="outline" disabled={!canWrite || busy || !orderId.trim()} onClick={() => run(() => api.post(`/api/v2/b2b/purchase-orders/${encodeURIComponent(orderId.trim())}/approve`, {}, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data)))}>Approve PO</Button><Button variant="outline" disabled={!canWrite || busy || !orderId.trim()} onClick={() => run(() => api.post(`/api/v2/b2b/purchase-orders/${encodeURIComponent(orderId.trim())}/reject`, {}, { idempotencyKey: idempotencyKey() }).then(r => unwrap(r.data)))}>Reject PO</Button></div>
    </CardContent></Card>
    {result ? <pre className="lg:col-span-2 max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre> : null}
    {error ? <div className="lg:col-span-2"><ApiState error={error} onRetry={() => setError(undefined)} /></div> : null}
  </div>;
}

export default function B2bPage() {
  const [tab, setTab] = useState<Tab>("accounts");
  return <RoleGate roles={["owner", "admin"]}><div className="space-y-6"><PageHeader title="B2B operations" description="Organizations, approvals, vendors, and scoped commerce data" /><div role="tablist" aria-label="B2B sections" className="flex max-w-full gap-2 overflow-x-auto border-b pb-2">{(["accounts", "quotes", "vendors", "sites", "orders"] as Tab[]).map((item) => <button key={item} type="button" role="tab" aria-selected={tab === item} onClick={() => setTab(item)} className={`rounded-md px-3 py-2 text-sm font-medium ${tab === item ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}>{item[0].toUpperCase() + item.slice(1)}</button>)}</div>{tab === "accounts" ? <AccountsTab /> : tab === "quotes" ? <QuotesTab /> : tab === "vendors" ? <RoleGate roles={["owner", "admin", "finance"]}><VendorsTab /></RoleGate> : tab === "sites" ? <SitesTab /> : <OrdersTab />}</div></RoleGate>;
}
