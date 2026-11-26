"use client";

import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/page-header";
import api from "@/services/api";

type CopilotRow = {
  id: string;
  task: string;
  product_id?: string | null;
  state: string;
  suggestion: Record<string, unknown> | null;
  provider?: string | null;
  model?: string | null;
  reason?: string | null;
  reviewed_by?: string | null;
  created_at?: string | null;
};

const TASKS = [
  { value: "product_description", label: "Product description" },
  { value: "review_draft", label: "Review draft" },
  { value: "catalog_enrichment", label: "Catalog enrichment" },
];

export default function AiCopilotPage() {
  const [rows, setRows] = useState<CopilotRow[]>([]);
  const [state, setState] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const [task, setTask] = useState(TASKS[0].value);
  const [productId, setProductId] = useState("");
  const [topic, setTopic] = useState("");
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{ id: string; action: "approve" | "reject" } | null>(null);

  const load = () => {
    setState("loading");
    api.get<{ data: CopilotRow[] }>("/api/v2/admin/ai/copilot/queue")
      .then((res) => {
        setRows(Array.isArray(res.data.data) ? res.data.data : []);
        setState("ready");
      })
      .catch(() => setState("error"));
  };

  const generate = () => {
    if (busy) return;
    setBusy(true);
    setNotice(null);
    api.post("/api/v2/admin/ai/copilot/tasks", {
      task,
      input: topic.trim() !== "" ? { topic: topic.trim() } : { product_id: productId.trim() },
      ...(productId.trim() !== "" ? { product_id: productId.trim() } : {}),
      max_cost_minor: 50,
    })
      .then((res) => {
        const row = (res.data as { data?: CopilotRow } | undefined)?.data;
        setNotice(row?.state === "failed" ? `Not generated: ${row.reason ?? "provider unavailable"}` : "Draft queued. Review it below.");
        load();
      })
      .catch(() => setNotice("Generation request failed. Check capabilities and provider settings."))
      .finally(() => setBusy(false));
  };

  const decide = (id: string, action: "approve" | "reject") => {
    setConfirmation({ id, action });
  };

  const applyDecision = (id: string, action: "approve" | "reject") => {
    api.post(`/api/v2/admin/ai/copilot/queue/${encodeURIComponent(id)}/${action}`, {})
      .then(() => load())
      .catch(() => setNotice("Review action failed."));
  };

  return (
    <div className="space-y-5">
      <PageHeader
        title="AI copilot queue"
        description="Generate product copy, review drafts and catalog enrichment. Nothing is applied until you approve it."
      />
      {notice && <p role="status" className="rounded-md border bg-muted px-3 py-2 text-sm">{notice}</p>}

      <Card>
        <CardHeader>
          <CardTitle>Generate a draft</CardTitle>
          <CardDescription>Runs through the provider configured in AI_PROVIDER; usage is billed against the tenant budget.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex flex-wrap gap-3">
            <select
              aria-label="Copilot task"
              className="h-10 rounded-md border bg-background px-3 text-sm"
              value={task}
              onChange={(event) => setTask(event.target.value)}
            >
              {TASKS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </select>
            <Input className="max-w-xs" aria-label="Product ID" placeholder="Product ID (optional)" value={productId} onChange={(event) => setProductId(event.target.value)} />
            <Input className="max-w-sm" aria-label="Topic or brief" placeholder="Topic or brief for the draft" value={topic} onChange={(event) => setTopic(event.target.value)} />
            <Button onClick={generate} disabled={busy}>{busy ? "Generating..." : "Generate draft"}</Button>
            <Button variant="outline" onClick={load}>Refresh queue</Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Review queue ({rows.length})</CardTitle>
          <CardDescription>Approve to mark a draft ready for publishing; reject to discard it. Both are one-shot.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          {state === "idle" ? <p className="text-sm text-muted-foreground">Refresh to load the queue.</p>
            : state === "loading" ? <p role="status">Loading queue...</p>
            : state === "error" ? <p role="alert" className="text-sm text-muted-foreground">Unavailable. <Button size="sm" variant="outline" onClick={load}>Retry</Button></p>
            : rows.length === 0 ? <p className="text-sm text-muted-foreground">No drafts yet.</p>
            : <div className="space-y-2">{rows.map((row) => <div key={row.id} className="space-y-1 rounded-md border p-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex flex-wrap items-center gap-2">
                  <Badge variant={row.state === "pending" ? "secondary" : row.state === "failed" ? "destructive" : "outline"}>{row.state}</Badge>
                  <strong>{row.task.replace(/_/g, " ")}</strong>
                  {row.product_id ? <span className="text-muted-foreground">· {row.product_id}</span> : null}
                  {row.provider ? <span className="text-muted-foreground">· {row.provider}{row.model ? `/${row.model}` : ""}</span> : null}
                </span>
                {row.state === "pending" ? <span className="flex gap-2">
                  <Button size="sm" onClick={() => decide(row.id, "approve")}>Approve</Button>
                  <Button size="sm" variant="outline" onClick={() => decide(row.id, "reject")}>Reject</Button>
                </span> : null}
              </div>
              {row.suggestion ? <pre className="overflow-x-auto rounded bg-muted p-2 text-xs">{JSON.stringify(row.suggestion, null, 2)}</pre> : null}
              {row.reason ? <p className="text-xs text-destructive">{row.reason}</p> : null}
            </div>)}</div>}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={confirmation !== null}
        title={confirmation?.action === "reject" ? "Reject draft" : "Approve draft"}
        description={confirmation ? `${confirmation.action === "approve" ? "Approve" : "Reject"} this draft? This review decision is one-shot and cannot be undone.` : undefined}
        confirmLabel={confirmation?.action === "reject" ? "Reject" : "Approve"}
        destructive={confirmation?.action === "reject"}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => {
          const action = confirmation;
          setConfirmation(null);
          if (action) applyDecision(action.id, action.action);
        }}
      />
    </div>
  );
}
