"use client";

import { useEffect, useState } from "react";
import { ApiState } from "@/modules/merchant/api-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { extensionsService, type ExtensionPanel } from "@/services/extensions.service";

type PanelData = {
  stats?: Array<{ label?: unknown; value?: unknown }>;
  table?: { columns?: Array<{ key?: unknown; label?: unknown }>; rows?: Array<Record<string, unknown>> };
};

function text(value: unknown): string {
  return typeof value === "string" || typeof value === "number" ? String(value) : "";
}

export function ExtensionPanelCard({ extensionId, panel }: { extensionId: string; panel: ExtensionPanel }) {
  const [data, setData] = useState<PanelData | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();

  const load = () => {
    setState("loading"); setError(undefined);
    void extensionsService.panel(extensionId, panel.endpoint)
      .then((value) => { setData(value as PanelData); setState("ready"); })
      .catch((cause) => { setError(cause); setState("error"); });
  };
  useEffect(load, [extensionId, panel.endpoint]);

  const columns = (() => {
    const declared = data?.table?.columns?.filter((c): c is { key: string; label: string } => typeof c?.key === "string" && typeof c?.label === "string");
    if (declared && declared.length > 0) return declared;
    const first = data?.table?.rows?.[0];
    if (!first) return [];
    return Object.keys(first).slice(0, 8).map((key) => ({ key, label: key }));
  })();
  const rows = (data?.table?.rows ?? []).slice(0, 50);

  return (
    <Card>
      <CardHeader><CardTitle>{panel.title}</CardTitle></CardHeader>
      <CardContent>
        {state === "loading" && <p role="status" className="text-sm text-muted-foreground">Loading…</p>}
        {state === "error" && <ApiState error={error} onRetry={load} />}
        {state === "ready" && panel.type === "stats" && (
          <dl className="grid gap-3 sm:grid-cols-3">
            {(data?.stats ?? []).map((stat, index) => (
              <div key={index} className="rounded-md border px-3 py-2">
                <dt className="text-xs text-muted-foreground">{text(stat.label)}</dt>
                <dd className="font-medium">{text(stat.value)}</dd>
              </div>
            ))}
          </dl>
        )}
        {state === "ready" && panel.type === "table" && (
          rows.length === 0 ? <p className="text-sm text-muted-foreground">No rows.</p> : (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead><tr>{columns.map((col) => <th key={col.key} className="border-b px-3 py-2 text-left font-medium">{col.label}</th>)}</tr></thead>
                <tbody>{rows.map((row, index) => <tr key={index} className="border-b last:border-0">{columns.map((col) => <td key={col.key} className="px-3 py-2">{text(row[col.key])}</td>)}</tr>)}</tbody>
              </table>
            </div>
          )
        )}
      </CardContent>
    </Card>
  );
}
