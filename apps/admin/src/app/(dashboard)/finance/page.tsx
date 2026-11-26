"use client";

import { useQuery } from "@tanstack/react-query";
import { useCallback, useRef, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { RoleGate } from "@/components/capability-gate";
import { PageHeader } from "@/components/page-header";
import { Pagination } from "@/components/pagination";
import { ApiState } from "@/modules/merchant/api-state";
import { ErrorState } from "@/components/error-state";
import { financialService } from "@/services/financial.service";
import { idempotencyKey } from "@/services/merchant.service";
import api from "@/services/api";
const formatCurrency = (minor: number, currency: string, minorUnit: number) => new Intl.NumberFormat(undefined, { style: "currency", currency }).format(minor / 10 ** minorUnit);

type Invoice = { id: string; number?: string; order_id?: string; payment_id?: string; state?: string; currency: string; minor_unit: number; subtotal_minor?: number; discount_minor?: number; shipping_minor?: number; tax_minor?: number; amount_minor: number; issued_at?: string | null; created_at?: string };
type CreditMemo = { id: string; number?: string; invoice_id?: string; order_id?: string; state?: string; currency: string; minor_unit: number; amount_minor: number; reason?: string; created_at?: string };

const unwrapData = <T,>(envelope: unknown): T => {
  const body = envelope as { data?: T };
  return (body && typeof body === "object" && "data" in body ? body.data : envelope) as T;
};

export default function FinancePage() {
  return <RoleGate roles={["owner", "admin", "finance"]}><FinanceContent /></RoleGate>;
}

function FinanceContent() {
  const [page, setPage] = useState(1);
  const query = useQuery({ queryKey: ["financial-reconciliation", page], queryFn: () => financialService.reconciliation(page) });
  if (query.isLoading) return <div role="status">Loading financial reconciliation...</div>;
  if (query.isError) return <ErrorState message="Financial reconciliation is unavailable." onRetry={() => void query.refetch()} />;
  const data = query.data;
  return <div className="space-y-5"><PageHeader title="Financial reconciliation" description="Read-only evidence for invoices, credited amounts, and settlement state. Amounts and statuses are backend-authoritative." /><Card><CardContent className="p-0"><div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">Invoice reconciliation</caption><thead><tr className="border-b text-left text-muted-foreground"><th className="p-4">Invoice</th><th className="p-4">State</th><th className="p-4">Amount</th><th className="p-4">Credited</th><th className="p-4">Evidence</th></tr></thead><tbody>{data?.data.map((row) => <tr className="border-b last:border-0" key={row.invoice_id}><td className="p-4"><strong>{row.invoice_number}</strong><span className="block text-xs text-muted-foreground">{row.order_id}</span></td><td className="p-4"><Badge variant={row.reconciliation === "over_credited" ? "destructive" : "outline"}>{row.state} · {row.reconciliation}</Badge></td><td className="p-4">{formatCurrency(row.amount_minor, row.currency, row.minor_unit)}</td><td className="p-4">{formatCurrency(row.credited_minor, row.currency, row.minor_unit)}</td><td className="p-4">{row.evidence_available ? "Available" : "Unavailable"}</td></tr>)}</tbody></table>{data?.data.length === 0 && <p className="p-6 text-muted-foreground">No invoices are available in this scope.</p>}</div></CardContent></Card>{data?.meta && <Pagination meta={data.meta as never} onPageChange={setPage} />}<InvoiceWorkspace /><PaymentReconciliation /></div>;
}

function InvoiceWorkspace() {
  const [invoiceId, setInvoiceId] = useState("");
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [evidence, setEvidence] = useState<Record<string, unknown> | null>(null);
  const [orderId, setOrderId] = useState("");
  const [paymentId, setPaymentId] = useState("");
  const [memoAmount, setMemoAmount] = useState("");
  const [memoReason, setMemoReason] = useState("");
  const [memoLookup, setMemoLookup] = useState("");
  const [memo, setMemo] = useState<CreditMemo | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(undefined);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);
  const lastAction = useRef<{ action: () => Promise<void>; success: string } | null>(null);

  const run = useCallback(async (action: () => Promise<void>, success: string) => {
    lastAction.current = { action, success };
    setBusy(true);
    setError(undefined);
    setNotice(null);
    try {
      await action();
      setNotice(success);
    } catch (cause) {
      setError(cause);
    } finally {
      setBusy(false);
    }
  }, []);

  const retry = useCallback(() => {
    const last = lastAction.current;
    if (last && !busy) void run(last.action, last.success);
  }, [busy, run]);

  const lookup = useCallback(() => {
    const id = invoiceId.trim();
    if (!id) return;
    void run(async () => {
      const response = await api.get<{ data: Invoice }>(`/api/admin/invoices/${encodeURIComponent(id)}`);
      setInvoice(unwrapData<Invoice>(response.data));
      setEvidence(null);
    }, "Invoice loaded from the backend.");
  }, [invoiceId, run]);

  const create = useCallback(() => {
    const order = orderId.trim();
    const payment = paymentId.trim();
    if (!order || !payment) return;
    void run(async () => {
      const response = await api.post<{ data: Invoice }>("/api/admin/invoices", { order_id: order, payment_id: payment }, { idempotencyKey: idempotencyKey() });
      const created = unwrapData<Invoice>(response.data);
      setInvoice(created);
      setInvoiceId(created.id);
      setEvidence(null);
    }, "Invoice created.");
  }, [orderId, paymentId, run]);

  const issue = useCallback(() => {
    const id = invoice?.id ?? invoiceId.trim();
    if (!id) return;
    setConfirmation({
      title: "Issue invoice",
      description: `Issue invoice ${id}? Issued invoices become payable evidence.`,
      run: () => void run(async () => {
        const response = await api.post<{ data: Invoice }>(`/api/admin/invoices/${encodeURIComponent(id)}/issue`, undefined, { idempotencyKey: idempotencyKey() });
        setInvoice(unwrapData<Invoice>(response.data));
      }, "Invoice issued."),
    });
  }, [invoice, invoiceId, run]);

  const loadEvidence = useCallback(() => {
    const id = invoice?.id ?? invoiceId.trim();
    if (!id) return;
    void run(async () => {
      const response = await api.get<{ data: Record<string, unknown> }>(`/api/admin/invoices/${encodeURIComponent(id)}/evidence`);
      setEvidence(unwrapData<Record<string, unknown>>(response.data));
    }, "Invoice evidence loaded.");
  }, [invoice, invoiceId, run]);

  const createMemo = useCallback(() => {
    const id = invoice?.id ?? invoiceId.trim();
    const amount = Number(memoAmount);
    if (!id || !Number.isInteger(amount) || amount <= 0) return;
    setConfirmation({
      title: "Issue credit memo",
      description: `Issue a credit memo of ${amount} (minor units) against invoice ${id}? This credits the customer.`,
      run: () => void run(async () => {
        const response = await api.post<{ data: CreditMemo }>(`/api/admin/invoices/${encodeURIComponent(id)}/credit-memos`, { amount_minor: amount, reason: memoReason.trim() || undefined }, { idempotencyKey: idempotencyKey() });
        setMemo(unwrapData<CreditMemo>(response.data));
      }, "Credit memo issued."),
    });
  }, [invoice, invoiceId, memoAmount, memoReason, run]);

  const lookupMemo = useCallback(() => {
    const id = memoLookup.trim();
    if (!id) return;
    void run(async () => {
      const response = await api.get<{ data: CreditMemo }>(`/api/admin/credit-memos/${encodeURIComponent(id)}`);
      setMemo(unwrapData<CreditMemo>(response.data));
    }, "Credit memo loaded from the backend.");
  }, [memoLookup, run]);

  return <Card><CardHeader><CardTitle>Invoice operations</CardTitle></CardHeader><CardContent className="space-y-4 text-sm">
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="space-y-3 rounded-md border p-4">
        <h3 className="font-medium">Find an invoice</h3>
        <div className="flex flex-wrap gap-2">
          <Input aria-label="Invoice ID" placeholder="Invoice ID" value={invoiceId} onChange={(event) => setInvoiceId(event.target.value)} disabled={busy} className="max-w-xs" />
          <Button variant="outline" size="sm" onClick={lookup} disabled={busy || !invoiceId.trim()}>Load invoice</Button>
        </div>
        {invoice && <div className="space-y-1 text-xs">
          <div className="flex items-center gap-2"><strong>{invoice.number ?? invoice.id}</strong> · <Badge variant="outline">{invoice.state ?? "unknown"}</Badge></div>
          <p className="text-muted-foreground">Order {invoice.order_id ?? "-"} · Payment {invoice.payment_id ?? "-"}</p>
          <p>Amount: {formatCurrency(invoice.amount_minor, invoice.currency, invoice.minor_unit)}{invoice.issued_at ? ` · Issued ${invoice.issued_at}` : " · Not issued"}</p>
        </div>}
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={loadEvidence} disabled={busy || !(invoice?.id ?? invoiceId.trim())}>Load evidence</Button>
          <Button size="sm" onClick={issue} disabled={busy || !(invoice?.id ?? invoiceId.trim())}>Issue invoice</Button>
        </div>
        {evidence && <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(evidence, null, 2)}</pre>}
      </div>
      <div className="space-y-3 rounded-md border p-4">
        <h3 className="font-medium">Create an invoice</h3>
        <Input aria-label="Order ID" placeholder="Order ID" value={orderId} onChange={(event) => setOrderId(event.target.value)} disabled={busy} />
        <Input aria-label="Payment ID" placeholder="Payment ID" value={paymentId} onChange={(event) => setPaymentId(event.target.value)} disabled={busy} />
        <Button size="sm" onClick={create} disabled={busy || !orderId.trim() || !paymentId.trim()}>Create invoice</Button>
      </div>
    </div>
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="space-y-3 rounded-md border p-4">
        <h3 className="font-medium">Issue a credit memo</h3>
        <Input aria-label="Credit memo amount in minor units" type="number" min="1" placeholder="Amount in minor units" value={memoAmount} onChange={(event) => setMemoAmount(event.target.value)} disabled={busy} />
        <Input aria-label="Credit memo reason" placeholder="Reason (optional)" value={memoReason} onChange={(event) => setMemoReason(event.target.value)} disabled={busy} />
        <Button size="sm" variant="outline" onClick={createMemo} disabled={busy || !(invoice?.id ?? invoiceId.trim()) || !Number.isInteger(Number(memoAmount)) || Number(memoAmount) <= 0}>Issue credit memo</Button>
        <p className="text-xs text-muted-foreground">The memo is attached to the loaded invoice above.</p>
      </div>
      <div className="space-y-3 rounded-md border p-4">
        <h3 className="font-medium">Find a credit memo</h3>
        <div className="flex flex-wrap gap-2">
          <Input aria-label="Credit memo ID" placeholder="Credit memo ID" value={memoLookup} onChange={(event) => setMemoLookup(event.target.value)} disabled={busy} className="max-w-xs" />
          <Button variant="outline" size="sm" onClick={lookupMemo} disabled={busy || !memoLookup.trim()}>Load credit memo</Button>
        </div>
        {memo && <div className="space-y-1 text-xs">
          <div className="flex items-center gap-2"><strong>{memo.number ?? memo.id}</strong> · <Badge variant="outline">{memo.state ?? "unknown"}</Badge></div>
          <p>Amount: {formatCurrency(memo.amount_minor, memo.currency, memo.minor_unit)}</p>
          {memo.reason && <p className="text-muted-foreground">Reason: {memo.reason}</p>}
        </div>}
      </div>
    </div>
    {notice && <p role="status" className="text-emerald-700">{notice}</p>}
    {error !== undefined && <ApiState error={error} onRetry={retry} />}
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
  </CardContent></Card>;
}

function PaymentReconciliation() {
  const [paymentId, setPaymentId] = useState("");
  const [payment, setPayment] = useState<Record<string, unknown> | null>(null);
  const [result, setResult] = useState<Record<string, unknown> | null>(null);
  const [proofRef, setProofRef] = useState("");
  const [captureAmount, setCaptureAmount] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(undefined);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);
  const lastOp = useRef<{ kind: "load"; paymentId: string } | { kind: "reconcile"; apply: boolean } | null>(null);

  const loadPayment = useCallback(() => {
    const id = paymentId.trim();
    if (!id) return;
    lastOp.current = { kind: "load", paymentId: id };
    setBusy(true);
    setError(undefined);
    setNotice(null);
    void api.get<{ data: Record<string, unknown> }>(`/api/v2/payments/${encodeURIComponent(id)}/status`)
      .then((response) => {
        const loaded = unwrapData<Record<string, unknown>>(response.data);
        setPayment(loaded);
        // Prefill the capture proof from the storefront submission so finance
        // doesn't retype it (capture still enforces it server-side).
        if (typeof loaded.proof_reference === "string" && loaded.proof_reference) setProofRef(loaded.proof_reference);
        setNotice("Payment status loaded.");
      })
      .catch((cause: unknown) => setError(cause))
      .finally(() => setBusy(false));
  }, [paymentId]);

  const executeReconcile = useCallback((apply: boolean) => {
    lastOp.current = { kind: "reconcile", apply };
    setBusy(true);
    setError(undefined);
    setNotice(null);
    void api.post<{ data: Record<string, unknown> }>("/api/admin/payments/reconcile", { apply }, { idempotencyKey: idempotencyKey() })
      .then((response) => { setResult(unwrapData<Record<string, unknown>>(response.data)); setNotice(apply ? "Reconciliation applied." : "Reconciliation dry-run complete."); })
      .catch((cause: unknown) => setError(cause))
      .finally(() => setBusy(false));
  }, []);

  const reconcile = useCallback((apply: boolean) => {
    if (!apply) {
      executeReconcile(false);
      return;
    }
    setConfirmation({
      title: "Apply reconciliation",
      description: "Apply payment reconciliation fixes? This writes corrections to payment state.",
      run: () => executeReconcile(true),
    });
  }, [executeReconcile]);

  const retry = useCallback(() => {
    const last = lastOp.current;
    if (!last || busy) return;
    if (last.kind === "load") {
      setPaymentId(last.paymentId);
      lastOp.current = { kind: "load", paymentId: last.paymentId };
      setBusy(true);
      setError(undefined);
      void api.get<{ data: Record<string, unknown> }>(`/api/v2/payments/${encodeURIComponent(last.paymentId)}/status`)
        .then((response) => { setPayment(unwrapData<Record<string, unknown>>(response.data)); setNotice("Payment status loaded."); })
        .catch((cause: unknown) => setError(cause))
        .finally(() => setBusy(false));
    } else {
      reconcile(last.apply);
    }
  }, [busy, reconcile]);

  const executeCapture = useCallback((id: string, body: Record<string, unknown>) => {
    setBusy(true); setError(undefined); setNotice(null);
    void api.post<{ data: Record<string, unknown> }>(`/api/v2/payments/${encodeURIComponent(id)}/capture`, body, { idempotencyKey: idempotencyKey() })
      .then((r) => { setPayment(unwrapData<Record<string, unknown>>(r.data)); setNotice(`Payment ${id} captured${proofRef ? ` (proof ${proofRef})` : ""}.`); })
      .catch((c: unknown) => setError(c)).finally(() => setBusy(false));
  }, [proofRef]);

  const doCapture = useCallback(() => {
    const id = paymentId.trim() || String(payment?.id ?? "");
    if (!id) return;
    const provider = String((payment as Record<string, unknown>)?.provider ?? "");
    const isOffline = provider === "bank_transfer" || provider === "cod";
    if (isOffline && !proofRef.trim()) { setError(new Error("Proof reference is required for COD / bank transfer capture")); return; }
    const body: Record<string, unknown> = {};
    const amt = captureAmount.trim() ? Number(captureAmount) : Number((payment as Record<string, unknown>)?.amount_minor ?? 0);
    if (Number.isInteger(amt) && amt > 0) { body.amount_minor = amt; body.currency = String((payment as Record<string, unknown>)?.currency ?? "VND"); }
    if (proofRef.trim()) body.proof_reference = proofRef.trim();
    setConfirmation({
      title: "Capture payment",
      description: `Capture payment ${id}${proofRef ? ` with proof ${proofRef}` : ""}? This confirms collection and cannot be undone.`,
      run: () => executeCapture(id, body),
    });
  }, [payment, paymentId, proofRef, captureAmount, executeCapture]);

  const executeVoid = useCallback((id: string) => {
    setBusy(true); setError(undefined);
    void api.post<{ data: Record<string, unknown> }>(`/api/v2/payments/${encodeURIComponent(id)}/void`, {}, { idempotencyKey: idempotencyKey() })
      .then((r) => { setPayment(unwrapData<Record<string, unknown>>(r.data)); setNotice(`Payment ${id} voided.`); })
      .catch((c: unknown) => setError(c)).finally(() => setBusy(false));
  }, []);

  const doVoid = useCallback(() => {
    const id = paymentId.trim() || String(payment?.id ?? "");
    if (!id) return;
    setConfirmation({
      title: "Void payment",
      description: `Void payment ${id}? This cancels the payment and cannot be undone.`,
      run: () => executeVoid(id),
    });
  }, [paymentId, payment, executeVoid]);

  return <Card><CardHeader><CardTitle>Payment reconciliation</CardTitle><p className="text-xs text-muted-foreground">COD / bank transfer: capture confirms collection · bank transfer requires proof reference · card captures are live gateway</p></CardHeader><CardContent className="space-y-3 text-sm">
    <div className="flex flex-wrap gap-2">
      <Input aria-label="Payment ID" placeholder="Payment ID" value={paymentId} onChange={(event) => setPaymentId(event.target.value)} disabled={busy} className="max-w-xs" />
      <Button variant="outline" size="sm" onClick={loadPayment} disabled={busy || !paymentId.trim()}>{busy ? "Working..." : "Load payment status"}</Button>
      <Button variant="outline" size="sm" onClick={() => reconcile(false)} disabled={busy}>{busy ? "Working..." : "Dry-run reconcile"}</Button>
      <Button size="sm" onClick={() => reconcile(true)} disabled={busy}>{busy ? "Working..." : "Apply reconcile"}</Button>
    </div>
    {payment && <div className="rounded-md border p-3 space-y-2">
      <div className="flex flex-wrap items-center gap-2 text-sm"><strong>{String(payment.id)}</strong> · provider <Badge variant="outline">{String((payment as Record<string, unknown>).provider ?? "unknown")}</Badge> · state <Badge>{String((payment as Record<string, unknown>).state ?? "")}</Badge> · {String((payment as Record<string, unknown>).amount_minor ?? "")} {String((payment as Record<string, unknown>).currency ?? "")}</div>
      {String((payment as Record<string, unknown>).provider) === "bank_transfer" && <p className="text-xs text-muted-foreground">Bank transfer · IBAN VN00 SIRO 0000 0000 · verify transfer receipt before capture. Proof reference is required.</p>}
      {String((payment as Record<string, unknown>).provider) === "cod" && <p className="text-xs text-muted-foreground">COD · capture confirms cash collected on delivery.</p>}
      <div className="grid gap-2 md:grid-cols-3">
        <div><label htmlFor="cap-amt" className="text-xs font-medium">Capture amount (minor, optional)</label><Input id="cap-amt" type="number" min="0" value={captureAmount} onChange={(e) => setCaptureAmount(e.target.value)} placeholder={String((payment as Record<string, unknown>).amount_minor ?? "")} disabled={busy} /></div>
        <div><label htmlFor="cap-proof" className="text-xs font-medium">Proof reference (bank transfer required)</label><Input id="cap-proof" value={proofRef} onChange={(e) => setProofRef(e.target.value)} placeholder="FT123456 / receipt URL" disabled={busy} /></div>
        <div className="flex items-end gap-2"><Button size="sm" onClick={doCapture} disabled={busy || !paymentId.trim()}>{busy ? "Capturing..." : "Capture payment"}</Button><Button size="sm" variant="outline" onClick={doVoid} disabled={busy || !paymentId.trim()}>Void</Button></div>
      </div>
    </div>}
    {notice && <p role="status" className="text-emerald-700">{notice}</p>}
    {payment && <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(payment, null, 2)}</pre>}
    {result && <pre className="max-h-44 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre>}
    {error !== undefined && <ApiState error={error} onRetry={retry} />}
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
  </CardContent></Card>;
}
