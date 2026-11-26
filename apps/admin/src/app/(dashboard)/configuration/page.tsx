"use client";

import { useEffect, useMemo, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { ApiState } from "@/modules/merchant/api-state";
import api from "@/services/api";
import { idempotencyKey } from "@/services/merchant.service";

type Field = { key: string; type: string; label: string; default?: unknown; required?: boolean; max?: number; options?: string[] };
type Section = { key: string; name: string; fields: Field[] };
type Group = { key: string; name: string; sections: Section[] };

export default function ConfigurationPage() {
  const [groups, setGroups] = useState<Group[]>([]);
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [query, setQuery] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const [busyKey, setBusyKey] = useState<string>("");
  const [notice, setNotice] = useState<string>("");

  const load = () => {
    setLoading(true); setError(undefined);
    api.get<{ data?: { groups?: Group[]; values?: Record<string, unknown> } }>("/api/admin/configuration")
      .then((res) => {
        const data = (res.data as { data?: { groups?: Group[]; values?: Record<string, unknown> } }).data;
        setGroups(data?.groups ?? []);
        setValues(data?.values ?? {});
      })
      .catch(setError)
      .finally(() => setLoading(false));
  };
  useEffect(load, []);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return groups;
    return groups.map((g) => {
      const sections = g.sections.filter((s) =>
        s.name.toLowerCase().includes(q) ||
        s.key.toLowerCase().includes(q) ||
        g.name.toLowerCase().includes(q) ||
        g.key.toLowerCase().includes(q) ||
        s.fields.some((f) => f.label.toLowerCase().includes(q) || f.key.toLowerCase().includes(q))
      ).map((s) => ({
        ...s,
        fields: s.fields.filter((f) => !q || f.label.toLowerCase().includes(q) || f.key.toLowerCase().includes(q) || s.name.toLowerCase().includes(q) || s.key.toLowerCase().includes(q)),
      })).filter((s) => s.fields.length > 0);
      // keep group if any section matches or group name matches
      if (sections.length === 0 && !(g.name.toLowerCase().includes(q) || g.key.toLowerCase().includes(q))) return null;
      return { ...g, sections: sections.length ? sections : g.sections };
    }).filter(Boolean) as Group[];
  }, [groups, query]);

  const saveSection = async (groupKey: string, sectionKey: string, section: Section) => {
    const key = `${groupKey}.${sectionKey}`;
    setBusyKey(key); setError(undefined); setNotice("");
    // collect values from inputs (we read from values state, but need to gather current inputs)
    // Instead, read from DOM? Simpler: collect from values state that is updated via inputs
    const payload: Record<string, unknown> = {};
    for (const f of section.fields) {
      const full = `${groupKey}.${sectionKey}.${f.key}`;
      if (full in values) payload[f.key] = values[full];
    }
    try {
      // fetch current version for optimistic
      const cur = await api.get<{ data?: { version?: number } }>(`/api/admin/configuration/${encodeURIComponent(groupKey)}/${encodeURIComponent(sectionKey)}`);
      const version = (cur.data as { data?: { version?: number } }).data?.version ?? 0;
      const res = await api.put<{ data?: { values?: Record<string, unknown>; version?: number } }>(`/api/admin/configuration/${encodeURIComponent(groupKey)}/${encodeURIComponent(sectionKey)}`, { values: payload, expected_version: version }, { idempotencyKey: idempotencyKey() });
      const data = (res.data as { data?: { values?: Record<string, unknown> } }).data;
      if (data?.values) {
        const next = { ...values };
        for (const [k, v] of Object.entries(data.values)) next[`${groupKey}.${sectionKey}.${k}`] = v;
        // also merge returned flat values (controller returns values as {field: value} under data.values? our controller returns {values: merged, version} where merged is {field: value} but we store as full key)
        // Actually update from server's merged values
        setValues((prev) => {
          const copy = { ...prev };
          for (const [k, v] of Object.entries(payload)) copy[`${groupKey}.${sectionKey}.${k}`] = v;
          return copy;
        });
      }
      setNotice(`Saved ${groupKey}.${sectionKey}`);
      load();
    } catch (e) { setError(e); } finally { setBusyKey(""); }
  };

  if (loading) return <div role="status">Loading configuration…</div>;
  if (error) return <ApiState error={error} onRetry={load} />;

  return (
    <div className="space-y-6">
      <PageHeader title="Configuration" description="Bagisto-style groups → sections → fields. Search to filter. Custom section demo: Custom → Promo Banner (no fork)." />
      <div className="flex gap-2">
        <Input placeholder="Search groups, sections, fields…" value={query} onChange={(e) => setQuery(e.target.value)} className="max-w-md" aria-label="Search configuration" />
        <Button variant="outline" onClick={load}>Reload</Button>
      </div>
      {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}
      {filtered.map((group) => (
        <Card key={group.key}>
          <CardHeader><CardTitle className="flex items-center gap-2">{group.name} <Badge variant="outline">{group.key}</Badge></CardTitle></CardHeader>
          <CardContent className="space-y-6">
            {group.sections.map((section) => (
              <div key={section.key} className="rounded-md border p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="font-medium">{section.name} <span className="text-xs text-muted-foreground">({group.key}.{section.key})</span></h3>
                  <Button size="sm" onClick={() => saveSection(group.key, section.key, section)} disabled={busyKey === `${group.key}.${section.key}`}>{busyKey === `${group.key}.${section.key}` ? "Saving…" : "Save"}</Button>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  {section.fields.map((field) => {
                    const fullKey = `${group.key}.${section.key}.${field.key}`;
                    const val = values[fullKey] ?? field.default ?? (field.type === "boolean" ? false : "");
                    const common = { id: fullKey, disabled: busyKey === `${group.key}.${section.key}` };
                    return (
                      <div key={field.key} className="space-y-1">
                        <Label htmlFor={fullKey}>{field.label} {field.required && <span className="text-destructive">*</span>}</Label>
                        {field.type === "boolean" ? (
                          <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={Boolean(val)} onChange={(e) => setValues((prev) => ({ ...prev, [fullKey]: e.target.checked }))} disabled={common.disabled} />
                            {String(field.label)}
                          </label>
                        ) : field.type === "select" ? (
                          <select id={fullKey} value={String(val ?? "")} onChange={(e) => setValues((prev) => ({ ...prev, [fullKey]: e.target.value }))} disabled={common.disabled} className="h-9 w-full rounded-md border bg-background px-3 text-sm">
                            {(field.options ?? []).map((opt) => <option key={opt} value={opt}>{opt}</option>)}
                          </select>
                        ) : field.type === "textarea" ? (
                          <Textarea id={fullKey} value={String(val ?? "")} onChange={(e) => setValues((prev) => ({ ...prev, [fullKey]: e.target.value }))} disabled={common.disabled} />
                        ) : field.type === "number" ? (
                          <Input id={fullKey} type="number" value={String(val ?? "")} onChange={(e) => setValues((prev) => ({ ...prev, [fullKey]: e.target.value === "" ? "" : Number(e.target.value) }))} disabled={common.disabled} />
                        ) : (
                          <Input id={fullKey} value={String(val ?? "")} onChange={(e) => setValues((prev) => ({ ...prev, [fullKey]: e.target.value }))} disabled={common.disabled} placeholder={String(field.default ?? "")} />
                        )}
                        {field.type === "image" && typeof val === "string" && val && <img src={val} alt="" className="h-12 w-auto rounded border" />}
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
      {filtered.length === 0 && <p className="text-sm text-muted-foreground">No groups match “{query}”.</p>}
    </div>
  );
}
