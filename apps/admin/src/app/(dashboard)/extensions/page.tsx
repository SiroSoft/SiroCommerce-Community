"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { RoleGate } from "@/components/capability-gate";
import { ApiState } from "@/modules/merchant/api-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { toAdminApiError } from "@/services/api-errors";
import { extensionsService, type CatalogItem, type ExtensionItem, type ExtensionLicenseSource, type ExtensionLicenseStatus, type ExtensionPackageReport, type ExtensionQualityReport, type ProFeed, type ProFeedItem } from "@/services/extensions.service";

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : "The request was not accepted.";
}

function licenseImportError(error: unknown): string {
  const normalized = toAdminApiError(error);
  if (normalized.kind === "validation") {
    const details = normalized.errors
      ? Object.values(normalized.errors).flat().map((item) => item.trim()).filter(Boolean)
      : [];
    return `The license was not accepted. Check that the file is a valid signed license document.${details.length > 0 ? ` ${details.join(" ")}` : ""}`;
  }
  return normalized.message || "The license was not accepted.";
}

const LICENSE_SOURCE_LABEL: Record<ExtensionLicenseSource, string> = {
  env: "environment",
  storage: "stored file",
  none: "none",
};

const STATE_VARIANT: Record<string, "default" | "outline" | "destructive"> = {
  enabled: "default",
  failed: "destructive",
};

type ExtensionTab = "installed" | "catalog" | "updates" | "licenses";
const TABS: { id: ExtensionTab; label: string }[] = [
  { id: "installed", label: "Installed" },
  { id: "catalog", label: "Catalog" },
  { id: "updates", label: "Updates" },
  { id: "licenses", label: "Licenses" },
];

const EDITION_VARIANT: Record<string, "success" | "warning" | "outline"> = { free: "success", pro: "warning" };
const LICENSE_LABEL: Record<string, string> = {
  not_required: "License not required",
  valid: "License valid",
  missing: "License missing",
  expired: "License expired",
  grace: "License in grace",
};
const LICENSE_VARIANT: Record<string, "success" | "warning" | "destructive" | "outline"> = {
  valid: "success",
  not_required: "outline",
  grace: "warning",
  expired: "destructive",
  missing: "destructive",
};

function safeCatalogHref(url: string | null): string | null {
  if (!url) return null;
  if (url.startsWith("/") && !url.startsWith("//")) return url;
  if (/^https:\/\//i.test(url)) return url;
  return null;
}

function licenseLabel(status: string | null): string {
  if (!status) return "License unknown";
  return LICENSE_LABEL[status] ?? status;
}

function licenseAllowsItem(item: Pick<CatalogItem, "license_required" | "license_status">): boolean {
  if (!item.license_required) return true;
  return item.license_status === "valid" || item.license_status === "grace";
}

function ProFeedRow({ item, busy, onInstall, onUpdate }: { item: ProFeedItem; busy: boolean; onInstall: (id: string, version?: string) => void; onUpdate: (id: string, version?: string) => void }) {
  const artifact = item.artifact;
  const canInstall = item.installable === true;
  const canUpdate = item.can_update === true;
  const reason = item.action_reason ?? (artifact?.available === false ? artifact.reason ?? "Signed Pro artifact is not available" : null);
  return <div className="space-y-2 rounded-md border p-3 text-sm">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <p className="font-medium">{item.name ?? item.id}</p>
        <p className="text-xs text-muted-foreground">{item.id} · v{item.latest_version ?? "—"} · {item.entitlement === "grace" ? "licence in grace" : item.entitlement === "valid" ? "entitled" : "not entitled"}</p>
      </div>
      <Badge variant={artifact?.available ? "success" : "outline"}>{artifact?.available ? "Artifact ready" : "No artifact"}</Badge>
    </div>
    {artifact?.available ? <p className="text-xs text-muted-foreground">{artifact.filename} · {artifact.signed ? "signed" : "unsigned"} · {artifact.size != null ? `${artifact.size} bytes` : "size unknown"}</p> : null}
    {reason && <p role="alert" className="rounded-md border border-amber-500/30 bg-amber-500/5 p-2 text-xs text-amber-900 dark:text-amber-200">{reason}</p>}
    {(canInstall || canUpdate) && <div className="flex flex-wrap gap-2">
      {canInstall && <Button size="sm" disabled={busy} onClick={() => onInstall(item.id, item.latest_version)}>Install Pro artifact</Button>}
      {canUpdate && <Button size="sm" variant="outline" disabled={busy} onClick={() => onUpdate(item.id, item.latest_version)}>Update Pro artifact</Button>}
    </div>}
  </div>;
}

function CatalogBadges({ item }: { item: CatalogItem }) {
  return (
    <div className="flex flex-wrap items-center gap-1">
      <Badge variant={EDITION_VARIANT[item.edition] ?? "outline"}>{item.edition === "pro" ? "Pro" : "Free"}</Badge>
      <Badge variant="outline">{item.status}</Badge>
      <Badge variant={item.compatible ? "outline" : "destructive"}>{item.compatible ? "Compatible" : "Incompatible"}</Badge>
      {item.update_available && <Badge variant="warning">Update available</Badge>}
      <Badge variant={LICENSE_VARIANT[item.license_status] ?? "outline"}>{licenseLabel(item.license_status)}</Badge>
    </div>
  );
}

function actionReasonFor(item: CatalogItem, canInstall: boolean, canUpdate: boolean): string | null {
  if (canInstall || canUpdate) return null;
  if (item.action_reason) return item.action_reason;
  if (item.edition === "pro" && item.license_required && (item.license_status === "missing" || item.license_status === "expired")) {
    return `Pro license required. This item cannot be used until a valid ${item.license_sku ?? "Pro"} license is activated.`;
  }
  return null;
}

function CatalogCard({ item, busy, onInstall, onUpdate }: { item: CatalogItem; busy: boolean; onInstall: (id: string) => void; onUpdate: (id: string) => void }) {
  const preview = safeCatalogHref(item.preview_url);
  const changelogLink = safeCatalogHref(item.changelog_url);
  const canInstall = item.installable && licenseAllowsItem(item);
  const canUpdate = item.can_update && licenseAllowsItem(item);
  const reason = actionReasonFor(item, canInstall, canUpdate);
  return <Card><CardContent className="space-y-2 p-4">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-52 flex-1">
        <p className="font-medium">{item.name}</p>
        <p className="text-xs text-muted-foreground">{item.id} · {item.vendor} · {item.type}</p>
        {item.description && <p className="mt-1 text-sm text-muted-foreground">{item.description}</p>}
      </div>
      <CatalogBadges item={item} />
    </div>
    <p className="text-xs text-muted-foreground">
      latest v{item.latest_version} · installed {item.installed_version ? `v${item.installed_version}` : "not installed"} · state: {item.installed_state}
    </p>
    <p className="text-xs text-muted-foreground">
      {licenseLabel(item.license_status)}{item.license_sku ? ` · SKU ${item.license_sku}` : ""}
    </p>
    {!item.compatible && <p className="text-xs text-destructive">Requires core {item.min_core ?? "—"} · commerce {item.min_commerce ?? "—"}.</p>}
    {item.changelog && <p className="whitespace-pre-line text-xs text-muted-foreground">{item.changelog}</p>}
    {reason && <p role="alert" className="rounded-md border border-amber-500/30 bg-amber-500/5 p-2 text-xs text-amber-900 dark:text-amber-200">{reason}</p>}
    {item.tags.length > 0 && <p className="text-xs text-muted-foreground">Tags: {item.tags.join(", ")}</p>}
    {(canInstall || canUpdate) && <div className="flex flex-wrap gap-2">
      {canInstall && <Button size="sm" disabled={busy} onClick={() => onInstall(item.id)}>Install</Button>}
      {canUpdate && <Button size="sm" variant="outline" disabled={busy} onClick={() => onUpdate(item.id)}>Update</Button>}
    </div>}
    {(preview || changelogLink) && <div className="flex flex-wrap gap-3 text-xs">
      {preview && <a className="underline" href={preview} target="_blank" rel="noreferrer">Preview</a>}
      {changelogLink && <a className="underline" href={changelogLink} target="_blank" rel="noreferrer">Changelog</a>}
    </div>}
  </CardContent></Card>;
}

function CatalogTab({ refreshKey, onMutated, onError }: { refreshKey: number; onMutated: (message: string) => void; onError: (error: unknown) => void }) {
  const [type, setType] = useState<"all" | "plugin" | "theme">("all");
  const [edition, setEdition] = useState<"all" | "free" | "pro">("all");
  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [items, setItems] = useState<CatalogItem[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [reloadKey, setReloadKey] = useState(0);
  const [busy, setBusy] = useState("");

  useEffect(() => {
    let active = true;
    setState("loading"); setError(undefined);
    void extensionsService.catalog({ type, edition, q: appliedSearch }).then((result) => {
      if (!active) return;
      setItems(result.items ?? []);
      setState("ready");
    }).catch((cause) => {
      if (!active) return;
      setError(cause); setState("error");
    });
    return () => { active = false; };
  }, [type, edition, appliedSearch, reloadKey, refreshKey]);

  const act = async (action: "install" | "update", id: string) => {
    if (busy) return;
    setBusy(`${action}:${id}`); onError(undefined);
    try {
      if (action === "install") {
        await extensionsService.installFromCatalog(id);
        onMutated(`${id} installed from catalog. Enable it when ready.`);
      } else {
        await extensionsService.updateFromCatalog(id);
        onMutated(`${id} updated to the latest version.`);
      }
    } catch (cause) { onError(cause); } finally { setBusy(""); }
  };

  return <div className="space-y-3">
    <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); setAppliedSearch(search); }}>
      <label className="flex flex-col gap-1 text-xs font-medium">Type
        <select aria-label="Catalog type" className="h-9 rounded-md border bg-background px-2 text-sm" value={type} onChange={(event) => setType(event.target.value as typeof type)}>
          <option value="all">All types</option>
          <option value="plugin">Plugins</option>
          <option value="theme">Themes</option>
        </select>
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium">Edition
        <select aria-label="Catalog edition" className="h-9 rounded-md border bg-background px-2 text-sm" value={edition} onChange={(event) => setEdition(event.target.value as typeof edition)}>
          <option value="all">All editions</option>
          <option value="free">Free edition</option>
          <option value="pro">Pro edition</option>
        </select>
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium">Search
        <input aria-label="Catalog search" className="h-9 w-56 rounded-md border bg-background px-2 text-sm" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, vendor, tag" />
      </label>
      <Button type="submit" size="sm" variant="outline">Search</Button>
    </form>
    {state === "loading" ? <p role="status">Loading catalog...</p>
      : state === "error" ? <ApiState error={error ?? new Error(messageOf(error))} onRetry={() => setReloadKey((value) => value + 1)} />
      : items.length === 0 ? <p className="text-sm text-muted-foreground">No catalog items match these filters.</p>
      : <div className="space-y-2">{items.map((item) => <CatalogCard key={`${item.type}:${item.id}`} item={item} busy={!!busy} onInstall={(id) => void act("install", id)} onUpdate={(id) => void act("update", id)} />)}</div>}
  </div>;
}

function UpdateCard({ item, busy, onUpdate }: { item: CatalogItem; busy: boolean; onUpdate: (id: string) => void }) {
  const canUpdate = item.can_update && licenseAllowsItem(item);
  const reason = actionReasonFor(item, false, canUpdate);
  return <Card><CardContent className="space-y-2 p-4">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-52 flex-1">
        <p className="font-medium">{item.name}</p>
        <p className="text-xs text-muted-foreground">{item.id} · {item.vendor} · {item.type}</p>
      </div>
      <CatalogBadges item={item} />
    </div>
    <p className="text-sm">v{item.installed_version ?? "—"} → v{item.latest_version}</p>
    <p className="text-xs text-muted-foreground">{licenseLabel(item.license_status)}{item.license_sku ? ` · SKU ${item.license_sku}` : ""}</p>
    {!item.compatible && <p className="text-xs text-destructive">Requires core {item.min_core ?? "—"} · commerce {item.min_commerce ?? "—"}.</p>}
    {item.changelog && <p className="whitespace-pre-line text-xs text-muted-foreground">{item.changelog}</p>}
    {reason && <p role="alert" className="rounded-md border border-amber-500/30 bg-amber-500/5 p-2 text-xs text-amber-900 dark:text-amber-200">{reason}</p>}
    {canUpdate && <div className="flex flex-wrap gap-2">
      <Button size="sm" variant="outline" disabled={busy} onClick={() => onUpdate(item.id)}>Update</Button>
    </div>}
  </CardContent></Card>;
}

function UpdatesTab({ refreshKey, onMutated, onError }: { refreshKey: number; onMutated: (message: string) => void; onError: (error: unknown) => void }) {
  const [items, setItems] = useState<CatalogItem[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [reloadKey, setReloadKey] = useState(0);
  const [busy, setBusy] = useState("");

  useEffect(() => {
    let active = true;
    setState("loading"); setError(undefined);
    void extensionsService.updates().then((result) => {
      if (!active) return;
      setItems((result.items ?? []).filter((item) => item.update_available));
      setState("ready");
    }).catch((cause) => {
      if (!active) return;
      setError(cause); setState("error");
    });
    return () => { active = false; };
  }, [reloadKey, refreshKey]);

  const act = async (id: string) => {
    if (busy) return;
    setBusy(`update:${id}`); onError(undefined);
    try {
      await extensionsService.updateFromCatalog(id);
      onMutated(`${id} updated to the latest version.`);
    } catch (cause) { onError(cause); } finally { setBusy(""); }
  };

  return state === "loading" ? <p role="status">Loading updates...</p>
    : state === "error" ? <ApiState error={error ?? new Error(messageOf(error))} onRetry={() => setReloadKey((value) => value + 1)} />
    : items.length === 0 ? <p className="text-sm text-muted-foreground">All installed extensions and themes are up to date.</p>
    : <div className="space-y-2">{items.map((item) => <UpdateCard key={item.id} item={item} busy={!!busy} onUpdate={(id) => void act(id)} />)}</div>;
}

function LicensesTab({ license, refreshKey, onMutated, onError }: { license: ExtensionLicenseStatus | null; refreshKey: number; onMutated: (message: string) => void; onError: (error: unknown) => void }) {
  const [items, setItems] = useState<CatalogItem[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [reloadKey, setReloadKey] = useState(0);
  const [licenseText, setLicenseText] = useState("");
  const [fileName, setFileName] = useState("");
  const [importError, setImportError] = useState("");
  const [busy, setBusy] = useState("");
  const [removeOpen, setRemoveOpen] = useState(false);
  const [feed, setFeed] = useState<ProFeed | null>(null);
  const [feedState, setFeedState] = useState<"loading" | "ready" | "forbidden" | "error">("loading");
  const [proBusy, setProBusy] = useState("");

  useEffect(() => {
    let active = true;
    setState("loading"); setError(undefined);
    void extensionsService.catalog({ type: "all", edition: "all" }).then((result) => {
      if (!active) return;
      setItems((result.items ?? []).filter((item) => item.license_sku || item.license_required || item.edition === "pro"));
      setState("ready");
    }).catch((cause) => {
      if (!active) return;
      setError(cause); setState("error");
    });
    return () => { active = false; };
  }, [reloadKey, refreshKey]);

  useEffect(() => {
    let active = true;
    setFeedState("loading");
    void extensionsService.proFeed().then((result) => {
      if (!active) return;
      setFeed(result ?? null); setFeedState("ready");
    }).catch((cause) => {
      if (!active) return;
      setFeed(null);
      setFeedState(toAdminApiError(cause).status === 403 ? "forbidden" : "error");
    });
    return () => { active = false; };
  }, [refreshKey]);

  const source = license?.source ?? "none";
  const stored = license?.stored ?? false;
  const envManaged = source === "env";

  const chooseFile = async (file: File | undefined) => {
    if (!file) return;
    setImportError(""); setFileName(file.name);
    try {
      setLicenseText(await file.text());
    } catch {
      setImportError("The selected license file could not be read.");
    }
  };

  const importLicense = async () => {
    if (busy) return;
    setImportError(""); onError(undefined);
    try {
      JSON.parse(licenseText);
    } catch {
      setImportError("License must be valid JSON. Paste the license document or choose a .json file.");
      return;
    }
    setBusy("license:import");
    try {
      await extensionsService.importLicense(licenseText);
      setLicenseText(""); setFileName("");
      onMutated("License imported. Review the edition, status and expiry below.");
    } catch (cause) {
      setImportError(licenseImportError(cause));
    } finally { setBusy(""); }
  };

  const removeLicense = async () => {
    if (busy) return;
    setBusy("license:remove"); onError(undefined);
    try {
      await extensionsService.removeLicense();
      setLicenseText(""); setFileName("");
      onMutated("License removed.");
    } catch (cause) { onError(cause); } finally { setBusy(""); }
  };

  const feedCount = feed?.count ?? feed?.items?.length ?? 0;

  const runProAction = async (action: "install" | "update", id: string, version?: string) => {
    if (proBusy) return;
    setProBusy(`${action}:${id}`); onError(undefined);
    try {
      if (action === "install") {
        await extensionsService.proInstall(id, version);
        onMutated(`${id} installed from the Pro feed. Enable it when ready.`);
      } else {
        await extensionsService.proUpdate(id, version);
        onMutated(`${id} updated from the Pro feed.`);
      }
    } catch (cause) { onError(cause); } finally { setProBusy(""); }
  };

  return <div className="space-y-4">
    <Card><CardHeader><CardTitle>License</CardTitle></CardHeader><CardContent className="space-y-2 text-sm">
      {license ? <>
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={license.ok ? "success" : "destructive"}>{license.ok ? "Valid" : "Not valid"}</Badge>
          <Badge variant="outline">{license.edition}</Badge>
          {envManaged && <Badge variant="warning">Managed by environment</Badge>}
          <Badge variant={stored ? "success" : "outline"}>{stored ? "Stored" : "Not stored"}</Badge>
        </div>
        <p>Edition: <strong>{license.edition}</strong> — status: <strong>{license.reason}</strong></p>
        <p className="text-muted-foreground">Expires: {license.expires_at ?? "—"} · Grace until: {license.grace_until ?? "—"}</p>
        <p className="text-muted-foreground">Source: {source} ({LICENSE_SOURCE_LABEL[source] ?? source}) · Stored: {stored ? "yes" : "no"}</p>
        {Array.isArray(license.features) && license.features.length > 0 && <p className="text-muted-foreground">Features: {license.features.join(", ")}</p>}
        {Array.isArray(license.extensions) && license.extensions.length > 0 && <p className="text-muted-foreground">Extensions: {license.extensions.join(", ")}</p>}
      </> : <p className="text-muted-foreground">License information is unavailable for this store.</p>}
      {envManaged && <p className="text-xs text-muted-foreground">The active license is supplied by EXTENSION_LICENSE_FILE, so it cannot be removed here.</p>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Import license</CardTitle></CardHeader><CardContent className="space-y-2 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <input aria-label="License file" type="file" accept=".json,application/json" disabled={!!busy} onChange={(event) => { void chooseFile(event.target.files?.[0]); event.target.value = ""; }} />
        <span className="text-xs text-muted-foreground">{fileName || "No file selected"}</span>
      </div>
      <label className="flex flex-col gap-1 text-xs font-medium">License JSON
        <textarea aria-label="License JSON" className="min-h-24 rounded-md border bg-background p-2 font-mono text-xs" value={licenseText} disabled={!!busy} onChange={(event) => setLicenseText(event.target.value)} placeholder="Paste the signed license document here" />
      </label>
      {importError && <p role="alert" className="rounded-md border border-destructive/40 p-2 text-xs text-destructive">{importError}</p>}
      <div className="flex flex-wrap gap-2">
        <Button size="sm" disabled={!licenseText.trim() || !!busy} onClick={() => void importLicense()}>{busy === "license:import" ? "Importing..." : "Import license"}</Button>
        <Button size="sm" variant="outline" disabled={!stored || envManaged || !!busy} onClick={() => setRemoveOpen(true)}>Remove license</Button>
      </div>
      <p className="text-xs text-muted-foreground">The raw license document is only used for import and is not displayed after it is accepted.</p>
    </CardContent></Card>
    <Card><CardHeader><CardTitle>Pro feed</CardTitle></CardHeader><CardContent className="space-y-2 text-sm">
      {feedState === "loading" ? <p role="status">Loading Pro feed...</p>
        : feedState === "forbidden" ? <><p role="alert" className="text-muted-foreground">Pro feed requires a valid license.</p><p className="text-xs text-muted-foreground">Pro artifacts and install/update actions are blocked until the licence is valid or in grace.</p></>
        : feedState === "error" ? <p role="alert" className="text-destructive">The Pro feed could not be loaded.</p>
        : <>
          <p>Pro feed: {feedCount} item{feedCount === 1 ? "" : "s"}{feed?.generated_at ? ` · generated ${feed.generated_at}` : ""}</p>
          {feed?.schema && <p className="text-xs text-muted-foreground">Schema: {feed.schema}</p>}
          {(feed?.items ?? []).length === 0 ? <p className="text-muted-foreground">No Pro items are listed in the feed.</p>
            : <div className="space-y-2">{(feed?.items ?? []).map((item) => <ProFeedRow key={item.id} item={item} busy={!!proBusy} onInstall={(id, version) => void runProAction("install", id, version)} onUpdate={(id, version) => void runProAction("update", id, version)} />)}</div>}
        </>}
    </CardContent></Card>
    <Card><CardHeader><CardTitle>SKU status</CardTitle></CardHeader><CardContent>
      {state === "loading" ? <p role="status">Loading license SKUs...</p>
        : state === "error" ? <ApiState error={error ?? new Error(messageOf(error))} onRetry={() => setReloadKey((value) => value + 1)} />
        : items.length === 0 ? <p className="text-sm text-muted-foreground">No licensed SKUs in the catalog.</p>
        : <div className="space-y-1">{items.map((item) => <div key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm">
          <div>
            <p className="font-medium">{item.name}</p>
            <p className="text-xs text-muted-foreground">{item.license_sku ?? item.id} · {item.edition === "pro" ? "Pro" : "Free"}</p>
          </div>
          <Badge variant={LICENSE_VARIANT[item.license_status] ?? "outline"}>{licenseLabel(item.license_status)}</Badge>
        </div>)}</div>}
    </CardContent></Card>
    <ConfirmDialog
      open={removeOpen}
      title="Remove license"
      description="Remove the stored license? Pro features and licensed extensions stay enabled until the next entitlement check."
      destructive
      isPending={busy === "license:remove"}
      onCancel={() => setRemoveOpen(false)}
      onConfirm={() => { setRemoveOpen(false); void removeLicense(); }}
    />
  </div>;
}

export default function ExtensionsPage() {
  return <RoleGate roles={["owner", "admin"]}><ExtensionsContent /></RoleGate>;
}

function ExtensionsContent() {
  const [tab, setTab] = useState<ExtensionTab>("installed");
  const [items, setItems] = useState<ExtensionItem[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState("");
  const [notice, setNotice] = useState("");
  const [actionError, setActionError] = useState<unknown>();
  const [refreshKey, setRefreshKey] = useState(0);
  const [updateCount, setUpdateCount] = useState<number | null>(null);
  const [packageFile, setPackageFile] = useState<File | null>(null);
  const [packageReport, setPackageReport] = useState<ExtensionPackageReport | null>(null);
  const [qitReport, setQitReport] = useState<ExtensionQualityReport | null>(null);
  const [license, setLicense] = useState<ExtensionLicenseStatus | null>(null);
  const [forcePackage, setForcePackage] = useState(false);

  const load = () => {
    setState("loading"); setError(undefined);
    void extensionsService.list().then((value) => {
      setItems(value);
      setState("ready");
    }).catch((cause) => { setError(cause); setState("error"); });
  };
  useEffect(load, [refreshKey]);
  useEffect(() => { void extensionsService.license().then((value) => setLicense(value ?? null)).catch(() => setLicense(null)); }, [refreshKey]);
  useEffect(() => {
    let active = true;
    void extensionsService.updates().then((result) => {
      if (!active) return;
      setUpdateCount((result.items ?? []).filter((item) => item.update_available).length);
    }).catch(() => { if (active) setUpdateCount(null); });
    return () => { active = false; };
  }, [refreshKey]);

  const refreshAfterMutation = (message: string) => {
    setNotice(message);
    setActionError(undefined);
    setRefreshKey((value) => value + 1);
  };
  const reportActionError = (cause: unknown) => {
    setActionError(cause);
    setNotice("");
  };

  const [removeData, setRemoveData] = useState(false);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; destructive?: boolean; run: () => void } | null>(null);
  const run = async (id: string, action: "install" | "enable" | "disable" | "upgrade" | "health" | "uninstall", withData = false) => {
    if (busy) return;
    if (action === "disable" || action === "uninstall") {
      setConfirmation({
        title: action === "uninstall" ? "Uninstall extension" : "Disable extension",
        description: `${action} extension ${id}? ${action === "uninstall" && withData ? "Extension data will be deleted." : "Data is kept and routes unmount."}`,
        destructive: action === "uninstall" && withData,
        run: () => void executeRun(id, action, withData),
      });
      return;
    }
    await executeRun(id, action, withData);
  };

  const executeRun = async (id: string, action: "install" | "enable" | "disable" | "upgrade" | "health" | "uninstall", withData = false) => {
    setBusy(`${action}:${id}`); setError(undefined); setNotice("");
    try {
      if (action === "health") {
        const result = await extensionsService.health(id);
        setNotice(`${id}: ${JSON.stringify(result)}`);
      } else if (action === "uninstall") {
        await extensionsService.uninstall(id, withData);
        setNotice(`${id} uninstalled${withData ? " with data removed" : " (data kept)"}.`);
        load();
      } else {
        await extensionsService[action](id);
        setNotice(`${id} ${action === "install" ? "installed" : action === "enable" ? "enabled" : action === "disable" ? "disabled" : "upgraded"}.`);
        load();
      }
    } catch (cause) { setError(cause); } finally { setBusy(""); }
  };

  const verifyPackage = async () => {
    if (!packageFile || busy) return;
    setBusy("package:verify"); setError(undefined); setNotice(""); setPackageReport(null);
    try {
      const report = await extensionsService.verifyPackage(packageFile);
      setPackageReport(report);
      setNotice(report.ok ? `Package ${report.id} ${report.version} verified.` : "Package has errors; fix them before installing.");
    } catch (cause) { setError(cause); } finally { setBusy(""); }
  };

  const runQit = async () => {
    if (!packageFile || busy) return;
    setBusy("package:qit"); setError(undefined); setNotice(""); setQitReport(null);
    try {
      const report = await extensionsService.qitPackage(packageFile);
      setQitReport(report);
      setNotice(report.ok ? "QIT passed; the package is safe to install." : "QIT failed; fix the failing checks before installing.");
    } catch (cause) { setError(cause); } finally { setBusy(""); }
  };

  const installPackage = async () => {
    if (!packageFile || busy) return;
    setBusy("package:install"); setError(undefined);
    try {
      const result = await extensionsService.installPackage(packageFile, forcePackage);
      setNotice(`Package ${result.id ?? ""} ${result.version ?? ""} installed. Enable it below when ready.`);
      setPackageFile(null); setPackageReport(null); setQitReport(null); setForcePackage(false);
      load();
    } catch (cause) { setError(cause); } finally { setBusy(""); }
  };

  return <div className="space-y-4">
    <PageHeader title="Extensions" description="Drop-in modules: install, enable, disable, uninstall. Disable unmounts routes and listeners; data stays unless removed." />
    <div role="tablist" aria-label="Extension sections" className="flex max-w-full gap-2 overflow-x-auto border-b pb-2">
      {TABS.map((item) => <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} aria-controls={tab === item.id ? `extensions-panel-${item.id}` : undefined} onClick={() => setTab(item.id)} className={`rounded-md px-3 py-2 text-sm font-medium ${tab === item.id ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}>{item.label}</button>)}
    </div>
    {updateCount !== null && updateCount > 0 && <div role="status" className="flex flex-wrap items-center gap-2 rounded-md border p-3 text-sm">
      <span>{updateCount} update{updateCount === 1 ? "" : "s"} available.</span>
      <Button size="sm" variant="outline" onClick={() => setTab("updates")}>View updates</Button>
    </div>}
    {notice && <p role="status" className="rounded-md border p-3 text-sm">{notice}</p>}
    {actionError != null && <p role="alert" className="rounded-md border border-destructive/40 p-3 text-sm text-destructive">{messageOf(actionError)}</p>}
    <div role="tabpanel" id={`extensions-panel-${tab}`} aria-label={TABS.find((item) => item.id === tab)?.label}>
      {tab === "catalog" ? <CatalogTab refreshKey={refreshKey} onMutated={refreshAfterMutation} onError={reportActionError} />
        : tab === "updates" ? <UpdatesTab refreshKey={refreshKey} onMutated={refreshAfterMutation} onError={reportActionError} />
        : tab === "licenses" ? <LicensesTab license={license} refreshKey={refreshKey} onMutated={refreshAfterMutation} onError={reportActionError} />
        : <>
          {license && <Card><CardHeader><CardTitle>License</CardTitle></CardHeader><CardContent className="text-sm"><p>Edition: <strong>{license.edition}</strong> — status: <strong>{license.reason}</strong>{license.expires_at ? ` — expires: ${license.expires_at}` : ""}</p>{Array.isArray(license.extensions) && license.extensions.length > 0 && <p className="text-muted-foreground">Extensions: {license.extensions.join(", ")}</p>}</CardContent></Card>}
          <Card><CardHeader><CardTitle>Install package</CardTitle></CardHeader><CardContent className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <input aria-label="Extension package zip" type="file" accept=".zip,application/zip" disabled={!!busy} onChange={(event) => { setPackageFile(event.target.files?.[0] ?? null); setPackageReport(null); setQitReport(null); event.target.value = ""; }} />
              <Button size="sm" variant="outline" disabled={!packageFile || !!busy} onClick={() => void verifyPackage()}>Verify</Button>
              <Button size="sm" variant="outline" disabled={!packageFile || !!busy} onClick={() => void runQit()}>Run QIT</Button>
              <Button size="sm" disabled={!packageFile || !!busy || packageReport?.ok === false || qitReport?.ok === false} onClick={() => void installPackage()}>Install package</Button>
              <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={forcePackage} onChange={(event) => setForcePackage(event.target.checked)} /> replace existing</label>
            </div>
            {packageReport && <div className="rounded-md border p-3 text-xs" role="status"><p className="font-medium">{packageReport.id} {packageReport.version} · signed: {packageReport.signed ? "yes" : "no"} · files: {packageReport.files ?? 0}</p>{packageReport.errors.length > 0 && <ul className="list-disc pl-4 text-destructive">{packageReport.errors.map((item) => <li key={item}>{item}</li>)}</ul>}{packageReport.warnings.length > 0 && <ul className="list-disc pl-4 text-muted-foreground">{packageReport.warnings.map((item) => <li key={item}>{item}</li>)}</ul>}</div>}
            {qitReport && <div className="rounded-md border p-3 text-xs" role="status" aria-label="QIT results">
              <p className="font-medium">QIT {qitReport.ok ? "passed" : "failed"}</p>
              <ul className="mt-1 space-y-1">{qitReport.checks.map((check) => <li key={check.name}>
                <span className={check.ok ? "font-medium text-emerald-600" : "font-medium text-destructive"}>{check.ok ? "PASS" : "FAIL"} {check.name}</span>
                {check.details.length > 0 && <ul className="list-disc pl-4 text-muted-foreground">{check.details.map((detail, index) => <li key={`${check.name}-${index}`}>{detail}</li>)}</ul>}
              </li>)}</ul>
            </div>}
          </CardContent></Card>
          {state === "loading" ? <p role="status">Loading extensions...</p>
            : state === "error" ? <ApiState error={error ?? new Error(messageOf(error))} onRetry={load} />
            : items.length === 0 ? <p className="text-sm text-muted-foreground">No extensions discovered. Add a module under app/Extensions/&lt;vendor&gt;/&lt;name&gt;/.</p>
            : <div className="space-y-2">{items.map((item) => <Card key={item.id}><CardContent className="flex flex-wrap items-center gap-3 p-4">
              <div className="min-w-52 flex-1"><p className="font-medium">{item.id}</p><p className="text-xs text-muted-foreground">manifest v{item.manifest_version} · installed v{item.version} · {item.routes.length} routes · {item.migrations.length} migrations{item.admin_ui ? ` · ${item.admin_ui.panels.length} panels` : ""}</p>{item.last_error && <p role="alert" className="text-xs text-destructive">{item.last_error}</p>}{item.state === "enabled" && item.admin_ui && <p><Link className="text-xs underline" href={`/extensions/${encodeURIComponent(item.id)}`}>Open {item.admin_ui.label}</Link></p>}</div>
              <Badge variant={STATE_VARIANT[item.state] ?? "outline"}>{item.state}</Badge>
              <div className="flex flex-wrap items-center gap-1">
                <Button size="sm" variant="outline" disabled={!!busy} onClick={() => void run(item.id, "install")}>Install</Button>
                <Button size="sm" variant="outline" disabled={!!busy} onClick={() => void run(item.id, "enable")}>Enable</Button>
                <Button size="sm" variant="outline" disabled={!!busy} onClick={() => void run(item.id, "disable")}>Disable</Button>
                <Button size="sm" variant="outline" disabled={!!busy} onClick={() => void run(item.id, "health")}>Health</Button>
                <Button size="sm" variant="outline" disabled={!!busy} onClick={() => void run(item.id, "upgrade")}>Upgrade</Button>
                <Button size="sm" variant="outline" disabled={!!busy} onClick={() => void run(item.id, "uninstall", removeData)}>Uninstall</Button>
                <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={removeData} onChange={(e) => setRemoveData(e.target.checked)} /> remove data</label>
              </div>
            </CardContent></Card>)}
            </div>}
        </>}
    </div>
    <ConfirmDialog
      open={confirmation !== null}
      title={confirmation?.title ?? ""}
      description={confirmation?.description}
      destructive={confirmation?.destructive}
      isPending={!!busy}
      onCancel={() => setConfirmation(null)}
      onConfirm={() => {
        const action = confirmation;
        setConfirmation(null);
        action?.run();
      }}
    />
  </div>;
}
