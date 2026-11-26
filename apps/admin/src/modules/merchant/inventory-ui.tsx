"use client";

import { useRef, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/page-header";
import { useInventory, useInventoryMutation } from "@/hooks/use-merchant";
import { ApiState, PermissionState } from "./api-state";
import { merchantFixtures } from "./fixtures";
import { useAuth } from "@/hooks/use-auth";
import { canOperateMerchant } from "./permissions";
import { inventoryAvailable } from "./contracts";

export function InventoryPage() {
  const [variant, setVariant] = useState("");
  const [demo, setDemo] = useState(false);
  const [onHand, setOnHand] = useState("");
  const [fromSource, setFromSource] = useState("");
  const [toSource, setToSource] = useState("");
  const [transferQty, setTransferQty] = useState("");
  const query = useInventory(variant);
  const adjust = useInventoryMutation("adjust", variant);
  const transfer = useInventoryMutation("transfer", variant);
  const adjustLock = useRef(false);
  const transferLock = useRef(false);
  const { user } = useAuth();
  const canOperate = canOperateMerchant(user, "inventory");
  const demoRow = demo ? merchantFixtures.inventory.find((row) => row.sku === variant) : undefined;
  const item = query.data;
  const belowReserved = !!item && Number.isInteger(Number(onHand)) && Number(onHand) < item.reserved;
  const adjustmentValid = !!item && inventoryAvailable(item.on_hand, item.reserved, item.available) !== null && onHand !== "" && Number.isInteger(Number(onHand)) && Number(onHand) >= item.reserved;
  const currentAvailable = item ? inventoryAvailable(item.on_hand, item.reserved, item.available) : null;
  const transferValid = !!variant.trim() && !!fromSource.trim() && !!toSource.trim() && fromSource.trim() !== toSource.trim() && Number.isInteger(Number(transferQty)) && Number(transferQty) > 0;

  return <div className="space-y-5">
    <PageHeader title="Inventory" description="Inspect contract-backed stock for a variant." />
    <Card><CardHeader><div className="flex flex-wrap items-center justify-between gap-3"><CardTitle>Variant stock</CardTitle><Badge variant="outline">{demo ? "Explicit demo data" : "Live API contract"}</Badge></div></CardHeader><CardContent className="space-y-4">
      <div className="flex flex-wrap gap-2"><Input className="max-w-sm" aria-label="Variant ID" value={variant} onChange={(event) => { setVariant(event.target.value); setDemo(false); }} placeholder="Enter variant ID" /><Button variant="outline" onClick={() => setDemo(true)} disabled={!variant.trim()}>Use demo data</Button></div>
      {query.isLoading ? <p role="status" className="py-8 text-center text-sm text-muted-foreground">Loading inventory...</p> : query.isError ? <ApiState error={query.error} onRetry={() => query.refetch()} /> : demo ? demoRow ? <InventoryTable variant={demoRow.sku} source="Demo source" onHand={demoRow.onHand} reserved={demoRow.reserved} available={demoRow.onHand - demoRow.reserved} /> : <p className="py-8 text-center text-sm text-muted-foreground">No demo variant matches this ID.</p> : item && currentAvailable !== null ? <InventoryTable variant={item.variant_id} source={item.source_id} onHand={item.on_hand} reserved={item.reserved} available={currentAvailable} /> : item ? <p role="alert" className="py-8 text-center text-sm text-destructive">Inventory data violates the on-hand and reserved invariant.</p> : <p className="py-8 text-center text-sm text-muted-foreground">Enter a variant ID to load live inventory.</p>}
    </CardContent></Card>
    <div className="grid gap-5 lg:grid-cols-2">
      <Card><CardHeader><CardTitle>Adjust on-hand</CardTitle></CardHeader><CardContent className="space-y-3">{!canOperate && <PermissionState />}<p className="text-sm text-muted-foreground">The contract accepts an absolute on-hand value. Reserved and available are returned by the service.</p><Input aria-label="New on-hand quantity" type="number" min="0" value={onHand} onChange={(event) => setOnHand(event.target.value)} placeholder="New on-hand quantity" />{belowReserved && <p role="alert" className="text-sm text-destructive">On-hand cannot be below reserved stock ({item?.reserved}). No request will be sent.</p>}<Button onClick={() => { if (!adjustmentValid || adjustLock.current) return; adjustLock.current = true; adjust.mutate({ on_hand: Number(onHand) }, { onSettled: () => { adjustLock.current = false; } }); }} disabled={!canOperate || !adjustmentValid || adjust.isPending}>Apply adjustment</Button>{adjust.isError && <ApiState error={adjust.error} />} {adjust.isSuccess && <p role="status" className="text-sm text-muted-foreground">Adjustment accepted. Refreshing ATP from the inventory service.</p>}</CardContent></Card>
      <Card><CardHeader><CardTitle>Transfer stock</CardTitle></CardHeader><CardContent className="space-y-3">{!canOperate && <PermissionState />}<Input aria-label="Source ID" value={fromSource} onChange={(event) => setFromSource(event.target.value)} placeholder="Source ID" /><Input aria-label="Destination source ID" value={toSource} onChange={(event) => setToSource(event.target.value)} placeholder="Destination source ID" /><Input aria-label="Transfer quantity" type="number" min="1" value={transferQty} onChange={(event) => setTransferQty(event.target.value)} placeholder="Quantity" /><Button onClick={() => { if (!transferValid || transferLock.current) return; transferLock.current = true; transfer.mutate({ from_source_id: fromSource.trim(), to_source_id: toSource.trim(), qty: Number(transferQty) }, { onSettled: () => { transferLock.current = false; } }); }} disabled={!canOperate || !transferValid || transfer.isPending}>Transfer stock</Button>{transfer.isError && <ApiState error={transfer.error} />}{transfer.isSuccess && <p role="status" className="text-sm text-muted-foreground">Transfer accepted. Refreshing ATP from the inventory service.</p>}</CardContent></Card>
    </div>
  </div>;
}

function InventoryTable({ variant, source, onHand, reserved, available }: { variant: string; source: string; onHand: number; reserved: number; available: number }) {
  return <div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">Inventory for {variant}</caption><thead><tr className="border-b text-left text-muted-foreground"><th className="p-3">Variant</th><th className="p-3">Source</th><th className="p-3">On hand</th><th className="p-3">Reserved</th><th className="p-3">Available</th></tr></thead><tbody><tr><td className="p-3 font-medium">{variant}</td><td className="p-3">{source}</td><td className="p-3">{onHand}</td><td className="p-3">{reserved}</td><td className="p-3 font-semibold">{available}</td></tr></tbody></table></div>;
}
