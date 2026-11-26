"use client";

import { useCallback, useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { ApiState } from "@/modules/merchant/api-state";
import { derivativeSummary, derivativesOf, previewUrl } from "@/lib/media-derivatives";
import { fe1Service, type RecordValue } from "@/services/fe1.service";

type Props = { productId: string | number };

export function ProductMediaManager({ productId }: Props) {
  const [items, setItems] = useState<RecordValue[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [url, setUrl] = useState("");
  const [alt, setAlt] = useState("");
  const [kind, setKind] = useState("image");
  const [notice, setNotice] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; label: string } | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    fe1Service.media(productId).then((value) => setItems(value)).catch(setError).finally(() => setLoading(false));
  }, [productId]);
  useEffect(() => { load(); }, [load]);

  async function run(work: () => Promise<unknown>, done: string) {
    setBusy(true);
    setNotice("");
    try {
      await work();
      setNotice(done);
      load();
    } catch (error) {
      setError(error);
    } finally {
      setBusy(false);
    }
  }

  const move = (index: number, delta: -1 | 1) => {
    const order = items.map((item) => String(item.id ?? ""));
    const target = index + delta;
    if (target < 0 || target >= order.length) return;
    const [moved] = order.splice(index, 1);
    order.splice(target, 0, moved!);
    void run(() => fe1Service.reorderMedia(productId, order), "Order saved.");
  };

  return <Card><CardHeader><CardTitle>Media gallery</CardTitle></CardHeader><CardContent className="space-y-4">
    {loading ? <p role="status">Loading media...</p> : items.length ? <ul className="space-y-2">{items.map((item, index) => {
      const derivatives = derivativesOf(item);
      return <li key={String(item.id ?? index)} className="flex items-center gap-3 rounded-md border p-2">
        {String(item.type ?? "") === "video" ? <span className="flex h-12 w-12 items-center justify-center rounded bg-muted text-xs" aria-hidden="true">▶</span>
        : String(item.type ?? "") === "youtube" ? <span className="flex h-12 w-12 items-center justify-center rounded bg-muted text-xs" aria-hidden="true">YT</span>
        : String(item.url ?? "").startsWith("http") || String(item.url ?? "").startsWith("/") ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={previewUrl(item, item.url)} alt={String(item.alt ?? "")} className="h-12 w-12 rounded object-cover" loading="lazy" />
        ) : <span className="flex h-12 w-12 items-center justify-center rounded bg-muted text-xs">file</span>}
        <div className="min-w-0 flex-1"><p className="truncate text-sm font-medium">{String(item.alt || item.title || item.url)}</p><p className="truncate text-xs text-muted-foreground">{String(item.type ?? "image")} · {String(item.url ?? "")}</p>{derivatives.length > 0 ? <p className="text-xs text-muted-foreground">{derivatives.length} derivatives · {derivativeSummary(derivatives)}</p> : null}</div>
        <Button size="icon" variant="ghost" aria-label="Move up" disabled={busy || index === 0} onClick={() => move(index, -1)}><ArrowUp className="h-4 w-4" /></Button>
        <Button size="icon" variant="ghost" aria-label="Move down" disabled={busy || index === items.length - 1} onClick={() => move(index, 1)}><ArrowDown className="h-4 w-4" /></Button>
        <Button size="icon" variant="ghost" aria-label={`Delete ${String(item.alt || item.url)}`} disabled={busy} onClick={() => setDeleteTarget({ id: String(item.id), label: String(item.alt || item.url) })}><Trash2 className="h-4 w-4" /></Button>
      </li>;
    })}</ul> : <p className="text-sm text-muted-foreground">No media yet. Add an image, mp4 video or YouTube link.</p>}
    <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto_auto]">
      <Input aria-label="Media URL" value={url} onChange={(event) => setUrl(event.target.value)} disabled={busy} placeholder="https://... mp4 / YouTube" />
      <Input aria-label="Media alt text" value={alt} onChange={(event) => setAlt(event.target.value)} disabled={busy} placeholder="Alt text" />
      <select aria-label="Media type" className="flex h-9 rounded-md border px-2 text-sm" value={kind} onChange={(event) => setKind(event.target.value)} disabled={busy}><option value="image">image</option><option value="video">video</option><option value="youtube">youtube</option></select>
      <Button disabled={busy || !url.trim()} onClick={() => void run(async () => { await fe1Service.addMedia(productId, { url: url.trim(), type: kind, ...(alt.trim() ? { alt: alt.trim() } : {}) }); setUrl(""); setAlt(""); }, "Media added.")}>Add media</Button>
    </div>
    {notice ? <p role="status" className="text-sm text-emerald-600">{notice}</p> : null}
    {error ? <ApiState error={error} onRetry={load} /> : null}
    <ConfirmDialog
      open={deleteTarget !== null}
      title="Delete media"
      description={deleteTarget ? `Delete "${deleteTarget.label}"? Product pages using its URL keep working only if cached.` : undefined}
      confirmLabel="Delete"
      destructive
      isPending={busy}
      onCancel={() => setDeleteTarget(null)}
      onConfirm={() => {
        const target = deleteTarget;
        setDeleteTarget(null);
        if (target) void run(() => fe1Service.deleteMedia(productId, target.id), "Media deleted.");
      }}
    />
  </CardContent></Card>;
}
