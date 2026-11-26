"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { CapabilityGate } from "@/components/capability-gate";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/components/page-header";
import { Pagination } from "@/components/pagination";
import { ApiState } from "@/modules/merchant/api-state";
import { useAuth } from "@/hooks/use-auth";
import { toast } from "@/hooks/use-toast";
import { useCreateSubscriptionPlan, useDeleteSubscriptionPlan, useSubscriptionPlans, useUpdateSubscriptionPlan } from "@/hooks/use-subscription-plans";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { idempotencyKey } from "@/services/merchant.service";
import { subscriptionsService, type SubscriptionPlanInput, type SubscriptionPlanRow } from "@/services/subscriptions.service";

const money = (minor: number, currency: string) => `${currency} ${(minor / 100).toLocaleString()}`;
const cadence = (plan: SubscriptionPlanRow) => `${plan.interval_count > 1 ? `${plan.interval_count} ` : ""}${plan.interval}`;

export default function SubscriptionsPage() {
  return <CapabilityGate capability="subscriptions"><SubscriptionsContent /></CapabilityGate>;
}

function SubscriptionsContent() {
  const [tab, setTab] = useState<"subscriptions" | "plans">("subscriptions");
  return <div className="space-y-5">
    <PageHeader title="Subscriptions" description="Scoped recurring-commerce operations. Customer identity and payment evidence are redacted." />
    <div role="tablist" aria-label="Subscription operations" className="flex gap-2 border-b pb-2">
      <button role="tab" aria-selected={tab === "subscriptions"} className={`rounded-md px-3 py-2 text-sm ${tab === "subscriptions" ? "bg-primary text-primary-foreground" : "text-muted-foreground"}`} onClick={() => setTab("subscriptions")}>Subscriptions</button>
      <button role="tab" aria-selected={tab === "plans"} className={`rounded-md px-3 py-2 text-sm ${tab === "plans" ? "bg-primary text-primary-foreground" : "text-muted-foreground"}`} onClick={() => setTab("plans")}>Plans</button>
    </div>
    {tab === "subscriptions" ? <SubscriptionsPanel /> : <PlansPanel />}
  </div>;
}

function SubscriptionsPanel() {
  const [page, setPage] = useState(1); const [q, setQ] = useState(""); const [state, setState] = useState(""); const [selected, setSelected] = useState<string | null>(null);
  const [retryError, setRetryError] = useState<unknown>(undefined);
  const [lastAttempt, setLastAttempt] = useState<string | null>(null);
  const [retryTarget, setRetryTarget] = useState<string | null>(null);
  const { user } = useAuth(); const client = useQueryClient(); const role = user?.role?.toLowerCase() || "";
  const canRetry = canOperateMerchant(user, "subscriptions") && ["admin", "owner", "finance"].includes(role);
  const list = useQuery({ queryKey: ["admin-subscriptions", page, q, state], queryFn: () => subscriptionsService.list({ page, per_page: 25, q: q || undefined, state: state || undefined }) });
  const summary = useQuery({ queryKey: ["admin-subscription-summary"], queryFn: () => subscriptionsService.summary() });
  const detail = useQuery({ enabled: selected !== null, queryKey: ["admin-subscription", selected], queryFn: () => subscriptionsService.detail(selected as string) });
  const retry = useMutation({
    mutationFn: (id: string) => subscriptionsService.retry(id, idempotencyKey()),
    onSuccess: () => {
      setRetryError(undefined);
      setLastAttempt(null);
      client.invalidateQueries({ queryKey: ["admin-subscriptions"] });
      client.invalidateQueries({ queryKey: ["admin-subscription"] });
      toast({ title: "Retry queued", description: "The renewal retry was queued safely.", variant: "success" });
    },
    onError: (error) => setRetryError(error),
  });
  const rows = list.data?.data || []; const totals = summary.data;
  return <div className="space-y-5">
    {summary.isLoading ? <p role="status">Loading subscription summary...</p> : summary.isError ? <ApiState error={summary.error} onRetry={() => void summary.refetch()} /> : totals && <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"><Metric title="Active" value={String(totals.active)} /><Metric title="Paused / cancelled" value={`${totals.paused} / ${totals.cancelled}`} /><Metric title="Churn" value={totals.churn_rate === null ? "n/a" : `${(totals.churn_rate * 100).toFixed(1)}%`} /><Metric title="Active recurring" value={Object.entries(totals.active_recurring_revenue).map(([currency, amount]) => money(amount, currency)).join(" · ") || "0"} /><Metric title="Failure queue" value={String(totals.payment_failure_queue)} /></div>}
    <Card><CardContent className="flex flex-wrap gap-3 pt-6"><Input className="max-w-sm" aria-label="Search subscriptions" placeholder="Search subscription or plan id" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} /><select aria-label="Subscription state" className="h-10 rounded-md border bg-background px-3 text-sm" value={state} onChange={(e) => { setState(e.target.value); setPage(1); }}><option value="">All states</option><option value="active">Active</option><option value="paused">Paused</option><option value="cancelled">Cancelled</option></select><Badge variant="outline">Scope enforced by API</Badge></CardContent></Card>
    {list.isLoading ? <p role="status">Loading subscriptions...</p> : list.isError ? <ApiState error={list.error} onRetry={() => void list.refetch()} /> : <Card><CardContent className="p-0"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Scoped subscriptions</caption><thead><tr className="border-b"><th className="p-4">Subscription</th><th className="p-4">Customer reference</th><th className="p-4">Plan</th><th className="p-4">State</th><th className="p-4">Next billing</th><th className="p-4">Action</th></tr></thead><tbody>{rows.map((row) => <tr className="border-b last:border-0" key={row.id}><td className="p-4 font-medium">{row.id}</td><td className="p-4 font-mono text-xs">{row.customer_ref.slice(0, 12)}...</td><td className="p-4">{money(row.amount_minor, row.currency)} / {row.interval_count} {row.interval}</td><td className="p-4"><Badge variant={row.state === "cancelled" ? "destructive" : "outline"}>{row.state}</Badge></td><td className="p-4">{row.next_billing_at || "-"}</td><td className="p-4"><Button size="sm" variant="outline" onClick={() => { setSelected(row.id); setRetryError(undefined); }}>View context</Button></td></tr>)}</tbody></table>{!rows.length && <p className="p-6 text-sm text-muted-foreground">No subscriptions in this scope.</p>}</div></CardContent></Card>}
    {list.data?.meta && <Pagination meta={list.data.meta} onPageChange={setPage} />}
    {selected && (detail.isLoading ? <p role="status">Loading subscription context...</p> : detail.isError ? <ApiState error={detail.error} onRetry={() => void detail.refetch()} /> : detail.data && <Card><CardHeader><CardTitle>Subscription context: {detail.data.id}</CardTitle></CardHeader><CardContent className="space-y-3 text-sm"><p>Customer reference: <span className="font-mono">{detail.data.customer_ref}</span>. No name, email, address, or payment instrument is available in this console.</p><p>Lifecycle: {detail.data.state}. Pause/cancel timestamps are shown as context only; authoritative lifecycle changes remain in the customer subscription flow.</p>{retryError !== undefined && <ApiState error={retryError} onRetry={() => { if (lastAttempt && !retry.isPending) retry.mutate(lastAttempt); }} />}<div className="overflow-x-auto"><table className="w-full text-left"><thead><tr className="border-b"><th className="py-2">Renewal</th><th className="py-2">State</th><th className="py-2">Amount</th><th className="py-2">Action</th></tr></thead><tbody>{detail.data.renewals.map((attempt) => <tr className="border-b" key={attempt.id}><td className="py-2">{attempt.id}</td><td className="py-2">{attempt.state}{attempt.error_code ? " (payment failure)" : ""}</td><td className="py-2">{money(attempt.amount_minor, attempt.currency)}</td><td className="py-2">{["failed", "retryable", "unknown"].includes(attempt.state) && <Button size="sm" variant="outline" disabled={!canRetry || retry.isPending} onClick={() => setRetryTarget(attempt.id)}>{retry.isPending ? "Queueing..." : "Queue safe retry"}</Button>}</td></tr>)}</tbody></table></div></CardContent></Card>)}
    <ConfirmDialog
      open={retryTarget !== null}
      title="Queue safe retry"
      description={retryTarget ? `Queue a safe retry for renewal ${retryTarget}? The backend schedules the retry without charging twice.` : undefined}
      confirmLabel="Queue retry"
      isPending={retry.isPending}
      onCancel={() => setRetryTarget(null)}
      onConfirm={() => {
        const target = retryTarget;
        setRetryTarget(null);
        if (target) {
          setLastAttempt(target);
          retry.mutate(target);
        }
      }}
    />
  </div>;
}

function PlansPanel() {
  const [state, setState] = useState("");
  const [editing, setEditing] = useState<SubscriptionPlanRow | null | undefined>(undefined);
  const [deleteTarget, setDeleteTarget] = useState<SubscriptionPlanRow | null>(null);
  const plans = useSubscriptionPlans({ state: state || undefined });
  const create = useCreateSubscriptionPlan();
  const update = useUpdateSubscriptionPlan();
  const remove = useDeleteSubscriptionPlan();
  const isPending = create.isPending || update.isPending;
  return <div className="space-y-5">
    <Card><CardContent className="flex flex-wrap items-center gap-3 pt-6">
      <select aria-label="Plan state filter" className="h-10 rounded-md border bg-background px-3 text-sm" value={state} onChange={(event) => setState(event.target.value)}><option value="">All states</option><option value="active">Active</option><option value="disabled">Disabled</option></select>
      <Button onClick={() => setEditing(null)}>Create plan</Button>
      <Badge variant="outline">Admin · owner · finance</Badge>
    </CardContent></Card>
    {plans.isLoading ? <p role="status">Loading subscription plans...</p> : plans.isError ? <ApiState error={plans.error} onRetry={() => void plans.refetch()} /> : <Card><CardContent className="p-0"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Subscription plans</caption><thead><tr className="border-b"><th className="p-4">Name</th><th className="p-4">Price</th><th className="p-4">Cadence</th><th className="p-4">Trial</th><th className="p-4">State</th><th className="p-4">Provider</th><th className="p-4">Actions</th></tr></thead><tbody>{plans.plans.map((plan) => <tr className="border-b last:border-0" key={plan.id}><td className="p-4 font-medium">{plan.name}</td><td className="p-4">{money(plan.amount_minor, plan.currency)}</td><td className="p-4">{cadence(plan)}</td><td className="p-4">{plan.trial_period && plan.trial_period_count > 0 ? `${plan.trial_period_count} ${plan.trial_period}` : "-"}</td><td className="p-4"><Badge variant={plan.state === "disabled" ? "destructive" : "outline"}>{plan.state}</Badge></td><td className="p-4">{plan.provider || "-"}</td><td className="p-4"><div className="flex gap-2"><Button size="sm" variant="outline" onClick={() => setEditing(plan)}>Edit</Button><Button size="sm" variant="destructive" onClick={() => setDeleteTarget(plan)}>Delete</Button></div></td></tr>)}</tbody></table>{!plans.plans.length && <p className="p-6 text-sm text-muted-foreground">No subscription plans yet.</p>}</div></CardContent></Card>}
    {remove.isError && <ApiState error={remove.error} />}
    {editing !== undefined && <PlanDialog plan={editing} isPending={isPending} onCancel={() => setEditing(undefined)} onSubmit={(input) => {
      if (editing === null) create.mutate(input, { onSuccess: () => setEditing(undefined) });
      else update.mutate({ id: editing.id, data: input }, { onSuccess: () => setEditing(undefined) });
    }} />}
    <ConfirmDialog
      open={deleteTarget !== null}
      title="Delete plan"
      description={deleteTarget ? `Delete ${deleteTarget.name}? Plans referenced by subscriptions cannot be removed.` : undefined}
      confirmLabel="Delete plan"
      destructive
      isPending={remove.isPending}
      onCancel={() => setDeleteTarget(null)}
      onConfirm={() => {
        const target = deleteTarget;
        setDeleteTarget(null);
        if (target) remove.mutate(target.id);
      }}
    />
  </div>;
}

function PlanDialog({ plan, isPending, onCancel, onSubmit }: { plan: SubscriptionPlanRow | null; isPending: boolean; onCancel: () => void; onSubmit: (input: SubscriptionPlanInput) => void }) {
  const [name, setName] = useState(plan?.name ?? "");
  const [amount, setAmount] = useState(String(plan?.amount_minor ?? 1000));
  const [currency, setCurrency] = useState(plan?.currency ?? "USD");
  const [interval, setInterval] = useState(plan?.interval ?? "monthly");
  const [intervalCount, setIntervalCount] = useState(String(plan?.interval_count ?? 1));
  const [trialPeriod, setTrialPeriod] = useState(plan?.trial_period ?? "");
  const [trialCount, setTrialCount] = useState(String(plan?.trial_period_count ?? 0));
  const [state, setState] = useState(plan?.state ?? "active");
  const [provider, setProvider] = useState(plan?.provider ?? "");
  return <Dialog open onOpenChange={(open) => { if (!open && !isPending) onCancel(); }}>
    <DialogContent>
      <DialogHeader><DialogTitle>{plan === null ? "Create plan" : "Edit plan"}</DialogTitle><DialogDescription>Prices are stored in minor units. Plans must be active before products can link to them.</DialogDescription></DialogHeader>
      <form className="space-y-3" onSubmit={(event) => {
        event.preventDefault();
        onSubmit({ name, amount_minor: Number(amount) || 0, currency: currency.toUpperCase(), interval, interval_count: Number(intervalCount) || 1, trial_period: trialPeriod || null, trial_period_count: Number(trialCount) || 0, state, provider: provider || null });
      }}>
        <div className="space-y-2"><Label htmlFor="plan-name">Name</Label><Input id="plan-name" aria-label="Plan name" value={name} onChange={(event) => setName(event.target.value)} required /></div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-2"><Label htmlFor="plan-amount">Amount (minor units)</Label><Input id="plan-amount" aria-label="Amount (minor units)" type="number" min="1" value={amount} onChange={(event) => setAmount(event.target.value)} required /></div>
          <div className="space-y-2"><Label htmlFor="plan-currency">Currency</Label><Input id="plan-currency" aria-label="Currency" maxLength={3} value={currency} onChange={(event) => setCurrency(event.target.value)} required /></div>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-2"><Label htmlFor="plan-interval">Interval</Label><select id="plan-interval" aria-label="Interval" className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={interval} onChange={(event) => setInterval(event.target.value)}><option value="weekly">weekly</option><option value="monthly">monthly</option><option value="yearly">yearly</option></select></div>
          <div className="space-y-2"><Label htmlFor="plan-interval-count">Interval count</Label><Input id="plan-interval-count" aria-label="Interval count" type="number" min="1" max="12" value={intervalCount} onChange={(event) => setIntervalCount(event.target.value)} /></div>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-2"><Label htmlFor="plan-trial-period">Trial period</Label><select id="plan-trial-period" aria-label="Trial period" className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={trialPeriod} onChange={(event) => setTrialPeriod(event.target.value)}><option value="">No trial</option><option value="weekly">weekly</option><option value="monthly">monthly</option><option value="yearly">yearly</option></select></div>
          <div className="space-y-2"><Label htmlFor="plan-trial-count">Trial count</Label><Input id="plan-trial-count" aria-label="Trial count" type="number" min="0" max="90" value={trialCount} onChange={(event) => setTrialCount(event.target.value)} /></div>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-2"><Label htmlFor="plan-state">State</Label><select id="plan-state" aria-label="Plan state" className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={state} onChange={(event) => setState(event.target.value)}><option value="active">active</option><option value="disabled">disabled</option></select></div>
          <div className="space-y-2"><Label htmlFor="plan-provider">Provider</Label><Input id="plan-provider" aria-label="Provider" value={provider} onChange={(event) => setProvider(event.target.value)} placeholder="Optional" /></div>
        </div>
        <DialogFooter><Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>Cancel</Button><Button type="submit" disabled={isPending}>{isPending ? "Saving..." : plan === null ? "Create plan" : "Save plan"}</Button></DialogFooter>
      </form>
    </DialogContent>
  </Dialog>;
}

function Metric({ title, value }: { title: string; value: string }) { return <Card><CardHeader><CardTitle className="text-sm">{title}</CardTitle></CardHeader><CardContent><p className="text-2xl font-semibold">{value}</p></CardContent></Card>; }
