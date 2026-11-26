"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { CapabilityGate } from "@/components/capability-gate";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { EmptyState } from "@/components/empty-state";
import { PageHeader } from "@/components/page-header";
import { ApiState } from "@/modules/merchant/api-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { launchService, type LaunchCheckState, type LaunchStatus, type ProviderStatus } from "@/services/launch.service";

const STATE_COPY: Record<LaunchCheckState, { label: string; variant: "success" | "warning" | "destructive" }> = {
  ok: { label: "OK", variant: "success" },
  warn: { label: "Warn", variant: "warning" },
  fail: { label: "Fail", variant: "destructive" },
};

function internalHref(href: string): string {
  return href.startsWith("/") && !href.startsWith("//") ? href : "/";
}

function LaunchContent() {
  const [status, setStatus] = useState<LaunchStatus | null>(null);
  const [providers, setProviders] = useState<ProviderStatus | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [notice, setNotice] = useState("");

  const load = () => {
    setState("loading");
    setError(undefined);
    void Promise.all([
      launchService.status(),
      launchService.providers().catch(() => null),
    ])
      .then(([nextStatus, nextProviders]) => {
        setStatus(nextStatus);
        setProviders(nextProviders);
        setState("ready");
      })
      .catch((cause) => {
        setError(cause);
        setState("error");
      });
  };

  useEffect(load, []);

  const seed = async () => {
    if (busy) return;
    setBusy(true);
    setError(undefined);
    setNotice("");
    try {
      const result = await launchService.seedDemo();
      const created = Object.values(result.created).reduce((total, count) => total + count, 0);
      setNotice(created > 0 ? `Created ${created} launch demo records.` : "Demo data already present; nothing was created.");
      load();
    } catch (cause) {
      setError(cause);
    } finally {
      setBusy(false);
    }
  };

  const catalog = status?.checks.find((check) => check.key === "catalog");
  const canSeed = catalog !== undefined && catalog.state !== "ok";

  return (
    <div className="space-y-5">
      <PageHeader
        title="Launch checklist"
        description="Everything a fresh store needs before it can take orders. Each check is read-only and never exposes provider secrets."
      >
        <Button variant="outline" onClick={load} disabled={state === "loading" || busy}>Re-run checks</Button>
        {canSeed && <Button onClick={() => setConfirmOpen(true)} disabled={busy}>{busy ? "Seeding…" : "Seed demo data"}</Button>}
      </PageHeader>

      {notice && <p role="status" className="rounded-md border border-primary/30 bg-primary/5 p-3 text-sm">{notice}</p>}
      {error !== undefined && <ApiState error={error} onRetry={load} />}

      {state === "loading" && status === null && (
        <div className="grid gap-4 md:grid-cols-3">
          {Array.from({ length: 6 }).map((_, index) => <Skeleton key={index} className="h-32 w-full" />)}
        </div>
      )}

      {status !== null && (
        <>
          <Card>
            <CardContent className="flex flex-col gap-6 py-6 sm:flex-row sm:items-center">
              <div
                role="progressbar"
                aria-label="Launch readiness score"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={status.score}
                className="relative flex h-28 w-28 shrink-0 items-center justify-center rounded-full"
                style={{ background: `conic-gradient(hsl(var(--primary)) ${status.score * 3.6}deg, hsl(var(--muted)) 0deg)` }}
              >
                <div className="absolute inset-2 flex flex-col items-center justify-center rounded-full bg-background">
                  <span className="text-2xl font-bold">{status.score}</span>
                  <span className="text-xs text-muted-foreground">/ 100</span>
                </div>
              </div>
              <div className="space-y-1">
                <h2 className="text-lg font-semibold">{status.ready ? "Ready to launch" : "Action needed"}</h2>
                <p className="text-sm text-muted-foreground">
                  {status.ready
                    ? "Every launch check is green. Your storefront is ready for orders."
                    : "Resolve the failing checks first; warnings are recommended before going live."}
                </p>
                <p className="text-xs text-muted-foreground">Score weighs OK as 1, warning as 0.5 and failure as 0.</p>
              </div>
            </CardContent>
          </Card>

          {providers !== null && (
            <Card>
              <CardHeader><CardTitle>Providers</CardTitle></CardHeader>
              <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <ProviderItem label="Payment" value={`${providers.payment.provider} · ${providers.payment.mode}`} configured={providers.payment.configured} />
                <ProviderItem label="Shipping" value={`${providers.shipping.provider} · ${providers.shipping.mode}`} configured={providers.shipping.configured} />
                <ProviderItem label="Mail" value={providers.mail.provider} configured={providers.mail.configured} />
                <ProviderItem label="Search" value={providers.search.provider} configured={providers.search.configured} />
                <ProviderItem label="AI" value={providers.ai.provider} configured={providers.ai.configured} />
              </CardContent>
            </Card>
          )}

          {status.checks.length === 0 ? (
            <EmptyState title="No launch checks" description="The server returned an empty checklist." action={<Button variant="outline" onClick={load}>Re-run checks</Button>} />
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {status.checks.map((check) => {
                const copy = STATE_COPY[check.state] ?? STATE_COPY.fail;
                return (
                  <Card key={check.key}>
                    <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
                      <CardTitle className="capitalize">{check.key.replaceAll("_", " ")}</CardTitle>
                      <Badge variant={copy.variant}>{copy.label}</Badge>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <p className="text-sm text-muted-foreground">{check.hint}</p>
                      {check.detail && <p className="text-xs text-muted-foreground">{check.detail}</p>}
                      <Link href={internalHref(check.href)} className="text-sm font-medium text-primary underline-offset-4 hover:underline">
                        Open {check.key.replaceAll("_", " ")}
                      </Link>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          )}
        </>
      )}

      <ConfirmDialog
        open={confirmOpen}
        title="Seed demo data"
        description="Create 3 categories, 6 products with variants, inventory and prices, 3 published legal pages, a general theme preset and a primary menu. Existing data is never overwritten."
        confirmLabel="Seed demo data"
        isPending={busy}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={() => {
          setConfirmOpen(false);
          void seed();
        }}
      />
    </div>
  );
}

function ProviderItem({ label, value, configured }: { label: string; value: string; configured: boolean }) {
  return (
    <div className="rounded-md border p-3">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="mt-1 truncate text-sm font-medium capitalize">{value}</p>
      <Badge variant={configured ? "success" : "warning"} className="mt-2">{configured ? "Configured" : "Missing"}</Badge>
    </div>
  );
}

export default function LaunchPage() {
  return <CapabilityGate capability="products"><LaunchContent /></CapabilityGate>;
}
