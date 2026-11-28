"use client";

import { use, useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { ApiState } from "@/modules/merchant/api-state";
import { Badge } from "@/components/ui/badge";
import { ExtensionPanelCard } from "@/components/extension-panel";
import { StaffAssistantPanel } from "@/components/staff-assistant-panel";
import { extensionsService, type ExtensionView } from "@/services/extensions.service";

export default function ExtensionDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const extensionId = decodeURIComponent(id);
  const [view, setView] = useState<ExtensionView | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error" | "missing">("loading");
  const [error, setError] = useState<unknown>();

  const load = () => {
    setState("loading"); setError(undefined);
    void extensionsService.views()
      .then((views) => {
        const found = views.find((item) => item.id === extensionId) ?? null;
        setView(found);
        setState(found ? "ready" : "missing");
      })
      .catch((cause) => { setError(cause); setState("error"); });
  };
  useEffect(load, [extensionId]);

  if (state === "loading") return <p role="status">Loading extension…</p>;
  if (state === "error") return <ApiState error={error} onRetry={load} />;
  if (state === "missing" || !view) return (
    <div className="space-y-4">
      <PageHeader title={extensionId} description="This extension has no admin views (disabled or no admin block)." />
      <p className="text-sm text-muted-foreground">Enable the extension and give its manifest an admin block to see panels here.</p>
    </div>
  );
  return (
    <div className="space-y-5">
      <PageHeader title={view.label} description={`${view.id} · ${view.section}`} />
      <p><Badge>enabled</Badge></p>
      {view.panels.length === 0 && <p className="text-sm text-muted-foreground">No panels declared.</p>}
      {view.panels.map((panel) => panel.type === "assistant"
        ? <StaffAssistantPanel key={panel.key} extensionId={view.id} panel={panel} />
        : <ExtensionPanelCard key={panel.key} extensionId={view.id} panel={panel} />)}
    </div>
  );
}
