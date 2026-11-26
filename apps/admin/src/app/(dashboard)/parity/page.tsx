"use client";
import { useState } from "react";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ApiState } from "@/modules/merchant/api-state";
import { parityService } from "@/services/parity.service";
import { idempotencyKey } from "@/services/merchant.service";
import { useI18n } from "@/providers/i18n-provider";
import api from "@/services/api";

function JsonView({ data }: { data: unknown }) {
  return <pre className="max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(data, null, 2)}</pre>;
}

export default function ParityPage() {
  const { t } = useI18n();
  const [jobType, setJobType] = useState("reindex");
  const [jobPayload, setJobPayload] = useState('{"force":false}');
  const [jobId, setJobId] = useState("");
  const [jobAction, setJobAction] = useState("retry");
  const [seoType, setSeoType] = useState("product");
  const [seoId, setSeoId] = useState("");
  const [seoBody, setSeoBody] = useState('{"title":"Test SEO"}');
  const [redirectSource, setRedirectSource] = useState("/old");
  const [redirectTarget, setRedirectTarget] = useState("/new");
  const [loyaltyCustomer, setLoyaltyCustomer] = useState("cust-1");
  const [loyaltyOrder, setLoyaltyOrder] = useState("order-1");
  const [loyaltyTotal, setLoyaltyTotal] = useState("50000");
  const [subId, setSubId] = useState("");
  const [subPeriod, setSubPeriod] = useState("2026-10");
  const [result, setResult] = useState<unknown>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true); setError(null); setResult(null);
    try { const r = await fn(); setResult(r); } catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <div className="space-y-5">
      <PageHeader title={t("parity.title")} description={t("parity.description")} />
      {error ? <ApiState error={error} onRetry={() => setError(null)} /> : null}
      {result ? <JsonView data={result} /> : null}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle>{t("parity.jobsTitle")}</CardTitle><CardDescription>POST /api/v2/admin/jobs · GET /api/v2/admin/jobs/{`{id}`} · POST /{`{action}`}</CardDescription></CardHeader>
          <CardContent className="space-y-3">
            <div className="grid gap-2"><Label>Job type</Label><Input value={jobType} onChange={(e) => setJobType(e.target.value)} placeholder="reindex" /><Label>Payload JSON</Label><Textarea value={jobPayload} onChange={(e) => setJobPayload(e.target.value)} rows={3} /><Button size="sm" disabled={busy} onClick={() => run(async () => parityService.enqueueJob({ type: jobType, payload: JSON.parse(jobPayload || "{}") }))}>{t("parity.enqueueJob")}</Button></div>
            <div className="grid gap-2 md:grid-cols-3"><div><Label>Job ID</Label><Input value={jobId} onChange={(e) => setJobId(e.target.value)} placeholder="job id" /></div><div><Label>Action</Label><Input value={jobAction} onChange={(e) => setJobAction(e.target.value)} placeholder="retry" /></div><div className="flex flex-wrap gap-2 items-end"><Button variant="outline" size="sm" disabled={busy || !jobId} onClick={() => run(() => parityService.job(jobId))}>{t("parity.getJob")}</Button><Button variant="outline" size="sm" disabled={busy || !jobId} onClick={() => run(() => parityService.jobAction(jobId, jobAction))}>{t("parity.runAction")}</Button></div></div>
          </CardContent></Card>
        <Card><CardHeader><CardTitle>{t("parity.auditTitle")}</CardTitle><CardDescription>GET /api/v2/admin/audit-logs · /export · /notification-events</CardDescription></CardHeader>
          <CardContent className="flex flex-wrap gap-2"><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.auditLogs({ per_page: 5 }))}>{t("parity.loadAudit")}</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.auditExport({ format: "csv" }))}>{t("parity.exportAudit")}</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.notificationEvents({ per_page: 5 }))}>{t("parity.notifEvents")}</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.sellerOrders({ per_page: 5 }))}>{t("parity.sellerOrders")}</Button></CardContent></Card>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle>{t("parity.seoTitle")}</CardTitle><CardDescription>GET /api/admin/seo/health · /{`{type}`}/{`{id}`} · PUT · POST /redirects</CardDescription></CardHeader>
          <CardContent className="space-y-3"><div className="flex flex-wrap gap-2"><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.seoHealth())}>{t("parity.seoHealth")}</Button></div>
            <div className="grid gap-2 md:grid-cols-3"><div><Label>Type</Label><Input value={seoType} onChange={(e) => setSeoType(e.target.value)} /></div><div><Label>ID</Label><Input value={seoId} onChange={(e) => setSeoId(e.target.value)} /></div><div className="flex gap-2 items-end"><Button variant="outline" size="sm" disabled={busy || !seoId} onClick={() => run(() => parityService.seoGet(seoType, seoId))}>Get</Button><Button variant="outline" size="sm" disabled={busy || !seoId} onClick={() => run(() => parityService.seoSchema(seoType, seoId))}>Schema</Button></div></div>
            <div><Label>PUT body JSON</Label><Textarea value={seoBody} onChange={(e) => setSeoBody(e.target.value)} rows={2} /><Button size="sm" disabled={busy || !seoId} onClick={() => run(() => parityService.seoPut(seoType, seoId, JSON.parse(seoBody || "{}")))}>{t("parity.putSeo")}</Button></div>
            <div className="grid gap-2 md:grid-cols-3"><div><Label>Redirect source</Label><Input value={redirectSource} onChange={(e) => setRedirectSource(e.target.value)} /></div><div><Label>Target</Label><Input value={redirectTarget} onChange={(e) => setRedirectTarget(e.target.value)} /></div><div className="flex items-end"><Button size="sm" disabled={busy} onClick={() => run(() => parityService.redirectsCreate({ source: redirectSource, target: redirectTarget }))}>{t("parity.createRedirect")}</Button></div></div>
          </CardContent></Card>
        <Card><CardHeader><CardTitle>{t("parity.analyticsTitle")}</CardTitle><CardDescription>GET /api/admin/analytics/funnel|events|export · /api/admin/smart/inventory|conversion|setup-guide</CardDescription></CardHeader>
          <CardContent className="flex flex-wrap gap-2"><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.analyticsFunnel({}))}>Funnel</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.analyticsEvents({ per_page: 5 }))}>Events</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.analyticsExport({ format: "csv" }))}>Export</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.smartInventory({}))}>Smart inventory</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.smartConversion({}))}>Smart conversion</Button><Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => parityService.smartSetupGuide())}>Setup guide</Button><Button variant="outline" size="sm" disabled={busy} onClick={async () => {
            await run(() => api.get("/api/v2/admin/operations", { params: { per_page: 3 } }).then(r => (r.data as {data?: unknown}).data));
          }}>{t("parity.opsDirect")}</Button></CardContent></Card>
        <Card><CardHeader><CardTitle>{t("parity.erpTitle")}</CardTitle><CardDescription>POST /api/v2/admin/erp/loyalty-evaluate · /subscriptions/{`{id}`}/renew · webhooks /api/webhooks/erp/* (HMAC)</CardDescription></CardHeader>
          <CardContent className="space-y-3">
            <div className="grid gap-2 md:grid-cols-4"><div><Label>Customer ID</Label><Input value={loyaltyCustomer} onChange={(e) => setLoyaltyCustomer(e.target.value)} /></div><div><Label>Order ID</Label><Input value={loyaltyOrder} onChange={(e) => setLoyaltyOrder(e.target.value)} /></div><div><Label>Total minor</Label><Input value={loyaltyTotal} onChange={(e) => setLoyaltyTotal(e.target.value)} /></div><div className="flex items-end"><Button size="sm" disabled={busy} onClick={() => run(() => parityService.erpLoyaltyEvaluate({ customer_id: loyaltyCustomer, order_id: loyaltyOrder, order_total_minor: Number(loyaltyTotal) }))}>{t("parity.evaluateTier")}</Button></div></div>
            <div className="grid gap-2 md:grid-cols-3"><div><Label>Subscription ID</Label><Input value={subId} onChange={(e) => setSubId(e.target.value)} placeholder="sub id" /></div><div><Label>Period</Label><Input value={subPeriod} onChange={(e) => setSubPeriod(e.target.value)} /></div><div className="flex items-end"><Button size="sm" disabled={busy || !subId} onClick={() => run(() => parityService.erpSubscriptionRenew(subId, { period: subPeriod }))}>{t("parity.renewErp")}</Button></div></div>
            <p className="text-xs text-muted-foreground">{t("parity.erpDesc")}</p>
          </CardContent></Card>
      </div>
    </div>
  );
}
