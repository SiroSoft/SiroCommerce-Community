"use client";

import Link from "next/link";
import { useState } from "react";
import { RoleGate } from "@/components/capability-gate";
import { ApiState } from "@/modules/merchant/api-state";
import { useActionCenter } from "@/hooks/use-merchant";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import api from "@/services/api";

function ActionCenterContent() {
  const { data, isLoading, isError, error, refetch } = useActionCenter();
  const actions = Array.isArray(data) ? data : (data as { actions?: unknown[] } | undefined)?.actions ?? [];
  const [command, setCommand] = useState("");
  const [search, setSearch] = useState("");
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [executeResult, setExecuteResult] = useState<unknown>(null);
  const [searchResult, setSearchResult] = useState<unknown>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);

  const rescue = async (id: string) => {
    if (busy || !id) return;
    setConfirmation({
      title: "Run rescue",
      description: `Run rescue for ${id}? This performs a backend recovery action.`,
      run: () => void runRescue(id),
    });
  };

  const runRescue = async (id: string) => {
    setBusy(true);
    setNotice(null);
    try {
      const res = await api.post<{ data: unknown }>(`/api/admin/smart/rescue/${encodeURIComponent(id)}`);
      setNotice(`Rescue accepted for ${id}.`);
      setExecuteResult(res.data.data);
      refetch();
    } catch (cause) {
      setNotice(cause instanceof Error ? cause.message : "Rescue was not accepted.");
    } finally {
      setBusy(false);
    }
  };

  const execute = () => {
    if (busy || !command.trim()) return;
    const trimmed = command.trim();
    setConfirmation({
      title: "Execute command",
      description: `Execute command "${trimmed}"? The command runs immediately against the backend.`,
      run: () => void runExecute(trimmed),
    });
  };

  const runExecute = async (trimmed: string) => {
    setBusy(true);
    setNotice(null);
    try {
      const res = await api.post<{ data: unknown }>("/api/admin/smart/execute", { command: trimmed });
      setExecuteResult(res.data.data);
      setNotice("Command executed.");
      refetch();
    } catch (cause) {
      setNotice(cause instanceof Error ? cause.message : "Command was not accepted.");
    } finally {
      setBusy(false);
    }
  };

  const runSearch = async () => {
    if (busy) return;
    setBusy(true);
    setNotice(null);
    try {
      const res = await api.get<{ data: unknown }>("/api/admin/smart/search", { params: { q: search } });
      setSearchResult(res.data.data);
    } catch (cause) {
      setNotice(cause instanceof Error ? cause.message : "Search failed.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Action Center" description="Review operational actions requiring attention." />
      {notice && (
        <p role="status" className="rounded-md border bg-muted px-3 py-2 text-sm">
          {notice}
        </p>
      )}
      {isLoading ? (
        <Card>
          <CardContent className="space-y-3 p-6">
            <Skeleton className="h-5 w-48" />
            <Skeleton className="h-4 w-full" />
            <p role="status" className="sr-only">
              Loading actions...
            </p>
          </CardContent>
        </Card>
      ) : isError ? (
        <ApiState error={error} onRetry={() => refetch()} />
      ) : actions.length === 0 ? (
        <Card>
          <CardContent className="p-6 text-sm text-muted-foreground">No actions require attention.</CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="divide-y p-0">
            {actions.map((action, index) => {
              const item = typeof action === "object" && action !== null ? (action as Record<string, unknown>) : {};
              const id = typeof item.id === "string" ? item.id : null;
              return (
                <div className="flex flex-wrap items-center justify-between gap-3 p-4" key={String(item.id ?? index)}>
                  <div>
                    <p className="font-medium">{String(item.title ?? item.name ?? item.type ?? "Action")}</p>
                    <p className="text-sm text-muted-foreground">{String(item.description ?? item.message ?? "Review this operational action.")}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {id && (
                      <Button size="sm" variant="outline" disabled={busy} onClick={() => rescue(id)}>
                        Run rescue
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" asChild>
                      <Link href="/orders">Open orders</Link>
                    </Button>
                  </div>
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Execute command</CardTitle>
            <CardDescription>POST /api/admin/smart/execute with confirmation.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input aria-label="Smart command" value={command} onChange={(e) => setCommand(e.target.value)} placeholder="e.g. reconcile-payments" />
            <Button disabled={busy || !command.trim()} onClick={execute}>
              {busy ? "Working..." : "Execute"}
            </Button>
            {executeResult ? (
              <pre className="max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(executeResult, null, 2)}</pre>
            ) : null}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Search operations</CardTitle>
            <CardDescription>GET /api/admin/smart/search.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <Input aria-label="Search query" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search orders, customers..." />
            <Button variant="outline" disabled={busy} onClick={runSearch}>
              Search
            </Button>
            {searchResult ? (
              <pre className="max-h-48 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(searchResult, null, 2)}</pre>
            ) : null}
          </CardContent>
        </Card>
      </div>
      <Link className="text-sm text-primary underline-offset-4 hover:underline" href="/orders">
        Open orders
      </Link>
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
    </div>
  );
}

export default function ActionCenterPage() {
  return (
    <RoleGate roles={["owner", "admin"]}>
      <ActionCenterContent />
    </RoleGate>
  );
}
