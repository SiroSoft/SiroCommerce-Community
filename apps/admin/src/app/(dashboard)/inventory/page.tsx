"use client";

import { useRef, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { useInventory, useInventoryMutation, useInventorySources } from "@/hooks/use-merchant";
import { ApiState, PermissionState } from "@/modules/merchant/api-state";
import { useAuth } from "@/hooks/use-auth";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { inventoryAvailable } from "@/modules/merchant/contracts";

type InventoryConfirmation = { title: string; description: string; run: () => void };

export default function InventoryRoute() {
  return <CapabilityGate capability="inventory"><InventoryContent /></CapabilityGate>;
}

function InventoryContent() {
  const [confirmation, setConfirmation] = useState<InventoryConfirmation | null>(null);
  const [variant, setVariant] = useState("");
  const [onHand, setOnHand] = useState("");
  const [fromSource, setFromSource] = useState("");
  const [toSource, setToSource] = useState("");
  const [transferQty, setTransferQty] = useState("");
  const query = useInventory(variant);
  const sources = useInventorySources(variant);
  const sourceRows = Array.isArray(sources.data) ? sources.data : [];
  const adjust = useInventoryMutation("adjust", variant);
  const transfer = useInventoryMutation("transfer", variant);
  const adjustLock = useRef(false);
  const transferLock = useRef(false);
  const lastAdjust = useRef<{ on_hand: number } | null>(null);
  const lastTransfer = useRef<{ from_source_id: string; to_source_id: string; qty: number } | null>(null);
  const { user } = useAuth();
  const canOperate = canOperateMerchant(user, "inventory");
  const item = query.data;
  const belowReserved = !!item && Number.isInteger(Number(onHand)) && Number(onHand) < item.reserved;
  const adjustmentValid = !!item && inventoryAvailable(item.on_hand, item.reserved, item.available) !== null && onHand !== "" && Number.isInteger(Number(onHand)) && Number(onHand) >= item.reserved;
  const currentAvailable = item ? inventoryAvailable(item.on_hand, item.reserved, item.available) : null;
  const transferValid = !!variant.trim() && !!fromSource.trim() && !!toSource.trim() && fromSource.trim() !== toSource.trim() && Number.isInteger(Number(transferQty)) && Number(transferQty) > 0;

  return <div className="space-y-5">
    <PageHeader title="Inventory" description="Inspect live stock for a variant." />
    <Card><CardHeader><CardTitle>Variant stock</CardTitle></CardHeader><CardContent className="space-y-4">
      <div className="flex flex-wrap gap-2"><Input className="max-w-sm" aria-label="Variant ID" value={variant} onChange={(event) => { setVariant(event.target.value); }} placeholder="Enter variant ID" /></div>
      {query.isLoading ? <p role="status" className="py-8 text-center text-sm text-muted-foreground">Loading inventory...</p> : query.isError ? <ApiState error={query.error} onRetry={() => query.refetch()} /> : item && currentAvailable !== null ? <InventoryTable variant={item.variant_id} source={item.source_id} onHand={item.on_hand} reserved={item.reserved} available={currentAvailable} /> : item ? <p role="alert" className="py-8 text-center text-sm text-destructive">Inventory data violates the on-hand and reserved invariant.</p> : <p className="py-8 text-center text-sm text-muted-foreground">Enter a variant ID to load live inventory.</p>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Stock by store / source</CardTitle></CardHeader><CardContent>
      {!variant.trim() ? <p className="py-4 text-center text-sm text-muted-foreground">Type a variant above to see per-source rows.</p>
      : sources.isLoading ? <p role="status" className="py-4 text-center text-sm text-muted-foreground">Loading sources...</p>
      : sources.isError ? <ApiState error={sources.error} onRetry={() => sources.refetch()} />
      : sourceRows.length === 0 ? <p className="py-4 text-center text-sm text-muted-foreground">No stock rows for this variant. Adjust or transfer to create a source row.</p>
      : <div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">Stock by source for {variant}</caption><thead><tr className="border-b text-left text-muted-foreground"><th className="p-3">Source</th><th className="p-3">On hand</th><th className="p-3">Reserved</th><th className="p-3">Available</th></tr></thead><tbody>{sourceRows.map((row, i) => <tr key={String(row.source_id ?? i)} className="border-b last:border-0"><td className="p-3 font-medium">{String(row.source_id)}</td><td className="p-3">{String(row.on_hand)}</td><td className="p-3">{String(row.reserved)}</td><td className="p-3 font-semibold">{String(row.available)}</td></tr>)}</tbody></table></div>}
    </CardContent></Card>
    <div className="grid gap-5 lg:grid-cols-2">
      <Card><CardHeader><CardTitle>Adjust on-hand</CardTitle></CardHeader><CardContent className="space-y-3">{!canOperate && <PermissionState />}<p className="text-sm text-muted-foreground">The contract accepts an absolute on-hand value. Reserved and available are returned by the service.</p><Input aria-label="New on-hand quantity" type="number" min="0" value={onHand} onChange={(event) => setOnHand(event.target.value)} placeholder="New on-hand quantity" />{belowReserved && <p role="alert" className="text-sm text-destructive">On-hand cannot be below reserved stock ({item?.reserved}). No request will be sent.</p>}<Button onClick={() => { if (!adjustmentValid || adjustLock.current) return; setConfirmation({ title: "Apply inventory adjustment", description: `Set on-hand to ${Number(onHand)} for variant ${variant.trim()}? Reserved stock is ${item?.reserved ?? 0}.`, run: () => { adjustLock.current = true; const payload = { on_hand: Number(onHand) }; lastAdjust.current = payload; adjust.mutate(payload, { onSettled: () => { adjustLock.current = false; } }); } }); }} disabled={!canOperate || !adjustmentValid || adjust.isPending}>{adjust.isPending ? "Applying..." : "Apply adjustment"}</Button>{adjust.isError && <ApiState error={adjust.error} onRetry={() => { if (lastAdjust.current && !adjust.isPending) adjust.mutate(lastAdjust.current); }} />} {adjust.isSuccess && <p role="status" className="text-sm text-muted-foreground">Adjustment accepted. Refreshing ATP from the inventory service.</p>}</CardContent></Card>
      <Card><CardHeader><CardTitle>Transfer stock</CardTitle></CardHeader><CardContent className="space-y-3">{!canOperate && <PermissionState />}<Input aria-label="Source ID" value={fromSource} onChange={(event) => setFromSource(event.target.value)} placeholder="Source ID" /><Input aria-label="Destination source ID" value={toSource} onChange={(event) => setToSource(event.target.value)} placeholder="Destination source ID" /><Input aria-label="Transfer quantity" type="number" min="1" value={transferQty} onChange={(event) => setTransferQty(event.target.value)} placeholder="Quantity" /><Button onClick={() => { if (!transferValid || transferLock.current) return; setConfirmation({ title: "Transfer inventory", description: `Transfer ${Number(transferQty)} units from ${fromSource.trim()} to ${toSource.trim()}?`, run: () => { transferLock.current = true; const payload = { from_source_id: fromSource.trim(), to_source_id: toSource.trim(), qty: Number(transferQty) }; lastTransfer.current = payload; transfer.mutate(payload, { onSettled: () => { transferLock.current = false; } }); } }); }} disabled={!canOperate || !transferValid || transfer.isPending}>{transfer.isPending ? "Transferring..." : "Transfer stock"}</Button>{transfer.isError && <ApiState error={transfer.error} onRetry={() => { if (lastTransfer.current && !transfer.isPending) transfer.mutate(lastTransfer.current); }} />}{transfer.isSuccess && <p role="status" className="text-sm text-muted-foreground">Transfer accepted. Stock levels refresh from the inventory service.</p>}</CardContent></Card>
      <StockAlertsCard />
      <ReplenishmentCard />
    </div>
    <ConfirmDialog
      open={confirmation !== null}
      title={confirmation?.title ?? ""}
      description={confirmation?.description}
      onCancel={() => setConfirmation(null)}
      onConfirm={() => {
        const action = confirmation;
        setConfirmation(null);
        action?.run();
      }}
    />
  </div>;
}

function StockAlertsCard() {
  const [rows, setRows] = useState<Array<{ id: string; product_id: string; email?: string; state: string; created_at?: string; type?: string; target_price_minor?: number }>>([]);
  const [state, setState] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const load = () => {
    setState("loading");
    void import("@/services/api").then(({ default: api }) => api.get<{ data: Array<{ id: string; product_id: string; email?: string; state: string; created_at?: string; type?: string; target_price_minor?: number }> }>("/api/admin/stock-alerts"))
      .then((res) => { setRows(Array.isArray(res.data.data) ? res.data.data : []); setState("ready"); })
      .catch(() => setState("error"));
  };
  return <Card><CardHeader><CardTitle>Product alerts ({rows.length})</CardTitle></CardHeader><CardContent className="space-y-2">
    {state === "idle" ? <Button size="sm" variant="outline" onClick={load}>Load alerts</Button>
      : state === "loading" ? <p role="status">Loading alerts...</p>
      : state === "error" ? <p role="alert" className="text-sm text-muted-foreground">Unavailable. <Button size="sm" variant="outline" onClick={load}>Retry</Button></p>
      : rows.length ? <div className="space-y-1">{rows.map((row) => <div key={row.id} className="flex flex-wrap justify-between gap-2 rounded-md border p-2 text-sm"><span>{row.product_id} · {String(row.type ?? "back_in_stock").replace(/_/g, " ")}{typeof row.target_price_minor === "number" ? ` ≤ ${(row.target_price_minor / 100).toFixed(2)}` : ""}</span><span className="text-muted-foreground">{row.state}</span></div>)}</div>
      : <p className="text-sm text-muted-foreground">No alerts yet.</p>}
  </CardContent></Card>;
}

function ReplenishmentCard() {
  const [rows, setRows] = useState<Array<{ variant_id: string; sold_7: number; sold_30: number; available: number; days_of_cover?: number | null; suggested_qty: number }>>([]);
  const [state, setState] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const load = () => {
    setState("loading");
    void import("@/services/api").then(({ default: api }) => api.get<{ data: Array<{ variant_id: string; sold_7: number; sold_30: number; available: number; days_of_cover?: number | null; suggested_qty: number }> }>("/api/admin/replenishment-hints?low_only=1"))
      .then((res) => { setRows(Array.isArray(res.data.data) ? res.data.data : []); setState("ready"); })
      .catch(() => setState("error"));
  };
  return <Card><CardHeader><CardTitle>Replenishment hints ({rows.length})</CardTitle></CardHeader><CardContent className="space-y-2">
    {state === "idle" ? <Button size="sm" variant="outline" onClick={load}>Load hints</Button>
      : state === "loading" ? <p role="status">Loading hints...</p>
      : state === "error" ? <p role="alert" className="text-sm text-muted-foreground">Unavailable. <Button size="sm" variant="outline" onClick={load}>Retry</Button></p>
      : rows.length ? <div className="space-y-1">{rows.map((row) => <div key={row.variant_id} className="flex flex-wrap justify-between gap-2 rounded-md border p-2 text-sm"><span>{row.variant_id} · {row.sold_30}/30d · {typeof row.days_of_cover === "number" ? `${row.days_of_cover}d cover` : "no sales cover"}</span><span className="font-semibold">+{row.suggested_qty}</span></div>)}</div>
      : <p className="text-sm text-muted-foreground">Stock covers demand. Hints refresh hourly.</p>}
  </CardContent></Card>;
}

function InventoryTable({ variant, source, onHand, reserved, available }: { variant: string; source: string; onHand: number; reserved: number; available: number }) {
  return <div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">Inventory for {variant}</caption><thead><tr className="border-b text-left text-muted-foreground"><th className="p-3">Variant</th><th className="p-3">Source</th><th className="p-3">On hand</th><th className="p-3">Reserved</th><th className="p-3">Available</th></tr></thead><tbody><tr><td className="p-3 font-medium">{variant}</td><td className="p-3">{source}</td><td className="p-3">{onHand}</td><td className="p-3">{reserved}</td><td className="p-3 font-semibold">{available}</td></tr></tbody></table></div>;
}
