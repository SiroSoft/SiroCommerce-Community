"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { ApiState, PermissionState } from "@/modules/merchant/api-state";
import { useAuth } from "@/hooks/use-auth";
import { canOperateMerchant, canUseRoute } from "@/modules/merchant/permissions";
import api from "@/services/api";
import { idempotencyKey } from "@/services/merchant.service";

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : "The request was not accepted. Refresh before retrying.";
}

export function OrderPaymentProofPanel({ orderId }: { orderId: number }) {
  const [payment, setPayment] = useState<Record<string, unknown> | null>(null);
  const [proof, setProof] = useState<Record<string, unknown> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const load = () => {
    setLoading(true); setError(null);
    api.get<{ data?: Record<string, unknown> }>(`/api/admin/orders/${orderId}`).then((res) => {
      const order = (res.data as { data?: Record<string, unknown> }).data;
      const paymentObj = (order as Record<string, unknown>)?.payment as Record<string, unknown> | undefined;
      const pid = (order as Record<string, unknown>)?.payment_id ?? paymentObj?.id;
      if (!pid || typeof pid !== 'string') { setPayment(null); setLoading(false); return; }
      api.get<{ data?: Record<string, unknown> }>(`/api/v2/payments/${encodeURIComponent(pid)}/status`).then((r) => {
        const p = (r.data as { data?: Record<string, unknown> }).data;
        setPayment(p ?? null);
        if (p && (p as Record<string, unknown>).proof_reference) setProof({ reference: (p as Record<string, unknown>).proof_reference, url: (p as Record<string, unknown>).proof_url });
        else {
          // try payment_proofs via order
          api.get<{ data?: Record<string, unknown>[] }>(`/api/admin/orders/${orderId}/timeline`).then((t) => {
            const ev = (t.data as { data?: unknown }).data;
            const arr = Array.isArray(ev) ? ev : [];
            const pr = arr.find((e) => typeof (e as Record<string, unknown>).event_type === 'string' && String((e as Record<string, unknown>).event_type).includes('proof'));
            if (pr) setProof(pr as Record<string, unknown>);
          }).catch(() => {});
        }
        setLoading(false);
      }).catch((e) => { setError(e); setLoading(false); });
    }).catch((e) => { setError(e); setLoading(false); });
  };
  useEffect(load, [orderId]);
  return (
    <Card>
      <CardHeader><CardTitle>Payment proof</CardTitle><CardDescription>Bank transfer / COD proof attached to payment.</CardDescription></CardHeader>
      <CardContent>
        {loading ? <p role="status" className="text-sm text-muted-foreground">Loading proof…</p> : error ? <ApiState error={error} onRetry={load} /> : !payment ? <p className="text-sm text-muted-foreground">No payment found for this order.</p> : (
          <div className="space-y-2 text-sm">
            <p>Provider: <strong>{String((payment as Record<string, unknown>).provider ?? "")}</strong> · State: <strong>{String((payment as Record<string, unknown>).state ?? "")}</strong></p>
            {(payment as Record<string, unknown>).proof_reference ? <p>Reference: <strong>{String((payment as Record<string, unknown>).proof_reference)}</strong></p> : <p className="text-muted-foreground">No proof reference yet (storefront can submit via order detail).</p>}
            {(payment as Record<string, unknown>).proof_url ? <p>Receipt: <a href={String((payment as Record<string, unknown>).proof_url)} target="_blank" rel="noreferrer" className="text-link break-all">{String((payment as Record<string, unknown>).proof_url)}</a>{String((payment as Record<string, unknown>).proof_url).match(/\.(jpg|png|webp)$/i) ? <img src={String((payment as Record<string, unknown>).proof_url)} alt="proof" className="mt-2 max-h-32 rounded border" /> : null}</p> : null}
            {proof && !(payment as Record<string, unknown>).proof_reference ? <pre className="rounded-md bg-muted p-2 text-xs">{JSON.stringify(proof, null, 2)}</pre> : null}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export function OrderTimelinePanel({ orderId }: { orderId: number }) {
  const [events, setEvents] = useState<Record<string, unknown>[] | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    setError(null);
    api
      .get<{ data: unknown }>(`/api/admin/orders/${orderId}/timeline`)
      .then((res) => {
        const raw = res.data.data;
        setEvents(Array.isArray(raw) ? raw : Array.isArray((raw as { events?: unknown })?.events) ? ((raw as { events: Record<string, unknown>[] }).events) : []);
        setLoading(false);
      })
      .catch((cause: unknown) => {
        setError(cause);
        setLoading(false);
      });
  };

  useEffect(load, [orderId]);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Audit timeline</CardTitle>
        <CardDescription>GET /api/admin/orders/{orderId}/timeline.</CardDescription>
      </CardHeader>
      <CardContent>
        {loading ? (
          <p role="status" className="text-sm text-muted-foreground">Loading timeline...</p>
        ) : error ? (
          <ApiState error={error} onRetry={load} />
        ) : !events?.length ? (
          <p className="text-sm text-muted-foreground">No timeline events recorded for this order.</p>
        ) : (
          <ul className="space-y-2">
            {events.map((event, index) => {
              const e = event as Record<string, unknown>;
              return (
                <li key={String(e.id ?? (e as Record<string, unknown>)["id"] ?? index)} className="rounded-md border p-3 text-sm">
                  <p className="font-medium">{String(e.event_type ?? e.type ?? "Event")}</p>
                  <p className="text-xs text-muted-foreground">
                    {String(e.created_at ?? e.occurred_at ?? "")} {e.actor ? `· ${String(e.actor)}` : ""}
                  </p>
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

export function OrderRefundLinesPanel({ orderId }: { orderId: number }) {
  const { user } = useAuth();
  const canRefund = canUseRoute(user, ["owner", "admin", "finance"]);
  const [linesRaw, setLinesRaw] = useState('[{"line_id":"","qty":1}]');
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [confirmMessage, setConfirmMessage] = useState<string | null>(null);

  const run = async (confirmed = false) => {
    if (!canRefund || busy) return;
    let lines: unknown;
    try {
      lines = JSON.parse(linesRaw.trim() || "[]");
    } catch {
      setStatus("Lines must be valid JSON.");
      return;
    }
    if (!Array.isArray(lines) || lines.length === 0) {
      setStatus("Provide at least one line with line_id and qty.");
      return;
    }
    if (!confirmed) {
      setConfirmMessage(`Refund ${lines.length} line(s) on order ${orderId}?`);
      return;
    }
    setConfirmMessage(null);
    setBusy(true);
    setStatus("Working...");
    setError(null);
    try {
      await api.post(
        `/api/admin/orders/${orderId}/refund-lines`,
        { lines, ...(reason.trim() ? { reason: reason.trim() } : {}) },
        { idempotencyKey: idempotencyKey() },
      );
      setStatus("Order lines refunded.");
    } catch (cause) {
      setError(cause);
      setStatus("Refund was not accepted. Refresh before retrying.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Line-item refunds</CardTitle>
        <CardDescription>POST /api/admin/orders/{orderId}/refund-lines.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`refund-lines-${orderId}`}>Lines JSON</Label>
          <Textarea id={`refund-lines-${orderId}`} value={linesRaw} onChange={(e) => setLinesRaw(e.target.value)} rows={3} />
        </div>
        <div className="space-y-1">
          <Label htmlFor={`refund-lines-reason-${orderId}`}>Reason (optional)</Label>
          <Input id={`refund-lines-reason-${orderId}`} value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
        {!canRefund && <PermissionState />}
        <Button disabled={!canRefund || busy} onClick={() => void run()}>
          {busy ? "Refunding..." : "Refund lines"}
        </Button>
        {status && (
          <p role="status" className="text-sm text-muted-foreground">
            {status}
          </p>
        )}
        {error ? <ApiState error={error} /> : null}
        <ConfirmDialog
          open={confirmMessage !== null}
          title="Refund order lines"
          description={confirmMessage ?? ""}
          destructive
          onCancel={() => setConfirmMessage(null)}
          onConfirm={() => void run(true)}
        />
      </CardContent>
    </Card>
  );
}

export function OrderReturnPanel({ orderId }: { orderId: number }) {
  const { user } = useAuth();
  const canReturn = canOperateMerchant(user, "orders");
  const [fulfillmentId, setFulfillmentId] = useState("");
  const [linesRaw, setLinesRaw] = useState('[{"line_id":"","qty":1}]');
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [confirmMessage, setConfirmMessage] = useState<string | null>(null);

  const run = async (confirmed = false) => {
    if (!canReturn || busy) return;
    let lines: unknown;
    try {
      lines = JSON.parse(linesRaw.trim() || "[]");
    } catch {
      setStatus("Lines must be valid JSON.");
      return;
    }
    if (!fulfillmentId.trim() || !Array.isArray(lines) || lines.length === 0) {
      setStatus("Fulfillment ID and at least one line are required.");
      return;
    }
    if (!confirmed) {
      setConfirmMessage(`Request return for order ${orderId}?`);
      return;
    }
    setConfirmMessage(null);
    setBusy(true);
    setStatus("Working...");
    try {
      await api.post(
        "/api/admin/returns",
        { order_id: String(orderId), fulfillment_id: fulfillmentId.trim(), lines, ...(reason.trim() ? { reason: reason.trim() } : {}) },
        { idempotencyKey: idempotencyKey() },
      );
      setStatus("Return requested. Manage it from Fulfillment.");
    } catch (cause) {
      setStatus(messageOf(cause));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Request return</CardTitle>
        <CardDescription>POST /api/admin/returns. Continue the lifecycle under Fulfillment.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`order-return-fulfillment-${orderId}`}>Fulfillment ID</Label>
          <Input
            id={`order-return-fulfillment-${orderId}`}
            value={fulfillmentId}
            onChange={(e) => setFulfillmentId(e.target.value)}
            placeholder="fulfillment id"
          />
        </div>
        <div className="space-y-1">
          <Label htmlFor={`order-return-lines-${orderId}`}>Lines JSON</Label>
          <Textarea id={`order-return-lines-${orderId}`} value={linesRaw} onChange={(e) => setLinesRaw(e.target.value)} rows={3} />
        </div>
        <div className="space-y-1">
          <Label htmlFor={`order-return-reason-${orderId}`}>Reason (optional)</Label>
          <Input id={`order-return-reason-${orderId}`} value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
        {!canReturn && <PermissionState />}
        <Button disabled={!canReturn || busy} onClick={() => void run()}>
          {busy ? "Requesting..." : "Request return"}
        </Button>
        {status && (
          <p role="status" className="text-sm text-muted-foreground">
            {status}
          </p>
        )}
        <ConfirmDialog
          open={confirmMessage !== null}
          title="Request order return"
          description={confirmMessage ?? ""}
          onCancel={() => setConfirmMessage(null)}
          onConfirm={() => void run(true)}
        />
      </CardContent>
    </Card>
  );
}

export function OrderDownloadPanel({ orderId }: { orderId: number }) {
  const { user } = useAuth();
  const canDownload = canOperateMerchant(user, "orders");
  const [assetId, setAssetId] = useState("");
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [confirmMessage, setConfirmMessage] = useState<string | null>(null);

  const run = async (confirmed = false) => {
    if (!canDownload || busy) return;
    if (!assetId.trim()) {
      setStatus("Asset ID is required.");
      return;
    }
    if (!confirmed) {
      setConfirmMessage(`Issue download for asset ${assetId.trim()} on order ${orderId}?`);
      return;
    }
    setConfirmMessage(null);
    setBusy(true);
    setStatus("Working...");
    try {
      await api.post(
        `/api/admin/orders/${orderId}/downloads`,
        { asset_id: assetId.trim(), order_id: String(orderId) },
        { idempotencyKey: idempotencyKey() },
      );
      setStatus("Download issued.");
    } catch (cause) {
      setStatus(messageOf(cause));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Digital downloads</CardTitle>
        <CardDescription>POST /api/admin/orders/{orderId}/downloads with asset_id and order_id.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`order-download-asset-${orderId}`}>Asset ID</Label>
          <Input
            id={`order-download-asset-${orderId}`}
            value={assetId}
            onChange={(e) => setAssetId(e.target.value)}
            placeholder="digital asset id"
          />
        </div>
        <Button variant="outline" disabled={!canDownload || busy} onClick={() => void run()}>
          {busy ? "Working..." : "Issue download"}
        </Button>
        {!canDownload && <PermissionState />}
        {status && (
          <p role="status" className="text-sm text-muted-foreground">
            {status}
          </p>
        )}
        <ConfirmDialog
          open={confirmMessage !== null}
          title="Issue digital download"
          description={confirmMessage ?? ""}
          onCancel={() => setConfirmMessage(null)}
          onConfirm={() => void run(true)}
        />
      </CardContent>
    </Card>
  );
}
