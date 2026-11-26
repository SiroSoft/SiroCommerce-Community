"use client";

import { useEffect, useState } from "react";
import { Loader2, Download } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { formatDate, formatMinor } from "@/lib/utils";
import {
  useCancelVendorPayout,
  useCreateVendorPayout,
  useMarkVendorPayoutPaid,
  useReconcileVendorPayouts,
  useVendorLedger,
  useVendorPayouts,
} from "@/hooks/use-vendors";
import type { Vendor, VendorPayout } from "@/services/vendors.service";
import { vendorsService } from "@/services/vendors.service";
import { toast } from "@/hooks/use-toast";

interface VendorPayoutsDialogProps {
  open: boolean;
  vendor: Vendor | null;
  onOpenChange: (open: boolean) => void;
}

interface ConfirmAction {
  kind: "paid" | "cancel";
  payout: VendorPayout;
}

function isoDate(offsetDays: number): string {
  const date = new Date();
  date.setDate(date.getDate() + offsetDays);
  return date.toISOString().slice(0, 10);
}

export function VendorPayoutsDialog({ open, vendor, onOpenChange }: VendorPayoutsDialogProps) {
  const vendorId = vendor?.id;
  const { ledger, isLoading: ledgerLoading, isError: ledgerError, error: ledgerFailure } = useVendorLedger(vendorId, undefined);
  const { payouts, isLoading: payoutsLoading } = useVendorPayouts(vendorId);
  const createPayout = useCreateVendorPayout(vendorId ?? "");
  const markPaid = useMarkVendorPayoutPaid(vendorId ?? "");
  const cancelPayout = useCancelVendorPayout(vendorId ?? "");
  const reconcilePayouts = useReconcileVendorPayouts(vendorId ?? "");
  const [periodStart, setPeriodStart] = useState(isoDate(-30));
  const [periodEnd, setPeriodEnd] = useState(isoDate(0));
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null);
  const [exportingPayoutId, setExportingPayoutId] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setPeriodStart(isoDate(-30));
    setPeriodEnd(isoDate(0));
    setConfirmAction(null);
    setExportingPayoutId(null);
  }, [open, vendorId]);

  const handleExport = async (payout: VendorPayout) => {
    setExportingPayoutId(payout.id);
    try {
      const result = await vendorsService.exportPayoutCsv(payout.id);
      const url = URL.createObjectURL(result.blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = result.filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      toast({ title: "Bank CSV ready", description: `Exported ${result.rows} payout row(s).` });
    } catch (error) {
      toast({ title: "Export failed", description: error instanceof Error ? error.message : "Could not export payout.", variant: "destructive" });
    } finally {
      setExportingPayoutId(null);
    }
  };

  const handleReconcile = async (file: File) => {
    const csv = await file.text();
    reconcilePayouts.mutate({ csv });
  };

  const currency = ledger?.entries[0]?.currency ?? payouts[0]?.currency ?? "VND";
  const totals = ledger?.totals;
  const entries = ledger?.entries ?? [];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-[calc(100%-2rem)] overflow-y-auto sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>{vendor ? `${vendor.name} payouts` : "Vendor payouts"}</DialogTitle>
          <DialogDescription>Commission snapshot ledger and manual payout batches. Checkout totals and payments are untouched.</DialogDescription>
        </DialogHeader>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Vendor balances">
          <div className="rounded-md border p-3">
            <p className="text-xs uppercase text-muted-foreground">Pending</p>
            <p className="text-lg font-semibold" aria-label="Pending balance">{formatMinor(totals?.pending ?? 0, currency)}</p>
          </div>
          <div className="rounded-md border p-3">
            <p className="text-xs uppercase text-muted-foreground">Available</p>
            <p className="text-lg font-semibold" aria-label="Available balance">{formatMinor(totals?.available ?? 0, currency)}</p>
          </div>
          <div className="rounded-md border p-3">
            <p className="text-xs uppercase text-muted-foreground">Paid</p>
            <p className="text-lg font-semibold" aria-label="Paid balance">{formatMinor(totals?.paid ?? 0, currency)}</p>
          </div>
        </div>

        <section className="space-y-2" aria-label="Vendor ledger">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">Ledger</h3>
            <span className="text-xs text-muted-foreground">
              Gross {formatMinor(totals?.gross ?? 0, currency)} · Commission {formatMinor(totals?.commission ?? 0, currency)} · Net {formatMinor(totals?.net ?? 0, currency)}
            </span>
          </div>
          {ledgerError ? (
            <p role="alert" className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
              {ledgerFailure instanceof Error ? ledgerFailure.message : "Ledger could not be loaded."}
            </p>
          ) : ledgerLoading ? (
            <p className="rounded-md border p-4 text-sm text-muted-foreground" role="status">Loading ledger...</p>
          ) : entries.length === 0 ? (
            <p className="rounded-md border p-4 text-sm text-muted-foreground" role="status">No commission entries yet.</p>
          ) : (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm" aria-label="Vendor ledger entries">
                <thead className="bg-muted/40 text-left">
                  <tr>
                    <th className="p-2">Created</th>
                    <th className="p-2">Type</th>
                    <th className="p-2">Order</th>
                    <th className="p-2 text-right">Gross</th>
                    <th className="p-2 text-right">Commission</th>
                    <th className="p-2 text-right">Net</th>
                    <th className="p-2">State</th>
                  </tr>
                </thead>
                <tbody>
                  {entries.map((entry) => (
                    <tr key={entry.id} className="border-t">
                      <td className="p-2 whitespace-nowrap">{formatDate(entry.created_at) || "—"}</td>
                      <td className="p-2">{entry.type}</td>
                      <td className="p-2 font-mono text-xs">{entry.order_id ?? "order-level"}</td>
                      <td className="p-2 text-right">{formatMinor(entry.gross_minor, entry.currency)}</td>
                      <td className="p-2 text-right">{formatMinor(entry.commission_minor, entry.currency)}</td>
                      <td className="p-2 text-right">{formatMinor(entry.net_minor, entry.currency)}</td>
                      <td className="p-2"><Badge variant={entry.state === "paid" ? "success" : entry.state === "void" ? "destructive" : "secondary"}>{entry.state}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="space-y-3 rounded-md border p-3" aria-label="Create payout batch">
          <h3 className="text-sm font-semibold">New payout batch</h3>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label htmlFor="vendor-payout-start">Period start</Label>
              <Input id="vendor-payout-start" type="date" value={periodStart} onChange={(event) => setPeriodStart(event.target.value)} disabled={createPayout.isPending} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="vendor-payout-end">Period end</Label>
              <Input id="vendor-payout-end" type="date" value={periodEnd} onChange={(event) => setPeriodEnd(event.target.value)} disabled={createPayout.isPending} />
            </div>
          </div>
          <div className="flex justify-end">
            <Button
              onClick={() => createPayout.mutate({ period_start: periodStart, period_end: periodEnd })}
              disabled={createPayout.isPending || !periodStart || !periodEnd}
            >
              {createPayout.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Create payout
            </Button>
          </div>
        </section>

        <section className="space-y-2" aria-label="Vendor payout history">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-semibold">Payout history</h3>
            <label className="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted">
              {reconcilePayouts.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4 rotate-180" />}
              Reconcile bank CSV
              <input aria-label="Reconcile vendor payouts CSV" type="file" accept="text/csv,.csv" className="sr-only" disabled={reconcilePayouts.isPending} onChange={(event) => { const file = event.target.files?.[0]; if (file) void handleReconcile(file); event.target.value = ""; }} />
            </label>
          </div>
          {payoutsLoading ? (
            <p className="rounded-md border p-4 text-sm text-muted-foreground" role="status">Loading payouts...</p>
          ) : payouts.length === 0 ? (
            <p className="rounded-md border p-4 text-sm text-muted-foreground" role="status">No payout batches yet.</p>
          ) : (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm" aria-label="Vendor payout history">
                <thead className="bg-muted/40 text-left">
                  <tr>
                    <th className="p-2">Created</th>
                    <th className="p-2">Period</th>
                    <th className="p-2 text-right">Amount</th>
                    <th className="p-2">State</th>
                    <th className="p-2 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {payouts.map((payout) => (
                    <tr key={payout.id} className="border-t">
                      <td className="p-2 whitespace-nowrap">{formatDate(payout.created_at) || "—"}</td>
                      <td className="p-2 whitespace-nowrap">{payout.period_start?.slice(0, 10)} → {payout.period_end?.slice(0, 10)}</td>
                      <td className="p-2 text-right">{formatMinor(payout.amount_minor, payout.currency)}</td>
                      <td className="p-2"><Badge variant={payout.state === "paid" ? "success" : ["cancelled", "failed", "returned"].includes(payout.state) ? "destructive" : "secondary"}>{payout.state}</Badge></td>
                      <td className="p-2">
                        <div className="flex justify-end gap-1">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => void handleExport(payout)}
                            aria-label={`Export bank CSV ${payout.id}`}
                            disabled={exportingPayoutId === payout.id}
                          >
                            {exportingPayoutId === payout.id ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Download className="mr-1 h-3 w-3" />}
                            Export bank CSV
                          </Button>
                          {payout.state === "pending" ? (
                            <>
                              <Button variant="outline" size="sm" onClick={() => setConfirmAction({ kind: "paid", payout })} aria-label={`Mark payout ${payout.id} paid`} disabled={markPaid.isPending}>
                                Mark paid
                              </Button>
                              <Button variant="ghost" size="sm" onClick={() => setConfirmAction({ kind: "cancel", payout })} aria-label={`Cancel payout ${payout.id}`} disabled={cancelPayout.isPending}>
                                Cancel
                              </Button>
                            </>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <ConfirmDialog
          open={confirmAction !== null}
          title={confirmAction?.kind === "paid" ? "Mark payout paid?" : "Cancel payout?"}
          description={confirmAction?.kind === "paid"
            ? "The batch is settled and its ledger entries move to paid."
            : "The batch is cancelled and its ledger entries return to available."}
          confirmLabel={confirmAction?.kind === "paid" ? "Mark paid" : "Cancel payout"}
          destructive={confirmAction?.kind === "cancel"}
          isPending={markPaid.isPending || cancelPayout.isPending}
          onCancel={() => setConfirmAction(null)}
          onConfirm={() => {
            if (!confirmAction) return;
            if (confirmAction.kind === "paid") markPaid.mutate(confirmAction.payout.id, { onSettled: () => setConfirmAction(null) });
            else cancelPayout.mutate(confirmAction.payout.id, { onSettled: () => setConfirmAction(null) });
          }}
        />
      </DialogContent>
    </Dialog>
  );
}
