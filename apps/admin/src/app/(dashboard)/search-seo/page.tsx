"use client";

import { useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { ApiState } from "@/modules/merchant/api-state";
import api from "@/services/api";
import { idempotencyKey } from "@/services/merchant.service";

type Rewrite = { id: string; request_path: string; target_path: string; redirect_type: number };
type Term = { id: string; term: string; hits: number; misses: number };
type Synonym = { id: string; term: string; synonyms: string[] };
type SitemapSettings = { products: { frequency: string; priority: number }; categories: { frequency: string; priority: number }; cms_pages: { frequency: string; priority: number }; brands: { frequency: string; priority: number }; images_enabled: boolean };

export default function SearchSeoPage() {
  return <CapabilityGate capability="seo"><SearchSeoContent /></CapabilityGate>;
}

function SearchSeoContent() {
  const [tab, setTab] = useState<"rewrites" | "terms" | "synonyms" | "sitemaps">("rewrites");

  return (
    <div className="space-y-6">
      <PageHeader title="Search SEO" description="URL Rewrites, Search Terms, Synonyms, Sitemaps — better than Bagisto" />
      <div className="flex gap-2 border-b pb-2">
        {(["rewrites", "terms", "synonyms", "sitemaps"] as const).map((t) => (
          <button key={t} onClick={() => setTab(t)} className={`rounded-md px-3 py-2 text-sm font-medium ${tab === t ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}>{t[0].toUpperCase() + t.slice(1)}</button>
        ))}
      </div>
      {tab === "rewrites" && <RewritesTab />}
      {tab === "terms" && <TermsTab />}
      {tab === "synonyms" && <SynonymsTab />}
      {tab === "sitemaps" && <SitemapsTab />}
    </div>
  );
}

function RewritesTab() {
  const [items, setItems] = useState<Rewrite[]>([]);
  const [req, setReq] = useState("/old-path");
  const [target, setTarget] = useState("/new-path");
  const [type, setType] = useState("301");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const load = () => { setLoading(true); api.get<{ data?: { data?: Rewrite[] } }>("/api/admin/search-seo/url-rewrites").then((r) => setItems((r.data as { data?: { data?: Rewrite[] } }).data?.data ?? [])).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);
  const create = async () => {
    try { await api.post("/api/admin/search-seo/url-rewrites", { request_path: req.trim(), target_path: target.trim(), redirect_type: Number(type) }, { idempotencyKey: idempotencyKey() }); load(); } catch (e) { setError(e); }
  };
  const remove = async (id: string) => { if (!confirm("Delete rewrite?")) return; try { await api.delete(`/api/admin/search-seo/url-rewrites/${encodeURIComponent(id)}`); load(); } catch (e) { setError(e); } };
  return <Card><CardHeader><CardTitle>URL Rewrites</CardTitle></CardHeader><CardContent className="space-y-3">
    <div className="grid gap-2 md:grid-cols-4">
      <div><Label>Request path</Label><Input value={req} onChange={(e) => setReq(e.target.value)} placeholder="/old" /></div>
      <div><Label>Target path</Label><Input value={target} onChange={(e) => setTarget(e.target.value)} placeholder="/new or https://" /></div>
      <div><Label>Type</Label><select value={type} onChange={(e) => setType(e.target.value)} className="h-9 w-full rounded-md border px-3 text-sm"><option value="301">301</option><option value="302">302</option></select></div>
      <div className="flex items-end"><Button onClick={create}>Create</Button></div>
    </div>
    {loading ? <p role="status">Loading…</p> : error ? <ApiState error={error} onRetry={load} /> : items.length ? <div className="space-y-2">{items.map((r) => <div key={r.id} className="flex items-center justify-between rounded-md border p-3 text-sm"><span><Badge variant="outline">{r.redirect_type}</Badge> {r.request_path} → {r.target_path}</span><Button size="sm" variant="outline" onClick={() => remove(r.id)}>Delete</Button></div>)}</div> : <p className="text-sm text-muted-foreground">No rewrites.</p>}
  </CardContent></Card>;
}

function TermsTab() {
  const [items, setItems] = useState<Term[]>([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const load = () => {
    setLoading(true);
    api.get<{ data?: { data?: Term[] } }>(`/api/admin/search-seo/search-terms${q ? `?q=${encodeURIComponent(q)}` : ""}`).then((r) => setItems((r.data as { data?: { data?: Term[] } }).data?.data ?? [])).catch(setError).finally(() => setLoading(false));
  };
  useEffect(load, []);
  return <Card><CardHeader><CardTitle>Search Terms (auto logged from /api/products?q=)</CardTitle></CardHeader><CardContent className="space-y-3">
    <div className="flex gap-2"><Input placeholder="Search term" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-xs" /><Button variant="outline" onClick={load}>Search</Button></div>
    {loading ? <p>Loading…</p> : error ? <ApiState error={error} onRetry={load} /> : items.length ? <div className="space-y-2">{items.map((t) => <div key={t.id} className="flex justify-between rounded-md border p-3 text-sm"><span>{t.term}</span><span className="text-muted-foreground">hits {t.hits} · misses {t.misses}</span></div>)}</div> : <p className="text-sm text-muted-foreground">No terms yet. Search on storefront to generate.</p>}
  </CardContent></Card>;
}

function SynonymsTab() {
  const [items, setItems] = useState<Synonym[]>([]);
  const [term, setTerm] = useState("");
  const [syns, setSyns] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const load = () => { setLoading(true); api.get<{ data?: { data?: Synonym[] } }>("/api/admin/search-seo/synonyms").then((r) => setItems((r.data as { data?: { data?: Synonym[] } }).data?.data ?? [])).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);
  const create = async () => {
    const arr = syns.split(",").map((s) => s.trim()).filter(Boolean);
    try { await api.post("/api/admin/search-seo/synonyms", { term: term.trim(), synonyms: arr }, { idempotencyKey: idempotencyKey() }); setTerm(""); setSyns(""); load(); } catch (e) { setError(e); }
  };
  const remove = async (id: string) => { if (!confirm("Delete synonym?")) return; try { await api.delete(`/api/admin/search-seo/synonyms/${encodeURIComponent(id)}`); load(); } catch (e) { setError(e); } };
  return <Card><CardHeader><CardTitle>Search Synonyms</CardTitle></CardHeader><CardContent className="space-y-3">
    <div className="grid gap-2 md:grid-cols-3">
      <div><Label>Term</Label><Input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="áo" /></div>
      <div><Label>Synonyms (comma)</Label><Input value={syns} onChange={(e) => setSyns(e.target.value)} placeholder="shirt, ao" /></div>
      <div className="flex items-end"><Button onClick={create} disabled={!term.trim() || !syns.trim()}>Create</Button></div>
    </div>
    {loading ? <p>Loading…</p> : error ? <ApiState error={error} onRetry={load} /> : items.length ? <div className="space-y-2">{items.map((s) => <div key={s.id} className="flex items-center justify-between rounded-md border p-3 text-sm"><span>{s.term} → {s.synonyms.join(", ")}</span><Button size="sm" variant="outline" onClick={() => remove(s.id)}>Delete</Button></div>)}</div> : <p className="text-sm text-muted-foreground">No synonyms.</p>}
  </CardContent></Card>;
}

function SitemapsTab() {
  const [settings, setSettings] = useState<SitemapSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const [notice, setNotice] = useState("");
  const load = () => { setLoading(true); api.get<{ data?: { settings?: SitemapSettings } }>("/api/admin/search-seo/sitemaps").then((r) => setSettings((r.data as { data?: { settings?: SitemapSettings } }).data?.settings ?? null)).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, []);
  const save = async () => {
    if (!settings) return;
    try { await api.put("/api/admin/search-seo/sitemaps", settings); setNotice("Sitemap settings saved"); } catch (e) { setError(e); }
  };
  const generate = async () => {
    try { await api.post("/api/admin/search-seo/sitemaps/generate", {}, { idempotencyKey: idempotencyKey() }); setNotice("Sitemap generated"); } catch (e) { setError(e); }
  };
  if (loading) return <p>Loading…</p>;
  if (error) return <ApiState error={error} onRetry={load} />;
  if (!settings) return <p>No settings</p>;
  return <Card><CardHeader><CardTitle>Sitemaps</CardTitle></CardHeader><CardContent className="space-y-3">
    <div className="grid gap-3 md:grid-cols-2">
      {(["products", "categories", "cms_pages", "brands"] as const).map((k) => (
        <div key={k} className="rounded-md border p-3 space-y-2">
          <h4 className="font-medium capitalize">{k.replace("_", " ")}</h4>
          <Label>Frequency</Label><select value={settings[k].frequency} onChange={(e) => setSettings({ ...settings, [k]: { ...settings[k], frequency: e.target.value } })} className="h-9 w-full rounded-md border px-3 text-sm"><option value="daily">daily</option><option value="weekly">weekly</option><option value="monthly">monthly</option><option value="yearly">yearly</option></select>
          <Label>Priority</Label><Input type="number" min="0" max="1" step="0.1" value={settings[k].priority} onChange={(e) => setSettings({ ...settings, [k]: { ...settings[k], priority: Number(e.target.value) } })} />
        </div>
      ))}
    </div>
    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.images_enabled} onChange={(e) => setSettings({ ...settings, images_enabled: e.target.checked })} /> Include images</label>
    <div className="flex gap-2"><Button onClick={save}>Save settings</Button><Button variant="outline" onClick={generate}>Generate sitemap</Button><a href="/sitemap.xml" target="_blank" className="text-sm underline">View /sitemap.xml</a></div>
    {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}
  </CardContent></Card>;
}
