"use client";

import { useState } from "react";
import { RefreshCw } from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { Pagination } from "@/components/pagination";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/hooks/use-auth";
import { ApiState, PermissionState } from "@/modules/merchant/api-state";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { idempotencyKey } from "@/services/merchant.service";
import { operationsService } from "@/services/operations.service";
import { ordersService } from "@/services/orders.service";
import api from "@/services/api";

type PendingConfirmation = { title: string; description: string; run: () => void };

export default function OperationsPage() {
  const [page, setPage] = useState(1);
  const [q, setQ] = useState("");
  const [kind, setKind] = useState("");
  const [state, setState] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<PendingConfirmation | null>(null);
  const [reconcileResult, setReconcileResult] = useState<{ inspected: number; resolved: number; unknown: number } | null>(null);
  const [reconcileBusy, setReconcileBusy] = useState(false);
  const client = useQueryClient();
  const { user } = useAuth();
  const query = useQuery({
    queryKey: ["operations", page, q, kind, state],
    queryFn: () =>
      operationsService.list({ page, per_page: 25, q: q || undefined, kind: kind || undefined, state: state || undefined }),
  });
  const retry = useMutation({
    mutationFn: (id: string) => operationsService.retry(id, idempotencyKey()),
    onSuccess: () => {
      setNotice("Retry queued.");
      client.invalidateQueries({ queryKey: ["operations"] });
    },
    onError: () => setNotice("Retry was not accepted. Refresh before retrying."),
  });
  const replay = useMutation({
    mutationFn: (id: string) => operationsService.replayNotification(id, idempotencyKey()),
    onSuccess: () => {
      setNotice("Notification replay queued.");
      client.invalidateQueries({ queryKey: ["operations"] });
    },
    onError: () => setNotice("Replay was not accepted. Refresh before retrying."),
  });
  const role = user?.role?.toLowerCase() || "";
  const canRetry = canOperateMerchant(user, "operations") && ["admin", "owner"].includes(role);
  const canReplay = canOperateMerchant(user, "operations") && ["admin", "owner", "support"].includes(role);
  const canReconcile = canOperateMerchant(user, "payments") || canOperateMerchant(user, "operations");
  const rows = query.data?.data || [];

  const runReconcile = async (apply: boolean) => {
    setReconcileBusy(true);
    setNotice(null);
    try {
      const result = await ordersService.reconcilePayments(apply, idempotencyKey());
      setReconcileResult(result);
      setNotice(apply ? `Reconciliation applied: ${result.resolved} resolved.` : `Inspection complete: ${result.inspected} inspected.`);
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "Reconciliation was not accepted.");
    } finally {
      setReconcileBusy(false);
    }
  };
  const reconcile = (apply: boolean) => {
    if (!canReconcile || reconcileBusy) return;
    if (!apply) {
      void runReconcile(false);
      return;
    }
    setConfirmation({
      title: "Apply reconciliation fixes",
      description: "Apply payment reconciliation fixes? This writes corrections to payment evidence.",
      run: () => void runReconcile(true),
    });
  };
  const [scheduledResult, setScheduledResult] = useState<Record<string, unknown> | null>(null);
  const [scheduledBusy, setScheduledBusy] = useState(false);
  const runScheduled = async (apply: boolean) => {
    setScheduledBusy(true); setNotice(null);
    try {
      const path = `/api/admin/ops/scheduled/run${apply ? "?apply=true" : ""}`;
      const res = apply ? await api.post<{ data?: Record<string, unknown> }>(path, undefined) : await api.get<{ data?: Record<string, unknown> }>(path);
      const data = (res.data as { data?: Record<string, unknown> }).data;
      setScheduledResult(data ?? null);
      setNotice(apply ? "Scheduled maintenance applied" : "Scheduled dry-run complete");
    } catch (e) { setNotice(e instanceof Error ? e.message : "Scheduled run failed"); }
    finally { setScheduledBusy(false); }
  };
  const scheduledRun = (apply: boolean) => {
    if (scheduledBusy) return;
    if (!apply) {
      void runScheduled(false);
      return;
    }
    setConfirmation({
      title: "Apply scheduled maintenance",
      description: "Apply scheduled maintenance (expire promotions/bookings, prune audits, expire reservations)?",
      run: () => void runScheduled(true),
    });
  };

  return (
    <div className="space-y-5">
      <PageHeader
        title="Operations"
        description="Monitor order-side effects, payment reconciliation evidence, fulfillment status, and return/refund exceptions."
      />
      {notice && (
        <p role="status" className="rounded-md border bg-muted px-3 py-2 text-sm">
          {notice}
        </p>
      )}
      <OpsAlertsCard />
      <div className="flex flex-wrap gap-3">
        <Input
          className="max-w-sm"
          aria-label="Search operations"
          placeholder="Search id, operation, or error"
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setPage(1);
          }}
        />
        <select
          aria-label="Operation kind"
          className="h-10 rounded-md border bg-background px-3 text-sm"
          value={kind}
          onChange={(e) => {
            setKind(e.target.value);
            setPage(1);
          }}
        >
          <option value="">All evidence</option>
          <option value="job">Jobs</option>
          <option value="payment">Payments</option>
          <option value="fulfillment">Fulfillment</option>
          <option value="notification">Notifications</option>
          <option value="return">Returns/refunds</option>
        </select>
        <select
          aria-label="Operation state"
          className="h-10 rounded-md border bg-background px-3 text-sm"
          value={state}
          onChange={(e) => {
            setState(e.target.value);
            setPage(1);
          }}
        >
          <option value="">All states</option>
          <option value="failed">Failed</option>
          <option value="dead">Dead</option>
          <option value="cancelled">Cancelled</option>
          <option value="sending">Sending</option>
          <option value="queued">Queued</option>
          <option value="unknown">Unknown</option>
        </select>
        <Badge variant="outline">Scope enforced by API</Badge>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Payment reconciliation</CardTitle>
          <CardDescription>POST /api/admin/payments/reconcile. Inspect first, then apply.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap items-center gap-2">
          <Button variant="outline" disabled={!canReconcile || reconcileBusy} onClick={() => reconcile(false)}>
            {reconcileBusy ? "Working..." : "Inspect payments"}
          </Button>
          <Button disabled={!canReconcile || reconcileBusy} onClick={() => reconcile(true)}>
            Apply fixes
          </Button>
          {!canReconcile && <PermissionState />}
          {reconcileResult && (
            <p role="status" className="text-sm text-muted-foreground">
              Inspected {reconcileResult.inspected} · resolved {reconcileResult.resolved} · unknown {reconcileResult.unknown}
            </p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Scheduled maintenance</CardTitle>
          <CardDescription>commerce:scheduled — expire promotion reserved (15m), prune audits (90d), expire booking reservations.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" disabled={scheduledBusy} onClick={() => scheduledRun(false)}>{scheduledBusy ? "Running…" : "Dry-run"}</Button>
            <Button disabled={scheduledBusy} onClick={() => scheduledRun(true)}>{scheduledBusy ? "Running…" : "Apply"}</Button>
          </div>
          {scheduledResult && <pre className="max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(scheduledResult, null, 2)}</pre>}
        </CardContent>
      </Card>

      {query.isLoading ? (
        <Card>
          <CardContent className="space-y-3 p-6">
            {[1, 2, 3].map((n) => (
              <Skeleton key={n} className="h-12 w-full" />
            ))}
            <p role="status" className="sr-only">
              Loading operations...
            </p>
          </CardContent>
        </Card>
      ) : query.isError ? (
        <ApiState error={query.error} onRetry={() => query.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState title="No operations found" description="Operational evidence matching these filters will appear here." />
      ) : (
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <caption className="sr-only">Operational evidence</caption>
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="p-4">Type</th>
                    <th className="p-4">Operation</th>
                    <th className="p-4">State</th>
                    <th className="p-4">Last error</th>
                    <th className="p-4">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr className="border-b last:border-0" key={`${row.kind}-${row.id}`}>
                      <td className="p-4 capitalize">{row.kind}</td>
                      <td className="p-4">
                        <span className="font-medium">{row.label}</span>
                        <span className="block text-xs text-muted-foreground">{row.id}</span>
                      </td>
                      <td className="p-4">
                        <Badge variant={row.state === "failed" || row.state === "dead" ? "destructive" : "outline"}>{row.state}</Badge>
                      </td>
                      <td className="max-w-sm truncate p-4 text-muted-foreground">{row.last_error || "No error recorded"}</td>
                      <td className="p-4">
                        {row.kind === "job" && ["failed", "dead", "cancelled"].includes(row.state) ? (
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={!canRetry || retry.isPending}
                            onClick={() => {
                              setConfirmation({
                                title: "Retry operation job",
                                description: `Retry job ${row.id}?`,
                                run: () => retry.mutate(row.id),
                              });
                            }}
                          >
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Retry
                          </Button>
                        ) : row.kind === "notification" && ["failed", "sending"].includes(row.state) ? (
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={!canReplay || replay.isPending}
                            onClick={() => {
                              setConfirmation({
                                title: "Replay notification",
                                description: `Replay notification ${row.id}?`,
                                run: () => replay.mutate(row.id),
                              });
                            }}
                          >
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Replay
                          </Button>
                        ) : (
                          <span className="text-muted-foreground">Evidence only</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}
      {(retry.isError || replay.isError) && <ApiState error={retry.error || replay.error} onRetry={() => query.refetch()} />}
      <Pagination meta={query.data?.meta} onPageChange={setPage} />
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
    </div>
  );
}

type OpsAlert = { kind: string; state: string; window_hours: number; observed: number; baseline_x100: number; ratio_x100: number; last_seen_at?: string | null };

function OpsAlertsCard() {
  const [rows, setRows] = useState<OpsAlert[]>([]);
  const [state, setState] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const [ackTarget, setAckTarget] = useState<string | null>(null);
  const load = () => {
    setState("loading");
    api.get<{ data: OpsAlert[] }>("/api/admin/ops-alerts")
      .then((res) => { setRows(Array.isArray(res.data.data) ? res.data.data : []); setState("ready"); })
      .catch(() => setState("error"));
  };
  const ack = (kind: string) => {
    api.post(`/api/admin/ops-alerts/${encodeURIComponent(kind)}/ack`, {})
      .then(() => load())
      .catch(() => setState("error"));
  };
  const open = rows.filter((row) => row.state === "open");
  return <><Card><CardHeader><CardTitle>Anomaly alerts ({open.length} open)</CardTitle><CardDescription>Refund, payment and fulfillment failure spikes vs the 7-day baseline. Refreshed hourly.</CardDescription></CardHeader><CardContent className="space-y-2">
    {state === "idle" ? <Button size="sm" variant="outline" onClick={load}>Load alerts</Button>
      : state === "loading" ? <p role="status">Loading alerts...</p>
      : state === "error" ? <p role="alert" className="text-sm text-muted-foreground">Unavailable. <Button size="sm" variant="outline" onClick={load}>Retry</Button></p>
      : rows.length ? <div className="space-y-1">{rows.map((row) => <div key={row.kind} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm"><span><Badge variant={row.state === "open" ? "destructive" : "secondary"}>{row.state}</Badge> {row.kind.replace(/_/g, " ")} · {row.observed}/24h vs {(row.baseline_x100 / 100).toFixed(2)}/day</span>{row.state === "open" ? <Button size="sm" variant="outline" onClick={() => setAckTarget(row.kind)}>Acknowledge</Button> : null}</div>)}</div>
      : <p className="text-sm text-muted-foreground">No anomalies. The hourly rollup keeps this fresh.</p>}
  </CardContent></Card>
  <ConfirmDialog
    open={ackTarget !== null}
    title="Acknowledge anomaly alert"
    description={`Acknowledge ${ackTarget ?? "this alert"}?`}
    onCancel={() => setAckTarget(null)}
    onConfirm={() => {
      const kind = ackTarget;
      setAckTarget(null);
      if (kind) ack(kind);
    }}
  />
  </>;
}
