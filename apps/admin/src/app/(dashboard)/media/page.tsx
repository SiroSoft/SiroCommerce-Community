"use client";
import { useEffect, useRef, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CapabilityGate } from "@/components/capability-gate";
import { ApiState } from "@/modules/merchant/api-state";
import { mediaLibraryService, type MediaAsset } from "@/services/media-library.service";
import { derivativeSummary, derivativesOf, previewUrl } from "@/lib/media-derivatives";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { useAuthStore } from "@/store/auth.store";
import { useI18n } from "@/providers/i18n-provider";

export default function MediaPage() {
  return <CapabilityGate capability="cms"><MediaContent /></CapabilityGate>;
}

function MediaContent() {
  const { t } = useI18n();
  const user = useAuthStore((s) => s.user);
  const canWrite = canOperateMerchant(user, "cms");
  const [items, setItems] = useState<MediaAsset[]>([]);
  const [q, setQ] = useState("");
  const [type, setType] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [title, setTitle] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<MediaAsset | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = () => {
    setLoading(true); setError(undefined);
    mediaLibraryService.list({ q: q || undefined, type: type || undefined, per_page: 48 })
      .then((r) => setItems(r.items)).catch(setError).finally(() => setLoading(false));
  };
  useEffect(load, []);
  useEffect(() => { const t = window.setTimeout(load, 400); return () => window.clearTimeout(t); }, [q, type]);

  const upload = async () => {
    const file = fileRef.current?.files?.[0];
    if (!file) { setNotice("Choose a file first (image, mp4/webm, document)"); return; }
    setBusy(true); setError(undefined); setNotice("");
    try {
      const asset = await mediaLibraryService.upload(file, { title: title.trim() || undefined });
      setNotice(`Uploaded ${String(asset.type)} · ${String(asset.url)}`);
      setTitle("");
      if (fileRef.current) fileRef.current.value = "";
      load();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  const remove = async (id: string) => {
    setBusy(true); setError(undefined);
    try { await mediaLibraryService.remove(id); load(); } catch (e) { setError(e); } finally { setBusy(false); }
  };
  const copy = async (url: string) => {
    try { await navigator.clipboard.writeText(url); setNotice("URL copied"); }
    catch { setNotice(url); }
  };

  return (
    <div className="space-y-5">
      <PageHeader title={t("media.title")} description={t("media.description")} />
      {notice && <p role="status" className="rounded-md border bg-muted px-3 py-2 text-sm break-all">{notice}</p>}
      {error ? <ApiState error={error} onRetry={load} /> : null}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle>{t("media.uploadTitle")}</CardTitle></CardHeader><CardContent className="space-y-3">
          <div><Label htmlFor="media-file">File (image ≤10MB, video mp4/webm ≤50MB, doc ≤20MB)</Label><Input id="media-file" ref={fileRef} type="file" accept="image/*,video/mp4,video/webm,.pdf,.csv,.zip,.doc,.docx,.txt,.json,.xml" disabled={!canWrite || busy} /></div>
          <div><Label htmlFor="media-title">Title (optional)</Label><Input id="media-title" value={title} onChange={(e) => setTitle(e.target.value)} disabled={!canWrite || busy} /></div>
          <Button size="sm" onClick={upload} disabled={!canWrite || busy}>{t("media.uploadBtn")}</Button>
          {!canWrite && <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot upload.</p>}
        </CardContent></Card>
        <Card><CardHeader><CardTitle>{t("media.filterTitle")}</CardTitle></CardHeader><CardContent className="space-y-3">
          <div><Label htmlFor="media-q">Search</Label><Input id="media-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="title, alt, url" /></div>
          <div><Label htmlFor="media-type">Type</Label><select id="media-type" className="flex h-9 w-full rounded-md border px-3 text-sm" value={type} onChange={(e) => setType(e.target.value)}><option value="">All</option><option value="image">Images</option><option value="video">Videos</option><option value="file">Files</option></select></div>
          <Button variant="outline" size="sm" onClick={load} disabled={loading}>{t("media.reloadBtn")}</Button>
        </CardContent></Card>
      </div>
      <Card><CardHeader><CardTitle>{t("media.assets")} ({items.length})</CardTitle></CardHeader><CardContent>
        {loading ? <p role="status" className="text-sm text-muted-foreground">{t("media.loading")}</p> : items.length === 0 ? <p className="text-sm text-muted-foreground">{t("media.empty")}</p> : (
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{items.map((a) => {
            const derivatives = derivativesOf(a);
            return <li key={String(a.id)} className="rounded-md border p-2 text-sm">
              {String(a.type) === "image" ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={previewUrl(a, a.url)} alt={String(a.alt ?? "")} className="h-28 w-full rounded object-cover" loading="lazy" />
              ) : String(a.type) === "video" ? (
                <video src={String(a.url)} className="h-28 w-full rounded bg-muted" preload="none" />
              ) : <p className="flex h-28 items-center justify-center rounded bg-muted text-xs">file · {String(a.mime_type ?? "")}</p>}
              <p className="mt-1 truncate font-medium">{String(a.title || a.url)}</p>
              <p className="truncate text-xs text-muted-foreground">{String(a.type)} · {String(a.size_bytes ?? 0)} B</p>
              {derivatives.length > 0 ? <p className="text-xs text-muted-foreground">{derivatives.length} derivatives · {derivativeSummary(derivatives)}</p> : null}
              <div className="mt-1 flex gap-2">
                <Button variant="outline" size="sm" onClick={() => copy(String(a.url))}>{t("media.copyUrl")}</Button>
                <Button variant="outline" size="sm" disabled={!canWrite || busy} onClick={() => setDeleteTarget(a)}>{t("media.deleteBtn")}</Button>
              </div>
            </li>;
          })}</ul>
        )}
      </CardContent></Card>
      <ConfirmDialog
        open={deleteTarget !== null}
        title="Delete media asset"
        description={deleteTarget ? `Delete "${String(deleteTarget.title || deleteTarget.url)}"? Product pages using its URL keep working only if cached.` : undefined}
        confirmLabel="Delete"
        destructive
        isPending={busy}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={() => {
          const target = deleteTarget;
          setDeleteTarget(null);
          if (target) void remove(String(target.id));
        }}
      />
    </div>
  );
}
