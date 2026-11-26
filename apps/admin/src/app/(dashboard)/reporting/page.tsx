"use client";

import { useCallback, useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { CapabilityGate } from "@/components/capability-gate";
import { Badge } from "@/components/ui/badge";
import { Pagination } from "@/components/pagination";
import { reportingService } from "@/services/reporting.service";
import { idempotencyKey } from "@/services/merchant.service";
import api from "@/services/api";
import { ApiState } from "@/modules/merchant/api-state";
import { Download, RefreshCw } from "lucide-react";

type Insights = { authoritative?: { orders?: number; sales_minor_by_currency?: Record<string, number>; refunds_minor_by_currency?: Record<string, number> }; funnel_estimate?: { counts?: Record<string, number>; conversion?: Record<string, number | null> }; product_performance?: { order_lines?: Array<{ sku: string; name: string; units: number; revenue_by_currency: Record<string, number> }>; engagement_signals?: Array<{ product_id: string; views: number; wishlist_adds: number }> }; retention_estimate?: { identified_customers?: number; repeat_customers?: number; repeat_rate?: number | null }; engagement_estimate?: Record<string, number>; recommendation_signals?: { status?: string; reason?: string } };

type FailureRow = { id?: string | number; queue?: string; name?: string; state?: string; attempts?: number; max_attempts?: number; available_at?: string };
type FailuresMeta = { page: number; per_page: number; total: number; last_page: number };
type ExportJob = { id: string; dataset: string; format: string; state: string; rows_written?: number; download_url?: string | null; from_at?: string; to_at?: string; created_at?: string };
type ReportContract = { schema_version?: number; dimensions?: string[]; export_datasets?: string[]; max_window_days?: number };

const EXPORT_DATASETS = ["orders", "payments", "refunds", "subscriptions", "b2b", "promotions", "fulfillment", "inventory"];
const EXPORT_FORMATS = ["csv", "jsonl"];

const money = (values: Record<string, number> = {}) => Object.entries(values).map(([currency, value]) => `${currency} ${value.toLocaleString()}`).join(" · ") || "0";

const unwrapData = <T,>(envelope: unknown): T => {
  const body = envelope as { data?: T };
  return (body && typeof body === "object" && "data" in body ? body.data : envelope) as T;
};

export default function ReportingPage() { return <CapabilityGate capability="reports"><ReportingContent /></CapabilityGate>; }

function ReportingContent() {
  const [from, setFrom] = useState(() => new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10));
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));
  const [windowError, setWindowError] = useState<string | null>(null);
  const [state, setState] = useState<{ loading: boolean; data?: Insights; error?: unknown }>({ loading: true });
  const load = useCallback(() => {
    if (from > to) {
      setWindowError("The start date must be on or before the end date.");
      return;
    }
    setWindowError(null);
    setState({ loading: true });
    void reportingService.insights({ from, to }).then((data) => setState({ loading: false, data: data.data as Insights })).catch((error) => setState({ loading: false, error }));
  }, [from, to]);
  useEffect(() => { load(); }, [load]);
  const data = state.data;
  return <div className="space-y-6"><PageHeader title="Reporting" description="Merchant insights and bounded aggregate signals for the selected date window" />
    <Card><CardContent className="flex flex-wrap items-end gap-3 pt-6"><label className="grid gap-1 text-sm">From<input className="rounded border bg-background px-2 py-1" type="date" value={from} onChange={(event) => setFrom(event.target.value)} /></label><label className="grid gap-1 text-sm">To<input className="rounded border bg-background px-2 py-1" type="date" value={to} onChange={(event) => setTo(event.target.value)} /></label><Button onClick={load}>Apply</Button>{windowError && <p role="alert" className="w-full text-sm text-destructive">{windowError}</p>}</CardContent></Card>
    {state.loading ? <p role="status">Loading insights...</p> : state.error ? <ApiState error={state.error} onRetry={load} /> : data ? <>
      <div className="grid gap-4 md:grid-cols-3"><Metric title="Orders" value={String(data.authoritative?.orders ?? 0)} note="Authoritative order records" /><Metric title="Sales" value={money(data.authoritative?.sales_minor_by_currency)} note="Authoritative, partitioned by currency" /><Metric title="Refunds" value={money(data.authoritative?.refunds_minor_by_currency)} note="Authoritative refund records" /></div>
      <div className="grid gap-4 lg:grid-cols-2"><Card><CardHeader><CardTitle>Funnel estimate</CardTitle></CardHeader><CardContent className="space-y-2 text-sm">{Object.entries(data.funnel_estimate?.counts ?? {}).map(([key, value]) => <div className="flex justify-between" key={key}><span>{key.replaceAll("_", " ")}</span><strong>{value}</strong></div>)}<p className="pt-2 text-xs text-muted-foreground">Derived from consented analytics events, not order truth.</p></CardContent></Card><Card><CardHeader><CardTitle>Retention and engagement estimate</CardTitle></CardHeader><CardContent className="space-y-2 text-sm"><p>Identified customers: <strong>{data.retention_estimate?.identified_customers ?? 0}</strong></p><p>Repeat customers: <strong>{data.retention_estimate?.repeat_customers ?? 0}</strong></p><p>Wishlist adds: <strong>{data.engagement_estimate?.wishlist_add ?? 0}</strong></p><p>Recommendation signals: <strong>{data.recommendation_signals?.status}</strong></p><p className="text-xs text-muted-foreground">No customer identities or PII are returned.</p></CardContent></Card></div>
      <Card><CardHeader><CardTitle>Product performance</CardTitle></CardHeader><CardContent><div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Product performance aggregates</caption><thead><tr className="border-b"><th className="p-2">Product</th><th className="p-2">Units</th><th className="p-2">Revenue by currency</th></tr></thead><tbody>{(data.product_performance?.order_lines ?? []).map((item) => <tr className="border-b" key={item.sku}><td className="p-2">{item.name}</td><td className="p-2">{item.units}</td><td className="p-2">{money(item.revenue_by_currency)}</td></tr>)}</tbody></table>{!data.product_performance?.order_lines?.length && <p className="py-4 text-sm text-muted-foreground">No product sales in this window.</p>}</div></CardContent></Card>
    </> : <p>No insights returned for this window.</p>}
    <FailuresSection />
    <ContractSection />
    <ExportsSection from={from} to={to} />
  </div>;
}

function FailuresSection() {
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState<FailureRow[]>([]);
  const [meta, setMeta] = useState<FailuresMeta | undefined>(undefined);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(undefined);
  const load = useCallback(() => {
    setLoading(true);
    setError(undefined);
    void api.get<{ data: FailureRow[]; meta: FailuresMeta }>("/api/v2/admin/reports/failures", { params: { page, per_page: 10 } })
      .then((response) => {
        setRows(Array.isArray(response.data?.data) ? response.data.data : []);
        setMeta(response.data?.meta);
      })
      .catch((cause: unknown) => setError(cause))
      .finally(() => setLoading(false));
  }, [page]);
  useEffect(() => { load(); }, [load]);
  return <Card><CardHeader><CardTitle>Operational failures</CardTitle></CardHeader><CardContent className="space-y-3">
    {loading ? <p role="status">Loading failures...</p> : error !== undefined ? <ApiState error={error} onRetry={load} /> : rows.length === 0 ? <p className="text-sm text-muted-foreground">No failed or dead jobs. The queue is healthy.</p> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Failed jobs</caption><thead><tr className="border-b"><th className="p-2">Job</th><th className="p-2">Queue</th><th className="p-2">State</th><th className="p-2">Attempts</th><th className="p-2">Available at</th></tr></thead><tbody>{rows.map((row, index) => <tr className="border-b" key={String(row.id ?? index)}><td className="p-2 font-mono text-xs">{String(row.name ?? row.id ?? "job")}</td><td className="p-2">{String(row.queue ?? "-")}</td><td className="p-2"><Badge variant={row.state === "dead" ? "destructive" : "secondary"}>{String(row.state ?? "unknown")}</Badge></td><td className="p-2">{row.attempts ?? 0}/{row.max_attempts ?? "-"}</td><td className="p-2">{String(row.available_at ?? "-")}</td></tr>)}</tbody></table></div>}
    {meta && <Pagination meta={meta as never} onPageChange={setPage} />}
  </CardContent></Card>;
}

function ContractSection() {
  const [contract, setContract] = useState<ReportContract | null>(null);
  const [error, setError] = useState<unknown>(undefined);
  const load = useCallback(() => {
    setError(undefined);
    void api.get<{ data: ReportContract }>("/api/v2/admin/reports/contract")
      .then((response) => setContract(unwrapData<ReportContract>(response.data)))
      .catch((cause: unknown) => setError(cause));
  }, []);
  useEffect(() => { load(); }, [load]);
  return <Card><CardHeader><CardTitle>Reporting contract</CardTitle></CardHeader><CardContent className="space-y-2 text-sm">
    {error !== undefined ? <ApiState error={error} onRetry={load} /> : !contract ? <p role="status">Loading contract...</p> : <>
      <p>Schema version: <strong>{contract.schema_version ?? "unknown"}</strong> · Max window: <strong>{contract.max_window_days ?? 366} days</strong></p>
      <p className="text-muted-foreground">Dimensions: {(contract.dimensions ?? []).join(", ") || "none reported"}</p>
      <p className="text-muted-foreground">Export datasets: {(contract.export_datasets ?? []).join(", ") || "none reported"}</p>
    </>}
  </CardContent></Card>;
}

function ExportsSection({ from, to }: { from: string; to: string }) {
  const [dataset, setDataset] = useState("orders");
  const [format, setFormat] = useState("csv");
  const [jobs, setJobs] = useState<ExportJob[]>([]);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<unknown>(undefined);
  const [notice, setNotice] = useState<string | null>(null);

  const refreshJob = useCallback(async (id: string) => {
    const response = await api.get<{ data: ExportJob }>(`/api/v2/admin/exports/${encodeURIComponent(id)}`);
    const job = unwrapData<ExportJob>(response.data);
    setJobs((current) => current.map((item) => (item.id === id ? { ...item, ...job } : item)));
    return job;
  }, []);

  const create = useCallback(() => {
    if (from > to) {
      setError(new Error("The export start date must be on or before the end date."));
      return;
    }
    setCreating(true);
    setError(undefined);
    setNotice(null);
    void api.post<{ data: ExportJob }>("/api/v2/admin/exports", { dataset, format, from, to }, { idempotencyKey: idempotencyKey() })
      .then((response) => {
        const job = unwrapData<ExportJob>(response.data);
        setJobs((current) => [job, ...current]);
        setNotice(`Export ${job.id} queued for ${dataset} (${format}).`);
      })
      .catch((cause: unknown) => setError(cause))
      .finally(() => setCreating(false));
  }, [dataset, format, from, to]);

  const retry = useCallback((id: string) => {
    setError(undefined);
    void api.post<{ data: ExportJob }>(`/api/v2/admin/exports/${encodeURIComponent(id)}/retry`, undefined, { idempotencyKey: idempotencyKey() })
      .then((response) => {
        const job = unwrapData<ExportJob>(response.data);
        setJobs((current) => current.map((item) => (item.id === id ? { ...item, ...job } : item)));
      })
      .catch((cause: unknown) => setError(cause));
  }, []);

  useEffect(() => {
    const active = jobs.some((job) => job.state === "queued" || job.state === "running");
    if (!active) return;
    const timer = setInterval(() => {
      void Promise.allSettled(jobs.filter((job) => job.state === "queued" || job.state === "running").map((job) => refreshJob(job.id)));
    }, 4000);
    return () => clearInterval(timer);
  }, [jobs, refreshJob]);

  return <Card><CardHeader><CardTitle>Exports</CardTitle></CardHeader><CardContent className="space-y-4 text-sm">
    <div className="flex flex-wrap items-end gap-3">
      <label className="grid gap-1">Dataset<select aria-label="Export dataset" className="h-10 rounded-md border bg-background px-3" value={dataset} onChange={(event) => setDataset(event.target.value)}>{EXPORT_DATASETS.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      <label className="grid gap-1">Format<select aria-label="Export format" className="h-10 rounded-md border bg-background px-3" value={format} onChange={(event) => setFormat(event.target.value)}>{EXPORT_FORMATS.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      <Button onClick={create} disabled={creating}><Download className="mr-2 h-4 w-4" />{creating ? "Queueing..." : "Start export"}</Button>
      <span className="text-xs text-muted-foreground">Window {from} to {to}. Completed exports expose a download link.</span>
    </div>
    {notice && <p role="status" className="text-emerald-700">{notice}</p>}
    {error !== undefined && <ApiState error={error} onRetry={() => setError(undefined)} />}
    {jobs.length === 0 ? <p className="text-muted-foreground">No export jobs started yet in this session.</p> : <div className="space-y-2">{jobs.map((job) => <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3" key={job.id}>
      <div><p className="font-mono text-xs">{job.id}</p><p className="text-xs text-muted-foreground">{job.dataset} · {job.format} · rows {job.rows_written ?? 0}</p></div>
      <div className="flex items-center gap-2">
        <Badge variant={job.state === "completed" ? "success" : job.state === "failed" ? "destructive" : "secondary"}>{job.state}</Badge>
        <Button size="sm" variant="outline" onClick={() => void refreshJob(job.id)}><RefreshCw className="mr-2 h-3 w-3" />Refresh</Button>
        {job.state === "failed" && <Button size="sm" variant="outline" onClick={() => retry(job.id)}>Retry</Button>}
        {job.state === "completed" && job.download_url && <a className="inline-flex items-center rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground" href={job.download_url} target="_blank" rel="noreferrer"><Download className="mr-2 h-3 w-3" />Download</a>}
      </div>
    </div>)}</div>}
  </CardContent></Card>;
}

function Metric({ title, value, note }: { title: string; value: string; note: string }) { return <Card><CardHeader><CardTitle className="text-sm">{title}</CardTitle></CardHeader><CardContent><p className="text-2xl font-semibold">{value}</p><p className="text-xs text-muted-foreground">{note}</p></CardContent></Card>; }
