"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { ArrowRight, Check, Clock3, PackageCheck, RefreshCw, Truck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { Skeleton } from "@/components/ui/skeleton";
import { StatusBadge } from "@/components/status-badge";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { SearchInput } from "@/components/search-input";
import { Pagination } from "@/components/pagination";
import { useAuth } from "@/hooks/use-auth";
import { useBulkOrderTransition, useCancelOrder, useCreateOrder, useOrder, useOrders, useOrderTransition, useRefundOrder, useRefundRemaining, useRefundToCredit } from "@/hooks/use-orders";
import { OrderFormDialog } from "@/modules/orders/components/order-form-dialog";
import { formatCurrency, formatDate } from "@/lib/utils";
import { ApiState, PermissionState } from "./api-state";
import { canOperateMerchant, canUseRoute } from "./permissions";
import type { components } from "@/types/api";
import { canTransitionOrder, dedupeIds, orderTransitions } from "./contracts";

type Order = components["schemas"]["Order"];
const transitionLabels: Record<string, string> = { confirmed: "Confirm", processing: "Start processing", shipped: "Mark shipped", delivered: "Mark delivered", cancelled: "Cancel", ready_to_ship: "Mark ready to ship", ready_for_pickup: "Mark ready for pickup", picked_up: "Mark collected" };

export function OrderList() {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [dialogOpen, setDialogOpen] = useState(false);
  const create = useCreateOrder();
  const [selected, setSelected] = useState<number[]>([]);
  const [target, setTarget] = useState<Order["status"]>("confirmed");
  const [bulkConfirmation, setBulkConfirmation] = useState<{ description: string; run: () => void } | null>(null);
  const [bulkSummary, setBulkSummary] = useState<{ succeeded: number; failed: number } | null>(null);
  const bulkLock = useRef(false);
  const query = useOrders({ search: search.trim() || undefined, page, per_page: 20 });
  const regionRef = useRef<HTMLDivElement>(null);
  const hadListError = useRef(false);
  useEffect(() => {
    if (query.isError) {
      hadListError.current = true;
      return;
    }
    if (hadListError.current && !query.isLoading) {
      hadListError.current = false;
      regionRef.current?.focus();
    }
  }, [query.isError, query.isLoading]);
  const bulk = useBulkOrderTransition();
  useEffect(() => {
    if (!bulk.isPending) bulkLock.current = false;
  }, [bulk.isPending]);
  const { user } = useAuth();
  const canOperate = canOperateMerchant(user, "orders");
  const visibleIds = dedupeIds(query.orders.map((order) => order.id));
  const selectedVisible = selected.filter((id) => visibleIds.includes(id));
  const eligible = query.orders.filter((order) => selectedVisible.includes(order.id ?? -1)).every((order) => canTransitionOrder(order.status, target));
  const toggle = (id: number) => setSelected((ids) => ids.includes(id) ? ids.filter((value) => value !== id) : [...ids, id]);
  const toggleAll = () => setSelected(selectedVisible.length === visibleIds.length ? [] : visibleIds);
  const useBulkEndpoint = canOperateMerchant(user, "admin_workbench");
  const runBulk = () => {
    if (bulkLock.current || bulk.isPending || !selectedVisible.length || !eligible) return;
    setBulkConfirmation({
      description: `Apply "${target}" to ${selectedVisible.length} order(s)? ${useBulkEndpoint ? "The backend bulk endpoint processes this selection." : "Each order is transitioned individually for this role."}`,
      run: () => {
        bulkLock.current = true;
        setBulkSummary(null);
        bulk.mutate({ ids: selectedVisible, to: target, useBulkEndpoint }, {
          onSuccess: (result) => setBulkSummary({ succeeded: result.succeeded.length, failed: result.failed.length }),
          onError: (error) => {
            const failures = (error as { failures?: unknown[] }).failures;
            setBulkSummary({ succeeded: Math.max(0, selectedVisible.length - (Array.isArray(failures) ? failures.length : selectedVisible.length)), failed: Array.isArray(failures) ? failures.length : selectedVisible.length });
          },
        });
      },
    });
  };

  return <div className="space-y-5">
    <PageHeader title="Orders" description="Review scoped orders and move them through backend-authoritative transitions." />
      <div className="flex flex-wrap items-center gap-3"><SearchInput value={search} onChange={(value) => { setSearch(value); setPage(1); }} placeholder="Search orders" /><Badge variant="outline">Live API contract</Badge>{canOperate && <Button size="sm" onClick={() => setDialogOpen(true)}>New order</Button>}<OrderFormDialog open={dialogOpen} onOpenChange={setDialogOpen} onSubmit={(data) => create.mutate(data as Parameters<typeof create.mutate>[0], { onSuccess: () => setDialogOpen(false) })} isPending={create.isPending} /></div>
    {query.isLoading ? <Card><CardContent className="space-y-3 p-6" role="status"><span className="sr-only">Loading orders</span>{[1, 2, 3, 4].map((n) => <Skeleton key={n} className="h-12 w-full" />)}</CardContent></Card>
      : query.isError ? <ApiState error={query.error} onRetry={() => query.refetch()} />
      : query.orders.length === 0 ? <EmptyState title="No orders yet" description="Orders returned by the service will appear here." />
      : <>
          <Card><CardContent className="flex flex-wrap items-center gap-3 p-4"><span className="text-sm font-medium" aria-live="polite">{selectedVisible.length} selected</span>{bulkSummary && <span className={bulkSummary.failed ? "text-sm text-amber-600" : "text-sm text-emerald-600"} role="status">Bulk result: {bulkSummary.succeeded} succeeded, {bulkSummary.failed} failed</span>}<select aria-label="Bulk target status" className="h-9 rounded-md border bg-background px-3 text-sm" value={target} onChange={(event) => setTarget(event.target.value as Order["status"])}><option value="confirmed">Confirm</option><option value="processing">Start processing</option><option value="shipped">Mark shipped</option><option value="delivered">Mark delivered</option><option value="cancelled">Cancel</option></select><Button size="sm" onClick={runBulk} disabled={!canOperate || !selectedVisible.length || !eligible || bulk.isPending}>{bulk.isPending ? "Updating..." : "Apply to selected"}</Button><Button asChild size="sm" variant="outline" disabled={!canOperate || !selectedVisible.length}><Link href={`/orders/print?type=packing&ids=${selectedVisible.join(",")}`}>Print packing slips</Link></Button><Button asChild size="sm" variant="outline" disabled={!canOperate || !selectedVisible.length}><Link href={`/orders/print?type=invoice&ids=${selectedVisible.join(",")}`}>Print invoices</Link></Button>{!canOperate && <PermissionState />}</CardContent></Card>
         {bulk.isError && <><ApiState error={bulk.error} onRetry={runBulk} /><div className="flex items-center justify-between gap-3 rounded-md border border-amber-500/30 bg-amber-500/5 p-3 text-sm"><span>Some transitions may have completed. Refresh before retrying, or abort this selection.</span><Button variant="outline" size="sm" onClick={() => setSelected([])}>Abort</Button></div></>}
                       <div ref={regionRef} role="region" tabIndex={-1} aria-label="Orders" data-tour="orders-table"><Card><CardContent className="overflow-x-auto p-0">
             <table className="hidden w-full text-sm md:table"><caption className="sr-only">Orders</caption><thead><tr className="border-b text-left text-muted-foreground"><th className="p-4"><Checkbox aria-label="Select all orders" checked={selectedVisible.length === visibleIds.length} onCheckedChange={toggleAll} /></th><th className="p-4">Order</th><th className="p-4">Customer</th><th className="p-4">Status</th><th className="p-4">Total</th><th className="p-4">Created</th><th className="p-4" /></tr></thead><tbody>{query.orders.map((order, index) => <tr key={`${order.id ?? "unknown"}-${index}`} className="border-b last:border-0 hover:bg-muted/40"><td className="p-4"><Checkbox aria-label={`Select order ${order.id ?? "unknown"}`} checked={order.id != null && selected.includes(order.id)} onCheckedChange={() => order.id != null && toggle(order.id)} /></td><td className="p-4 font-medium">#{order.id}</td><td className="p-4">{order.user_name || order.customer_email || "Unknown customer"}</td><td className="p-4"><StatusBadge status={order.status} /></td><td className="p-4">{formatCurrency(order.total ?? 0)}</td><td className="p-4 text-muted-foreground">{formatDate(order.created_at)}</td><td className="p-4 text-right"><Button asChild variant="ghost" size="sm"><Link href={`/orders/${order.id}`}>View <ArrowRight className="ml-2 h-4 w-4" /></Link></Button></td></tr>)}</tbody></table>
           <div className="space-y-3 p-4 md:hidden" aria-label="Mobile order cards">{query.orders.map((order, index) => <div key={`${order.id ?? "unknown"}-${index}`} className="rounded-lg border p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-medium">#{order.id}</p><p className="text-sm text-muted-foreground">{order.user_name || order.customer_email || "Unknown customer"}</p></div><Checkbox aria-label={`Select order ${order.id ?? "unknown"}`} checked={order.id != null && selected.includes(order.id)} onCheckedChange={() => order.id != null && toggle(order.id)} /></div><div className="mt-3 flex items-center justify-between"><StatusBadge status={order.status} /><Button asChild variant="outline" size="sm"><Link aria-label={`Open order ${order.id}`} href={`/orders/${order.id}`}>Open</Link></Button></div></div>)}</div>
           </CardContent></Card><Pagination meta={query.meta} onPageChange={setPage} />
            </div>
      </>}
      <ConfirmDialog
        open={bulkConfirmation !== null}
        title="Bulk order transition"
        description={bulkConfirmation?.description}
        isPending={bulk.isPending}
        onCancel={() => setBulkConfirmation(null)}
        onConfirm={() => {
          const action = bulkConfirmation;
          setBulkConfirmation(null);
          action?.run();
        }}
      />
  </div>;
}

function Timeline({ order }: { order: Order }) {
  const stages = ["pending", "confirmed", "processing", "shipped", "delivered"];
  const current = stages.indexOf(order.status ?? "pending");
  return <div className="space-y-4" aria-label="Order status timeline">{stages.map((stage, index) => <div key={stage} className="flex items-start gap-3"><div className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${index <= current ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground"}`}>{index < current ? <Check className="h-4 w-4" /> : index === 0 ? <Clock3 className="h-4 w-4" /> : index === 3 ? <Truck className="h-4 w-4" /> : <PackageCheck className="h-4 w-4" />}</div><div><p className="font-medium capitalize">{stage}</p><p className="text-xs text-muted-foreground">{index <= current ? "Current order status" : "Not reached"}</p></div></div>)}<p className="border-t pt-3 text-xs text-muted-foreground">Full audit event history lives in the timeline panel below.</p></div>;
}

export function OrderDetail({ id }: { id: number }) {
  const query = useOrder(id);
  const { user } = useAuth();
  const [message, setMessage] = useState("");
  const mutation = useOrderTransition(id);
  const cancel = useCancelOrder(id);
  const refund = useRefundOrder(id);
  const refundAll = useRefundRemaining(id);
  const refundCredit = useRefundToCredit(id);
  const [refundMinor, setRefundMinor] = useState("");
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; destructive?: boolean; run: () => void } | null>(null);
  if (query.isLoading) return <div className="space-y-4" role="status"><span className="sr-only">Loading order</span><Skeleton className="h-10 w-64" /><Skeleton className="h-64 w-full" /></div>;
  if (query.isError || !query.data) return <ApiState error={query.error} onRetry={() => query.refetch()} />;
  const order = query.data;
  const next = orderTransitions[order.status ?? "pending"] ?? [];
  const canTransition = canOperateMerchant(user, "orders");
  const canRefund = canUseRoute(user, ["owner", "admin", "finance"]);
  const orderPayments = ((): Array<{ id?: string; provider?: string; state?: string; amount?: number; captured?: number; refunded?: number }> => {
    const raw = (order as unknown as { payments?: unknown }).payments;
    return Array.isArray(raw) ? raw.filter((item): item is { id?: string; provider?: string; state?: string; amount?: number; captured?: number; refunded?: number } => typeof item === "object" && item !== null) : [];
  })();
  const pickupCode = (order as unknown as { pickup_point_code?: unknown }).pickup_point_code;
  return <div className="space-y-5"><PageHeader title={`Order #${order.id}`} description={`${order.customer_email || order.user_name || "Customer unavailable"} • ${formatDate(order.created_at)}`}><Button asChild variant="outline"><Link href="/orders">Back to orders</Link></Button></PageHeader>
    {typeof pickupCode === "string" && pickupCode !== "" && <p role="status" className="rounded-md border border-primary/30 bg-primary/5 p-3 text-sm">Store pickup at <strong>{pickupCode}</strong> — use Ready for pickup / Collected transitions, not Ship.</p>}
    {message && <p role="status" className="rounded-md border border-primary/30 bg-primary/5 p-3 text-sm">{message}</p>}
     <div className="grid gap-5 lg:grid-cols-[1fr_360px]"><div className="space-y-5"><Card><CardHeader><CardTitle>Items</CardTitle></CardHeader><CardContent>{order.items?.length ? <div className="divide-y">{order.items.map((item) => <div className="flex justify-between gap-4 py-3" key={item.id ?? `${item.product_id}-${item.product_name}`}><div><p className="font-medium">{item.product_name || `Product #${item.product_id}`}</p><p className="text-sm text-muted-foreground">Qty {item.quantity ?? 0}</p></div><span>{formatCurrency(item.subtotal ?? item.unit_price ?? 0)}</span></div>)}</div> : <EmptyState title="No line items" description="The service returned no order items." className="py-8" />}<div className="mt-4 border-t pt-4 text-right font-semibold">Total {formatCurrency(order.total ?? 0)}</div></CardContent></Card><Card><CardHeader><CardTitle>Payments</CardTitle></CardHeader><CardContent>{orderPayments.length ? <div className="divide-y">{orderPayments.map((payment) => <div className="flex justify-between gap-4 py-2 text-sm" key={payment.id ?? payment.provider}><div><p className="font-medium">{payment.provider || "Payment"}</p><p className="text-muted-foreground">{payment.id} · {payment.state}</p></div><span className="text-right">{formatCurrency(payment.amount ?? 0)}<span className="block text-xs text-muted-foreground">captured {formatCurrency(payment.captured ?? 0)} · refunded {formatCurrency(payment.refunded ?? 0)}</span></span></div>)}</div> : <p className="text-sm text-muted-foreground">No payments recorded on this order.</p>}</CardContent></Card><Card><CardHeader><CardTitle>Fulfillment</CardTitle></CardHeader><CardContent><p className="text-sm text-muted-foreground">Use the Fulfillment workspace to allocate this order with its contract-required variant lines.</p><Button asChild variant="outline" className="mt-4"><Link href="/fulfillment">Open fulfillment</Link></Button></CardContent></Card><Card><CardHeader><CardTitle>Refunds and returns</CardTitle></CardHeader><CardContent><p className="text-sm text-muted-foreground">Refunds, returns, and the audit timeline live with this order below.</p><div className="mt-4 flex flex-wrap gap-2"><Button asChild variant="outline"><a href="#order-panels">Open order panels</a></Button></div></CardContent></Card></div>
      <div className="space-y-5"><Card><CardHeader><CardTitle>Status</CardTitle></CardHeader><CardContent className="space-y-4"><StatusBadge status={order.status} />{!canTransition && <PermissionState />}<div className="flex flex-wrap gap-2">{next.filter((status) => status !== "cancelled").map((status) => <Button key={status} size="sm" disabled={!canTransition || mutation.isPending} onClick={() => { setMessage(`Updating order to ${status}...`); mutation.mutate(status as Order["status"], { onSuccess: () => setMessage(`Order updated to ${status}.`), onError: () => setMessage("Order was not updated. Refresh before retrying.") }); }}>{transitionLabels[status]}</Button>)}{next.includes("cancelled") && <Button variant="destructive" disabled={!canTransition || cancel.isPending} onClick={() => setConfirmation({ title: "Cancel order", description: `Cancel order #${order.id}? This cannot be undone.`, destructive: true, run: () => cancel.mutate(undefined, { onSuccess: () => setMessage("Order cancelled."), onError: () => setMessage("Order was not cancelled. Refresh before retrying.") }) })}>Cancel</Button>}{mutation.isPending && <span role="status" className="inline-flex items-center"><RefreshCw className="h-4 w-4 animate-spin" aria-hidden="true" /><span className="sr-only">Updating</span></span>}</div>{(mutation.isError || cancel.isError) && <ApiState error={mutation.error || cancel.error} onRetry={() => query.refetch()} />}{next.length === 0 && <p className="text-sm text-muted-foreground">No named transitions are available from this status.</p>}</CardContent></Card><Card><CardHeader><CardTitle>Refund</CardTitle></CardHeader><CardContent className="space-y-3"><p className="text-sm text-muted-foreground">Enter a positive amount in the order currency's minor units.</p><Input aria-label="Refund amount in minor units" type="number" min="1" step="1" value={refundMinor} onChange={(event) => setRefundMinor(event.target.value)} /><Button disabled={!canRefund || refund.isPending || !/^[1-9]\d*$/.test(refundMinor)} onClick={() => setConfirmation({ title: "Refund payment", description: `Refund ${refundMinor} (minor units) on order #${order.id}?`, destructive: true, run: () => refund.mutate({ amount_minor: Number(refundMinor) }, { onSuccess: () => setMessage("Refund accepted."), onError: () => setMessage("Refund was not accepted. Refresh before retrying.") }) })}>Refund</Button><Button variant="outline" disabled={!canRefund || refundAll.isPending} onClick={() => setConfirmation({ title: "Refund remaining balance", description: `Refund everything remaining on order #${order.id}?`, destructive: true, run: () => refundAll.mutate(undefined, { onSuccess: () => setMessage("Remaining refund accepted."), onError: () => setMessage("Refund was not accepted. Refresh before retrying.") }) })}>{refundAll.isPending ? "Refunding..." : "Refund remaining"}</Button><Button variant="outline" disabled={!canRefund || refundCredit.isPending} onClick={() => setConfirmation({ title: "Refund to store credit", description: `Refund everything remaining on order #${order.id} to store credit? The customer keeps the value in their wallet instead of a provider refund.`, destructive: true, run: () => refundCredit.mutate(undefined, { onSuccess: (result) => setMessage(`Refunded to store credit${result?.credit?.code ? ` (${result.credit.code})` : ""}.`), onError: () => setMessage("Refund to credit was not accepted. Only customer orders can refund to credit.") }) })}>{refundCredit.isPending ? "Crediting..." : "Refund to credit"}</Button>{!canRefund && <PermissionState />}{(refund.isError || refundAll.isError || refundCredit.isError) && <ApiState error={refund.error || refundAll.error || refundCredit.error} onRetry={() => query.refetch()} />}</CardContent></Card><Card><CardHeader><CardTitle>Timeline</CardTitle></CardHeader><CardContent><Timeline order={order} /></CardContent></Card></div></div>
    <ConfirmDialog
      open={confirmation !== null}
      title={confirmation?.title ?? ""}
      description={confirmation?.description}
      destructive={confirmation?.destructive}
      isPending={cancel.isPending || refund.isPending || refundAll.isPending || refundCredit.isPending}
      onCancel={() => setConfirmation(null)}
      onConfirm={() => {
        const action = confirmation;
        setConfirmation(null);
        action?.run();
      }}
    />
  </div>;
}
