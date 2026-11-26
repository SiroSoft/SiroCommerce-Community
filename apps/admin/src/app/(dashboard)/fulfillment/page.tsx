"use client";

import { useState } from "react";
import { CapabilityGate } from "@/components/capability-gate";
import { PageHeader } from "@/components/page-header";
import { ApiState, PermissionState } from "@/modules/merchant/api-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useAuth } from "@/hooks/use-auth";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import api from "@/services/api";
import { idempotencyKey, merchantService } from "@/services/merchant.service";

type Feedback = { kind: "ok" | "err"; text: string } | null;

function useFeedback() {
  const [feedback, setFeedback] = useState<Feedback>(null);
  return {
    feedback,
    ok: (text: string) => setFeedback({ kind: "ok", text }),
    err: (text: string) => setFeedback({ kind: "err", text }),
    clear: () => setFeedback(null),
  };
}

function FeedbackBanner({ feedback }: { feedback: Feedback }) {
  if (!feedback) return null;
  return (
    <p
      role={feedback.kind === "err" ? "alert" : "status"}
      className={
        feedback.kind === "ok"
          ? "rounded-md border border-emerald-500/30 bg-emerald-500/5 p-3 text-sm"
          : "rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm"
      }
    >
      {feedback.text}
    </p>
  );
}

function errorText(error: unknown): string {
  if (error instanceof Error) return error.message;
  const candidate = error as { message?: unknown; data?: { message?: unknown } };
  if (typeof candidate?.message === "string") return candidate.message;
  const nested = candidate?.data?.message;
  if (typeof nested === "string") return nested;
  return "The request was not accepted. Refresh before retrying.";
}

function parseJsonObject(raw: string, label: string): Record<string, unknown> {
  const trimmed = raw.trim();
  if (!trimmed) return {};
  const parsed: unknown = JSON.parse(trimmed);
  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) {
    throw new Error(`${label} must be a JSON object.`);
  }
  return parsed as Record<string, unknown>;
}

function AllocateCard() {
  const { user } = useAuth();
  const canWrite = canOperateMerchant(user, "inventory");
  const fb = useFeedback();
  const [orderId, setOrderId] = useState("");
  const [linesRaw, setLinesRaw] = useState('[{"variant_id":"variant-1","qty":1}]');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<unknown>(null);
  const [confirmation, setConfirmation] = useState<{ description: string; run: () => void } | null>(null);

  const submit = async (lines: { variant_id: string; qty: number }[], targetOrder: string) => {
    setBusy(true);
    try {
      const out = await merchantService.allocate(
        { order_id: targetOrder, lines },
        idempotencyKey(),
      );
      setResult(out);
      fb.ok("Fulfillment allocated.");
    } catch (error) {
      fb.err(errorText(error));
    } finally {
      setBusy(false);
    }
  };

  const run = () => {
    if (!canWrite || busy) return;
    fb.clear();
    let lines: unknown;
    try {
      lines = JSON.parse(linesRaw.trim() || "[]");
    } catch {
      fb.err("Lines must be valid JSON.");
      return;
    }
    if (!Array.isArray(lines) || lines.length === 0) {
      fb.err("Provide at least one line with variant_id and qty.");
      return;
    }
    for (const line of lines) {
      const row = line as Record<string, unknown>;
      if (typeof row.variant_id !== "string" || !row.variant_id.trim() || !Number.isInteger(row.qty) || (row.qty as number) <= 0) {
        fb.err("Each line needs a variant_id string and a positive integer qty.");
        return;
      }
    }
    if (!orderId.trim()) {
      fb.err("Order ID is required.");
      return;
    }
    const targetOrder = orderId.trim();
    setConfirmation({
      description: `Allocate fulfillment for order ${targetOrder}? This reserves stock against the order.`,
      run: () => void submit(lines as { variant_id: string; qty: number }[], targetOrder),
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Allocate fulfillment</CardTitle>
        <CardDescription>POST /api/admin/fulfillment/allocate. Lines require variant_id and qty.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor="allocate-order">Order ID</Label>
          <Input id="allocate-order" value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="order id" />
        </div>
        <div className="space-y-1">
          <Label htmlFor="allocate-lines">Lines JSON</Label>
          <Textarea id="allocate-lines" value={linesRaw} onChange={(e) => setLinesRaw(e.target.value)} rows={4} />
        </div>
        {!canWrite && <PermissionState />}
        <Button onClick={run} disabled={!canWrite || busy || !orderId.trim()}>
          {busy ? "Allocating..." : "Allocate"}
        </Button>
        <FeedbackBanner feedback={fb.feedback} />
        {result ? <pre className="max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre> : null}
        <ConfirmDialog
          open={confirmation !== null}
          title="Allocate fulfillment"
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

function TrackAdvanceCard() {
  const { user } = useAuth();
  const canWrite = canOperateMerchant(user, "inventory");
  const fb = useFeedback();
  const [id, setId] = useState("");
  const [record, setRecord] = useState<unknown>(null);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [confirmation, setConfirmation] = useState<{ description: string; run: () => void } | null>(null);

  const load = async () => {
    if (!id.trim()) return;
    setLoading(true);
    setLoadError(null);
    fb.clear();
    try {
      setRecord(await merchantService.track(id.trim()));
    } catch (error) {
      setLoadError(error);
      setRecord(null);
    } finally {
      setLoading(false);
    }
  };

  const actionLabel = (action: "ship" | "deliver" | "cancel" | "ready" | "collect") =>
    action === "ship" ? "Ship" : action === "deliver" ? "Deliver" : action === "ready" ? "Mark ready for pickup" : action === "collect" ? "Complete pickup" : "Cancel";

  const act = (action: "ship" | "deliver" | "cancel" | "ready" | "collect") => {
    if (!canWrite || busy || !id.trim()) return;
    const targetId = id.trim();
    setConfirmation({
      description: `${actionLabel(action)} fulfillment ${targetId}? This advances the fulfillment state.`,
      run: () => void execute(action, targetId),
    });
  };

  const execute = async (action: "ship" | "deliver" | "cancel" | "ready" | "collect", targetId: string) => {
    setBusy(true);
    fb.clear();
    try {
      if (action === "ship") await merchantService.ship(targetId, undefined, idempotencyKey());
      else if (action === "deliver") await merchantService.deliver(targetId, idempotencyKey());
      else if (action === "ready") await merchantService.markReady(targetId, undefined, idempotencyKey());
      else if (action === "collect") await merchantService.collect(targetId, idempotencyKey());
      else await merchantService.cancelFulfillment(targetId, idempotencyKey());
      fb.ok(`Fulfillment ${action} accepted.`);
      await load();
    } catch (error) {
      fb.err(errorText(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Track and advance</CardTitle>
        <CardDescription>GET track, then POST ship / deliver / cancel — or ready / collect for pickup — for the same record.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex flex-wrap gap-2">
          <Input aria-label="Fulfillment ID" value={id} onChange={(e) => setId(e.target.value)} placeholder="Enter fulfillment ID" className="max-w-sm" />
          <Button variant="outline" disabled={!id.trim() || loading} onClick={load}>
            {loading ? "Loading..." : "Refresh"}
          </Button>
        </div>
        {loading ? (
          <p role="status" className="text-sm text-muted-foreground">Loading fulfillment...</p>
        ) : loadError ? (
          <ApiState error={loadError} onRetry={load} />
        ) : record ? (
          <div className="space-y-3">
            <pre className="max-h-56 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(record, null, 2)}</pre>
            <div className="flex flex-wrap gap-2">
              <Button disabled={!canWrite || busy} onClick={() => act("ship")}>Ship</Button>
              <Button disabled={!canWrite || busy} onClick={() => act("deliver")}>Deliver</Button>
              <Button disabled={!canWrite || busy} onClick={() => act("ready")}>Mark ready for pickup</Button>
              <Button disabled={!canWrite || busy} onClick={() => act("collect")}>Complete pickup</Button>
              <Button variant="destructive" disabled={!canWrite || busy} onClick={() => act("cancel")}>Cancel</Button>
            </div>
            {!canWrite && <PermissionState />}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Enter an ID to load fulfillment status.</p>
        )}
        <FeedbackBanner feedback={fb.feedback} />
        <ConfirmDialog
          open={confirmation !== null}
          title="Advance fulfillment"
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

function ReturnsCard() {
  const { user } = useAuth();
  const canWrite = canOperateMerchant(user, "orders");
  const fb = useFeedback();
  const [orderId, setOrderId] = useState("");
  const [fulfillmentId, setFulfillmentId] = useState("");
  const [linesRaw, setLinesRaw] = useState('[{"line_id":"line-1","qty":1}]');
  const [reason, setReason] = useState("");
  const [returnId, setReturnId] = useState("");
  const [disposition, setDisposition] = useState("restock");
  const [note, setNote] = useState("");
  const [shippingRaw, setShippingRaw] = useState('{"carrier":"dhl"}');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<unknown>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);

  const executeCallReturn = async (path: string, body: unknown, success: string, target: string) => {
    setBusy(true);
    fb.clear();
    try {
      const res = await api.post<{ data: unknown }>(`/api/admin/returns/${encodeURIComponent(target)}${path}`, body, {
        idempotencyKey: idempotencyKey(),
      });
      setResult(res.data.data);
      fb.ok(success);
    } catch (error) {
      fb.err(errorText(error));
    } finally {
      setBusy(false);
    }
  };

  const callReturn = (path: string, body: unknown, success: string) => {
    if (!canWrite || busy) return;
    const target = returnId.trim();
    if (!target) {
      fb.err("Return ID is required for this action.");
      return;
    }
    setConfirmation({
      title: success.replace(/\.$/, ""),
      description: `${success} — return ${target}? This changes the return lifecycle.`,
      run: () => void executeCallReturn(path, body, success, target),
    });
  };

  const executeRequestReturn = async (lines: unknown, targetOrder: string, targetFulfillment: string) => {
    setBusy(true);
    fb.clear();
    try {
      const res = await api.post<{ data: unknown }>(
        "/api/admin/returns",
        { order_id: targetOrder, fulfillment_id: targetFulfillment, lines, ...(reason.trim() ? { reason: reason.trim() } : {}) },
        { idempotencyKey: idempotencyKey() },
      );
      setResult(res.data.data);
      const created = res.data.data as { id?: unknown };
      if (typeof created?.id === "string") setReturnId(created.id);
      fb.ok("Return requested.");
    } catch (error) {
      fb.err(errorText(error));
    } finally {
      setBusy(false);
    }
  };

  const requestReturn = () => {
    if (!canWrite || busy) return;
    let lines: unknown;
    try {
      lines = JSON.parse(linesRaw.trim() || "[]");
    } catch {
      fb.err("Return lines must be valid JSON.");
      return;
    }
    if (!Array.isArray(lines) || lines.length === 0) {
      fb.err("Provide at least one return line with line_id and qty.");
      return;
    }
    for (const line of lines) {
      const row = line as Record<string, unknown>;
      if (typeof row.line_id !== "string" || !row.line_id.trim() || !Number.isInteger(row.qty) || (row.qty as number) <= 0) {
        fb.err("Each return line needs a line_id string and a positive integer qty.");
        return;
      }
    }
    if (!orderId.trim() || !fulfillmentId.trim()) {
      fb.err("Order ID and fulfillment ID are required.");
      return;
    }
    const targetOrder = orderId.trim();
    const targetFulfillment = fulfillmentId.trim();
    setConfirmation({
      title: "Request return",
      description: `Request return for order ${targetOrder}? This creates a return request against the fulfillment.`,
      run: () => void executeRequestReturn(lines, targetOrder, targetFulfillment),
    });
  };

  const executeCreateLabel = async (shipping: Record<string, unknown>, target: string) => {
    setBusy(true);
    fb.clear();
    try {
      const res = await api.post<{ data: unknown }>(`/api/admin/returns/${encodeURIComponent(target)}/label`, { shipping }, {
        idempotencyKey: idempotencyKey(),
      });
      setResult(res.data.data);
      fb.ok("Return label created.");
    } catch (error) {
      fb.err(errorText(error));
    } finally {
      setBusy(false);
    }
  };

  const createLabel = () => {
    if (!canWrite || busy) return;
    let shipping: Record<string, unknown>;
    try {
      shipping = parseJsonObject(shippingRaw, "Shipping");
    } catch (error) {
      fb.err(errorText(error));
      return;
    }
    if (!returnId.trim()) {
      fb.err("Return ID is required for this action.");
      return;
    }
    const target = returnId.trim();
    setConfirmation({
      title: "Create return label",
      description: `Create return label for ${target}? This requests a label from the shipping provider.`,
      run: () => void executeCreateLabel(shipping, target),
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Returns lifecycle</CardTitle>
        <CardDescription>Request, approve, inspect, complete, refund, reject, and label returns.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid gap-3 md:grid-cols-2">
          <div className="space-y-1">
            <Label htmlFor="return-order">Order ID</Label>
            <Input id="return-order" value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="order id" />
          </div>
          <div className="space-y-1">
            <Label htmlFor="return-fulfillment">Fulfillment ID</Label>
            <Input id="return-fulfillment" value={fulfillmentId} onChange={(e) => setFulfillmentId(e.target.value)} placeholder="fulfillment id" />
          </div>
        </div>
        <div className="space-y-1">
          <Label htmlFor="return-lines">Return lines JSON</Label>
          <Textarea id="return-lines" value={linesRaw} onChange={(e) => setLinesRaw(e.target.value)} rows={3} />
        </div>
        <div className="space-y-1">
          <Label htmlFor="return-reason">Reason (optional)</Label>
          <Input id="return-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="damaged" />
        </div>
        <Button onClick={requestReturn} disabled={!canWrite || busy}>Request return</Button>
        <div className="space-y-1 border-t pt-3">
          <Label htmlFor="return-id">Return ID for lifecycle actions</Label>
          <Input id="return-id" value={returnId} onChange={(e) => setReturnId(e.target.value)} placeholder="return id" />
        </div>
        <div className="grid gap-3 md:grid-cols-2">
          <div className="space-y-1">
            <Label htmlFor="return-disposition">Disposition</Label>
            <Input id="return-disposition" value={disposition} onChange={(e) => setDisposition(e.target.value)} placeholder="restock" />
          </div>
          <div className="space-y-1">
            <Label htmlFor="return-note">Note (optional)</Label>
            <Input id="return-note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="inspection note" />
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={!canWrite || busy} onClick={() => callReturn("/approve", undefined, "Return approved.")}>Approve</Button>
          <Button
            variant="outline"
            disabled={!canWrite || busy || !disposition.trim()}
            onClick={() => callReturn("/inspect", { disposition: disposition.trim(), ...(note.trim() ? { note: note.trim() } : {}) }, "Return inspected.")}
          >
            Inspect
          </Button>
          <Button variant="outline" disabled={!canWrite || busy} onClick={() => callReturn("/complete", undefined, "Return completed.")}>Complete</Button>
          <Button variant="outline" disabled={!canWrite || busy} onClick={() => callReturn("/approve-refund", undefined, "Return refund approved.")}>Approve refund</Button>
          <Button variant="outline" disabled={!canWrite || busy} onClick={() => callReturn("/reject", note.trim() ? { note: note.trim() } : {}, "Return rejected.")}>Reject</Button>
        </div>
        <div className="space-y-1">
          <Label htmlFor="return-shipping">Label shipping JSON</Label>
          <Textarea id="return-shipping" value={shippingRaw} onChange={(e) => setShippingRaw(e.target.value)} rows={2} />
        </div>
        <Button variant="outline" disabled={!canWrite || busy} onClick={createLabel}>Create label</Button>
        {!canWrite && <PermissionState />}
        <FeedbackBanner feedback={fb.feedback} />
        {result ? <pre className="max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre> : null}
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

function ShipmentsCard() {
  const { user } = useAuth();
  const canWrite = canOperateMerchant(user, "inventory");
  const fb = useFeedback();
  const [quoteRaw, setQuoteRaw] = useState('{"origin":{},"destination":{},"packages":[]}');
  const [shipmentRaw, setShipmentRaw] = useState('{"shipment":{}}');
  const [shipmentId, setShipmentId] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<unknown>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);

  const execute = async (label: string, fn: () => Promise<unknown>) => {
    setBusy(true);
    fb.clear();
    try {
      const out = await fn();
      setResult(out);
      fb.ok(`${label} accepted.`);
    } catch (error) {
      fb.err(errorText(error));
    } finally {
      setBusy(false);
    }
  };

  const run = (label: string, fn: () => Promise<unknown>) => {
    if (!canWrite || busy) return;
    setConfirmation({
      title: label,
      description: `${label}? This talks to the shipping provider and cannot be undone from the console.`,
      run: () => void execute(label, fn),
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Shipments and labels</CardTitle>
        <CardDescription>Quote rates, queue shipments, track, and cancel via the shipping contract.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor="ship-quote">Rate quote JSON ({`{origin, destination, packages}`})</Label>
          <Textarea id="ship-quote" value={quoteRaw} onChange={(e) => setQuoteRaw(e.target.value)} rows={3} />
        </div>
        <Button
          variant="outline"
          disabled={!canWrite || busy}
          onClick={() => run("Quote shipping rates", async () => {
            const body = parseJsonObject(quoteRaw, "Quote");
            const res = await api.post<{ data: unknown }>("/api/v2/shipping/rates", body);
            return res.data.data;
          })}
        >
          Quote rates
        </Button>
        <div className="space-y-1 border-t pt-3">
          <Label htmlFor="ship-create">Create shipment JSON ({`{shipment}`})</Label>
          <Textarea id="ship-create" value={shipmentRaw} onChange={(e) => setShipmentRaw(e.target.value)} rows={3} />
        </div>
        <Button
          disabled={!canWrite || busy}
          onClick={() => run("Queue shipment", async () => {
            const body = parseJsonObject(shipmentRaw, "Shipment");
            const res = await api.post<{ data: unknown }>("/api/v2/shipments", body, { idempotencyKey: idempotencyKey() });
            return res.data.data;
          })}
        >
          Queue shipment
        </Button>
        <div className="space-y-1 border-t pt-3">
          <Label htmlFor="ship-id">Shipment ID</Label>
          <Input id="ship-id" value={shipmentId} onChange={(e) => setShipmentId(e.target.value)} placeholder="shipment id" />
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            disabled={!canWrite || busy || !shipmentId.trim()}
            onClick={() => run("Refresh shipment tracking", async () => {
              const res = await api.get<{ data: unknown }>(`/api/v2/shipments/${encodeURIComponent(shipmentId.trim())}/tracking`);
              return res.data.data;
            })}
          >
            Refresh tracking
          </Button>
          <Button
            variant="destructive"
            disabled={!canWrite || busy || !shipmentId.trim()}
            onClick={() => run("Cancel shipment", async () => {
              const res = await api.post<{ data: unknown }>(`/api/v2/shipments/${encodeURIComponent(shipmentId.trim())}/cancel`, undefined, {
                idempotencyKey: idempotencyKey(),
              });
              return res.data.data;
            })}
          >
            Cancel shipment
          </Button>
        </div>
        {!canWrite && <PermissionState />}
        <FeedbackBanner feedback={fb.feedback} />
        {result ? <pre className="max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(result, null, 2)}</pre> : null}
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

function FulfillmentContent() {
  return (
    <div className="space-y-5">
      <PageHeader
        title="Fulfillment"
        description="Allocate, track, ship, deliver, cancel, return, and label through the Admin API contract."
      />
      <Badge variant="outline">Live API contract — no list endpoint exists; work by record ID.</Badge>
      <div className="grid gap-5 xl:grid-cols-2">
        <AllocateCard />
        <TrackAdvanceCard />
        <ReturnsCard />
        <ShipmentsCard />
      </div>
    </div>
  );
}

export default function FulfillmentPage() {
  return (
    <CapabilityGate capability="inventory">
      <FulfillmentContent />
    </CapabilityGate>
  );
}
