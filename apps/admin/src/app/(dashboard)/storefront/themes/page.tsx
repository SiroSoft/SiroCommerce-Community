"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { ExternalLink, Paintbrush, RefreshCw } from "lucide-react";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { ErrorState } from "@/components/error-state";
import { EmptyState } from "@/components/empty-state";
import { SearchInput } from "@/components/search-input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  storefrontThemeService,
  themeCatalogAvailability,
  type EcosystemCatalogItem,
  type ThemeCatalogEdition,
} from "@/services/storefront-theme.service";

type Tab = "catalog" | "updates";
type LoadState = "idle" | "loading" | "ready" | "error";

const TABS: Array<{ id: Tab; label: string }> = [
  { id: "catalog", label: "Catalog" },
  { id: "updates", label: "Updates" },
];

const EDITIONS: Array<{ id: ThemeCatalogEdition; label: string }> = [
  { id: "all", label: "All" },
  { id: "free", label: "Free" },
  { id: "pro", label: "Pro" },
];

const LICENSE_LABELS: Record<EcosystemCatalogItem["license_status"], string> = {
  not_required: "License not required",
  valid: "License valid",
  missing: "License missing",
  expired: "License expired",
  grace: "License in grace",
};

const STATUS_LABELS: Record<string, string> = {
  published: "Published",
  planned: "Planned",
  deprecated: "Deprecated",
};

function safeUrl(value: string | null | undefined): string | null {
  if (typeof value !== "string") return null;
  const candidate = value.trim();
  if (!candidate || /[\u0000-\u001f]/.test(candidate)) return null;
  if (candidate.startsWith("/") && !candidate.startsWith("//") && !candidate.startsWith("/\\")) return candidate;
  try {
    const url = new URL(candidate);
    if (url.protocol !== "https:" || url.username || url.password) return null;
    return url.toString();
  } catch {
    return null;
  }
}

function licenseVariant(status: EcosystemCatalogItem["license_status"]): "success" | "warning" | "destructive" | "outline" {
  if (status === "valid" || status === "not_required") return "success";
  if (status === "grace") return "warning";
  if (status === "missing" || status === "expired") return "destructive";
  return "outline";
}

function ThemeCard({ item }: { item: EcosystemCatalogItem }) {
  const preview = safeUrl(item.preview_url);
  const changelog = safeUrl(item.changelog_url);
  const availability = themeCatalogAvailability(item);
  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <CardTitle className="text-base">{item.name}</CardTitle>
            <CardDescription>{item.vendor} - {item.id}</CardDescription>
          </div>
          <div className="flex flex-wrap justify-end gap-1">
            <Badge variant={item.edition === "pro" ? "default" : "secondary"}>{item.edition === "pro" ? "Pro" : "Free"}</Badge>
            <Badge variant="outline">{STATUS_LABELS[item.status] ?? item.status}</Badge>
            {item.update_available && <Badge variant="warning">Update available</Badge>}
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        {item.description && <p className="text-sm text-muted-foreground">{item.description}</p>}
        <div className="flex flex-wrap gap-2">
          <Badge variant="outline">Latest v{item.latest_version}</Badge>
          {item.installed_version && <Badge variant="outline">Installed v{item.installed_version}</Badge>}
          <Badge variant={item.compatible ? "success" : "destructive"}>{item.compatible ? "Compatible" : "Incompatible"}</Badge>
          <Badge variant={licenseVariant(item.license_status)}>{LICENSE_LABELS[item.license_status] ?? `License ${item.license_status}`}</Badge>
        </div>
        <p className="text-xs text-muted-foreground">
          Core {item.min_core ?? "any"} - Commerce {item.min_commerce ?? "any"} - Installed state: {item.installed_state}
        </p>
        {item.changelog && <p className="whitespace-pre-line text-xs text-muted-foreground">{item.changelog}</p>}
        {item.tags?.length > 0 && (
          <div className="flex flex-wrap gap-1">
            {item.tags.map((tag) => <Badge key={tag} variant="outline" className="font-normal">{tag}</Badge>)}
          </div>
        )}
        <div className="flex flex-wrap items-center gap-4 text-sm">
          {preview && (
            <a href={preview} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline">
              Preview <ExternalLink className="h-3 w-3" aria-hidden="true" />
            </a>
          )}
          {changelog && (
            <a href={changelog} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline">
              Changelog <ExternalLink className="h-3 w-3" aria-hidden="true" />
            </a>
          )}
          {availability.openable ? (
            <Link href="/storefront/theme" className="inline-flex items-center gap-1 text-primary hover:underline">
              <Paintbrush className="h-3 w-3" aria-hidden="true" />Open Theme builder
            </Link>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              <Button type="button" size="sm" variant="outline" disabled>Import</Button>
              <span role="note" className="text-xs text-muted-foreground">{availability.reason}</span>
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function LoadingState({ label }: { label: string }) {
  return (
    <div role="status" className="space-y-3">
      <span className="sr-only">{label}</span>
      <Skeleton className="h-28 w-full" />
      <Skeleton className="h-28 w-full" />
    </div>
  );
}

function CatalogPanel({ active }: { active: boolean }) {
  const [edition, setEdition] = useState<ThemeCatalogEdition>("all");
  const [query, setQuery] = useState("");
  const [items, setItems] = useState<EcosystemCatalogItem[]>([]);
  const [state, setState] = useState<LoadState>("idle");
  const [error, setError] = useState<unknown>();
  const [reload, setReload] = useState(0);

  useEffect(() => {
    if (!active) return;
    let cancelled = false;
    setState("loading");
    setError(undefined);
    void storefrontThemeService.catalog({ edition, q: query.trim() || undefined })
      .then((response) => {
        if (cancelled) return;
        setItems(Array.isArray(response.items) ? response.items : []);
        setState("ready");
      })
      .catch((cause) => {
        if (cancelled) return;
        setError(cause);
        setState("error");
      });
    return () => { cancelled = true; };
  }, [active, edition, query, reload]);

  return (
    <section role="tabpanel" id="theme-panel-catalog" aria-labelledby="theme-tab-catalog" hidden={!active} className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div role="group" aria-label="Edition filter" className="flex gap-2">
          {EDITIONS.map((option) => (
            <Button
              key={option.id}
              type="button"
              size="sm"
              variant={edition === option.id ? "default" : "outline"}
              aria-pressed={edition === option.id}
              onClick={() => setEdition(option.id)}
            >
              {option.label}
            </Button>
          ))}
        </div>
        <div className="w-full sm:max-w-xs">
          <SearchInput value={query} onChange={setQuery} placeholder="Search themes" />
        </div>
      </div>
      {state === "loading" && <LoadingState label="Loading theme catalog..." />}
      {state === "error" && (
        <ErrorState
          title="Unable to load theme catalog"
          message={error instanceof Error ? error.message : "The catalog request failed."}
          onRetry={() => setReload((value) => value + 1)}
        />
      )}
      {state === "ready" && items.length === 0 && (
        <EmptyState title="No theme packages" description="No themes match this edition or search." />
      )}
      {state === "ready" && items.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          {items.map((item) => <ThemeCard key={item.id} item={item} />)}
        </div>
      )}
    </section>
  );
}

function UpdatesPanel({ active }: { active: boolean }) {
  const [items, setItems] = useState<EcosystemCatalogItem[]>([]);
  const [state, setState] = useState<LoadState>("idle");
  const [error, setError] = useState<unknown>();
  const [reload, setReload] = useState(0);

  useEffect(() => {
    if (!active) return;
    let cancelled = false;
    setState("loading");
    setError(undefined);
    void storefrontThemeService.updates()
      .then((response) => {
        if (cancelled) return;
        const themes = (Array.isArray(response.items) ? response.items : [])
          .filter((item) => item.type === "theme" && item.update_available);
        setItems(themes);
        setState("ready");
      })
      .catch((cause) => {
        if (cancelled) return;
        setError(cause);
        setState("error");
      });
    return () => { cancelled = true; };
  }, [active, reload]);

  return (
    <section role="tabpanel" id="theme-panel-updates" aria-labelledby="theme-tab-updates" hidden={!active} className="space-y-4">
      {state === "loading" && <LoadingState label="Loading theme updates..." />}
      {state === "error" && (
        <ErrorState
          title="Unable to load theme updates"
          message={error instanceof Error ? error.message : "The updates request failed."}
          onRetry={() => setReload((value) => value + 1)}
        />
      )}
      {state === "ready" && items.length === 0 && (
        <EmptyState title="No theme updates" description="Installed themes are up to date." />
      )}
      {state === "ready" && items.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          {items.map((item) => <ThemeCard key={item.id} item={item} />)}
        </div>
      )}
    </section>
  );
}

function ThemeCatalogContent() {
  const [tab, setTab] = useState<Tab>("catalog");
  const tabRefs = useRef<Record<Tab, HTMLButtonElement | null>>({ catalog: null, updates: null });

  const onTabKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
    event.preventDefault();
    const index = TABS.findIndex((item) => item.id === tab);
    const nextIndex = event.key === "Home" ? 0
      : event.key === "End" ? TABS.length - 1
      : event.key === "ArrowLeft" ? (index - 1 + TABS.length) % TABS.length
      : (index + 1) % TABS.length;
    const next = TABS[nextIndex];
    setTab(next.id);
    tabRefs.current[next.id]?.focus();
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Theme catalog" description="Free and Pro theme packages, compatibility, licenses, and updates." />
      <div role="tablist" aria-label="Theme catalog sections" className="flex max-w-full gap-2 overflow-x-auto border-b pb-2" onKeyDown={onTabKeyDown}>
        {TABS.map((item) => (
          <button
            key={item.id}
            ref={(element) => { tabRefs.current[item.id] = element; }}
            type="button"
            role="tab"
            id={`theme-tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`theme-panel-${item.id}`}
            tabIndex={tab === item.id ? 0 : -1}
            onClick={() => setTab(item.id)}
            className={`rounded-md px-3 py-2 text-sm font-medium ${tab === item.id ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}
          >
            {item.label}
          </button>
        ))}
      </div>
      <CatalogPanel active={tab === "catalog"} />
      <UpdatesPanel active={tab === "updates"} />
      <p className="flex items-center gap-2 text-xs text-muted-foreground">
        <RefreshCw className="h-3 w-3" aria-hidden="true" />
        Catalog data is read-only. Theme imports stay drafts until reviewed in Theme builder.
      </p>
    </div>
  );
}

export default function ThemeCatalogPage() {
  return <CapabilityGate capability="cms"><ThemeCatalogContent /></CapabilityGate>;
}
