"use client";

import { useEffect, useState } from "react";
import { AlertTriangle, ArrowDown, ArrowUp, Download, Eye, History, Plus, RotateCcw, Save, Send, Trash2, Upload } from "lucide-react";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { ErrorState } from "@/components/error-state";
import { EmptyState } from "@/components/empty-state";
import { cn } from "@/lib/utils";
import { AdminApiError } from "@/services/api-errors";
import {
  storefrontThemeService,
  HOMEPAGE_SECTION_TYPES,
  HEADER_LAYOUT_OPTIONS,
  FOOTER_LAYOUT_OPTIONS,
  LIST_LAYOUT_OPTIONS,
  PAGE_LAYOUT_OPTIONS,
  CARD_VARIANT_OPTIONS,
  PURCHASE_VARIANT_OPTIONS,
  QUOTE_MODE_OPTIONS,
  QUOTE_MODE_LABELS,
  QUOTE_MODE_HINTS,
  DENSITY_OPTIONS,
  RADIUS_OPTIONS,
  CARD_SHAPE_OPTIONS,
  addHomepageSection,
  contrastRatio,
  moveHomepageSection,
  presetGallery,
  previewPresetConfig,
  validateThemeImport,
  validateThemePackage,
  type HomepageSection,
  type HomepageSectionType,
  type ThemeConfig,
  type ThemeTokens,
  type ThemeWrite,
} from "@/services/storefront-theme.service";
import { useApplyStorefrontPreset, usePreviewStorefrontTheme, usePublishStorefrontTheme, useRestoreStorefrontRevision, useSaveStorefrontTheme, useStorefrontRevisions, useStorefrontTheme, useStorefrontThemeOptions } from "@/hooks/use-storefront-theme";
import { useQuery } from "@tanstack/react-query";
import { scopedQueryKey } from "@/services/query-keys";

const colors = ["ink", "muted", "paper", "surface", "accent", "accent_dark"] as const;
const MIN_CONTRAST = 4.5;
const sectionLabel = (value: string) => value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
const operationKey = () => `${Date.now()}-${Math.random().toString(36).slice(2)}`;

function withChromeDefaults(config: ThemeConfig): ThemeConfig {
  return {
    ...config,
    theme: {
      ...config.theme,
      tokens: {
        ...config.theme.tokens,
        colors: { ...config.theme.tokens.colors },
        typography: { display_stack: "", body_stack: "", base_size: 16, ...config.theme.tokens.typography },
        shape: { ...config.theme.tokens.shape },
      },
    },
    brand: config.brand ?? { name: "", wordmark: "", logo_url: null, favicon_url: null },
    navigation: config.navigation ?? { menu_key: "primary", items: [] },
    header: config.header ?? { announcement: true, layout: "standard", sticky: false },
    footer: config.footer ?? { layout: "standard", show_help: true, show_policies: true, columns: [] },
    homepage: { sections: config.homepage?.sections ?? [] },
    presentation: {
      ...{ product_card_variant: "editorial", product_detail_variant: "editorial", purchase_variant: "standard" },
      ...(config.presentation ?? {}),
    },
    purchase: config.purchase ?? { quote_mode: "required" },
    policies: config.policies ?? { shipping_url: "", returns_url: "", support_url: "" },
    experiments: config.experiments ?? [],
  };
}

function toBody(state: ThemeConfig): ThemeWrite {
  return {
    expected_version: state.version,
    theme_key: state.theme.key,
    ...(state.theme.extends ? { theme_extends: state.theme.extends } : {}),
    vertical_key: state.vertical.key,
    brand: state.brand,
    tokens: state.theme.tokens,
    navigation: state.navigation,
    header: state.header,
    footer: state.footer,
    homepage: state.homepage,
    presentation: state.presentation,
    purchase: state.purchase,
    policies: state.policies,
    experiments: state.experiments,
  };
}

export function Builder({ config }: { config: ThemeConfig }) {
  const [draft, setDraft] = useState(() => withChromeDefaults(config));
  const [preview, setPreview] = useState<ThemeConfig | null>(null);
  const [selectedRevision, setSelectedRevision] = useState("");
  const [newSectionType, setNewSectionType] = useState<HomepageSectionType>("campaign_hero");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [confirmation, setConfirmation] = useState<{ title: string; description: string; run: () => void } | null>(null);
  const options = useStorefrontThemeOptions();
  const revisions = useStorefrontRevisions();
  const save = useSaveStorefrontTheme();
  const previewMutation = usePreviewStorefrontTheme();
  const applyPreset = useApplyStorefrontPreset();
  const publish = usePublishStorefrontTheme();
  const restore = useRestoreStorefrontRevision();
  const diff = useQuery({
    queryKey: scopedQueryKey("storefront-theme", "diff", selectedRevision),
    queryFn: () => {
      const index = revisions.data?.findIndex((item) => item.id === selectedRevision) ?? -1;
      const previous = index >= 0 ? revisions.data?.[index + 1] : undefined;
      return previous ? storefrontThemeService.diff(previous.id, selectedRevision) : Promise.resolve({ changes: [] });
    },
    enabled: Boolean(selectedRevision),
  });

  useEffect(() => setDraft(withChromeDefaults(config)), [config]);
  const update = (change: (current: ThemeConfig) => ThemeConfig) => { setDraft((current) => change(current)); setMessage(null); setError(null); };
  const body = () => toBody(draft);
  const run = (action: () => void) => { setError(null); setMessage(null); action(); };
  const confirmRun = (title: string, question: string, action: () => void) => { setConfirmation({ title, description: question, run: () => run(action) }); };
  const onError = (reason: unknown) => setError(reason instanceof AdminApiError ? reason : new AdminApiError({ message: "The theme operation failed." }));
  const requireBrand = (): boolean => {
    if (draft.brand.name.trim()) return true;
    setError(new AdminApiError({ message: "Brand name is required before saving." }));
    return false;
  };
  const onSuccess = (value: unknown, text: string) => { if (value && typeof value === "object" && "version" in value) setDraft(withChromeDefaults(value as ThemeConfig)); setMessage(text); setError(null); };
  const setToken = (group: keyof ThemeTokens, key: string, value: string) => update((current) => ({ ...current, theme: { ...current.theme, tokens: { ...current.theme.tokens, [group]: { ...current.theme.tokens[group], [key]: value } } } }));
  const resetTokens = () => {
    const original = withChromeDefaults(config).theme.tokens;
    update((current) => ({ ...current, theme: { ...current.theme, tokens: structuredClone(original) } }));
    setMessage("Tokens reset to the saved preset values.");
  };

  const presets = (options.data?.[1] ?? []).map(presetGallery);
  const selectedPreset = presets.find((preset) => preset.key === draft.vertical.key);
  const inkPaperContrast = contrastRatio(draft.theme.tokens.colors.ink, draft.theme.tokens.colors.paper);
  const orderedSections = draft.homepage.sections.slice().sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));

  const selectPreset = (presetKey: string) => {
    const preset = presets.find((item) => item.key === presetKey);
    const next = { ...draft, vertical: { ...draft.vertical, key: presetKey } };
    update(() => next);
    const previewConfig = preset ? previewPresetConfig(draft, preset.preset) : next;
    previewMutation.mutate(toBody(previewConfig), {
      onSuccess: (value) => { if (value && typeof value === "object" && "version" in value) setPreview(value as ThemeConfig); },
      onError: () => setMessage("Preset selected. Preview is unavailable, but you can still apply it."),
    });
  };

  const exportDraft = () => {
    const payload = { ...draft, exported_at: new Date().toISOString() };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `theme-${draft.theme.key}-v${draft.version}.json`;
    link.click();
    URL.revokeObjectURL(url);
    setMessage("Theme draft exported.");
  };

  const importDraft = (file: File) => {
    void file.text().then((text) => {
      let parsed: unknown;
      try {
        parsed = JSON.parse(text);
      } catch {
        throw new AdminApiError({ message: "Import file is not valid JSON." });
      }
      const result = validateThemeImport(parsed);
      if (!result.ok) {
        onError(new AdminApiError({ message: result.error }));
        return;
      }
      update((current) => ({ ...withChromeDefaults(result.config), version: current.version }));
      setMessage("Imported into the draft. Review the changes, then save when ready.");
    }).catch(onError);
  };

  const importThemePackage = (file: File) => {
    void file.text().then(async (text) => {
      let parsed: unknown;
      try {
        parsed = JSON.parse(text);
      } catch {
        throw new AdminApiError({ message: "Theme package is not valid JSON." });
      }
      const result = validateThemePackage(parsed);
      if (!result.ok) {
        onError(new AdminApiError({ message: result.error }));
        return;
      }
      const saved = await storefrontThemeService.importThemePackage(result.package, draft.version, operationKey());
      onSuccess(saved, "Siro theme package imported as draft. Review, then save or publish.");
    }).catch(onError);
  };

  const importThemeArchive = (file: File) => {
    void storefrontThemeService.importThemeArchive(file, draft.version, operationKey())
      .then((saved) => onSuccess(saved, "Theme archive imported as draft with local assets. Review, then save or publish."))
      .catch(onError);
  };

  const exportThemePackage = () => {
    void storefrontThemeService.exportThemePackage().then((value) => {
      const packageRecord = value as Record<string, unknown>;
      const blob = new Blob([JSON.stringify(packageRecord, null, 2)], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      const id = typeof packageRecord.id === "string" ? packageRecord.id : draft.theme.key;
      const version = typeof packageRecord.version === "string" ? packageRecord.version : "1.0.0";
      link.download = `${id.replace("/", "-")}-v${version}.siro-theme.json`;
      link.click();
      URL.revokeObjectURL(url);
      setMessage("Siro theme package exported.");
    }).catch(onError);
  };

  const exportThemeArchive = () => {
    void storefrontThemeService.exportThemeArchive().then(({ blob, filename }) => {
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);
      setMessage("Siro theme archive exported with local assets.");
    }).catch(onError);
  };

  return (
    <div className="space-y-6" data-tour="theme-builder">
      <PageHeader title="Theme builder" description={`Editing ${draft.scope.store_id} / ${draft.scope.channel_id} / ${draft.scope.locale} / ${draft.scope.currency}`}>
        <Badge variant="outline">Draft v{draft.version}</Badge>
        {draft.theme.extends ? <Badge variant="secondary">Child theme · {draft.theme.extends}</Badge> : null}
      </PageHeader>
      {error?.kind === "conflict" && <div role="alert" className="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"><AlertTriangle className="h-4 w-4" />This draft is stale. Reload the latest version before saving.</div>}
      {error && error.kind !== "conflict" && <div role="alert" className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">{error.kind === "permission" ? "You do not have permission to edit storefront themes." : error.message}</div>}
      {message && <div role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900">{message}</div>}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-6">
          <Card><CardHeader><CardTitle>Theme identity</CardTitle><CardDescription>Custom key creates a new theme; extends makes it a child of a preset.</CardDescription></CardHeader><CardContent className="grid gap-4 sm:grid-cols-2">
            <Field label="Theme key" value={draft.theme.key} onChange={(value) => update((current) => ({ ...current, theme: { ...current.theme, key: value } }))} />
            <Field label="Extends (parent preset, optional)" value={draft.theme.extends ?? ""} onChange={(value) => update((current) => ({ ...current, theme: { ...current.theme, extends: value || undefined } }))} />
          </CardContent></Card>

          <Card>
            <CardHeader><CardTitle>Preset gallery</CardTitle><CardDescription>Pick a vertical starting point. Selecting a card previews it; applying replaces the current draft.</CardDescription></CardHeader>
            <CardContent className="space-y-4">
              {options.isLoading && <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><Skeleton className="h-40 w-full" /><Skeleton className="h-40 w-full" /><Skeleton className="h-40 w-full" /></div>}
              <div role="group" aria-label="Vertical presets" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {presets.map((preset) => {
                  const selected = preset.key === draft.vertical.key;
                  return (
                    <button
                      key={preset.key}
                      type="button"
                      aria-pressed={selected}
                      aria-label={`${preset.name} preset`}
                      onClick={() => selectPreset(preset.key)}
                      className={cn(
                        "w-full rounded-lg border p-3 text-left transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                        selected && "border-primary ring-2 ring-primary/30",
                      )}
                    >
                      <div className="flex items-center justify-between gap-2"><span className="text-sm font-semibold">{preset.name}</span>{selected ? <Badge variant="secondary">Selected</Badge> : null}</div>
                      <p className="mt-1 text-xs text-muted-foreground">{preset.description}</p>
                      <div className="mt-3 flex gap-1" aria-hidden="true">{preset.swatches.map((swatch, index) => <span key={`${preset.key}-${swatch}-${index}`} className="h-5 w-5 rounded-full border" style={{ backgroundColor: swatch }} />)}</div>
                      {preset.layout_hints.length > 0 && <ul className="mt-2 space-y-0.5 text-xs text-muted-foreground">{preset.layout_hints.map((hint) => <li key={hint}>· {hint}</li>)}</ul>}
                    </button>
                  );
                })}
              </div>
              {presets.length === 0 && !options.isLoading && <p className="text-sm text-muted-foreground">No vertical presets are available.</p>}
              <div className="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 sm:flex-row sm:items-center sm:justify-between">
                <span className="flex items-center gap-2"><AlertTriangle className="h-4 w-4" />Applying a preset overwrites the current draft presentation and homepage defaults.</span>
                <Button variant="outline" size="sm" disabled={applyPreset.isPending || !draft.vertical.key} onClick={() => confirmRun("Apply preset", `Apply the "${selectedPreset?.name ?? draft.vertical.key}" preset? The current draft presentation and homepage defaults will be replaced.`, () => applyPreset.mutate({ expected_version: draft.version, vertical_key: draft.vertical.key, key: operationKey() }, { onSuccess: (value) => onSuccess(value, "Preset applied to draft."), onError }))}>{applyPreset.isPending ? "Applying..." : "Apply preset"}</Button>
              </div>
            </CardContent>
          </Card>

          <Card><CardHeader><CardTitle>Brand identity</CardTitle><CardDescription>Safe, presentation-only brand fields.</CardDescription></CardHeader><CardContent className="grid gap-4 sm:grid-cols-2">
            <Field label="Brand name" value={draft.brand.name} onChange={(value) => update((current) => ({ ...current, brand: { ...current.brand, name: value } }))} />
            <Field label="Wordmark" value={draft.brand.wordmark} onChange={(value) => update((current) => ({ ...current, brand: { ...current.brand, wordmark: value } }))} />
            <Field label="Logo URL" value={draft.brand.logo_url ?? ""} onChange={(value) => update((current) => ({ ...current, brand: { ...current.brand, logo_url: value || null } }))} />
            <Field label="Favicon URL" value={draft.brand.favicon_url ?? ""} onChange={(value) => update((current) => ({ ...current, brand: { ...current.brand, favicon_url: value || null } }))} />
          </CardContent></Card>

          <Card><CardHeader><CardTitle>Theme tokens</CardTitle><CardDescription>Colors are strict hex values; typography and shape use backend allowlists.</CardDescription></CardHeader><CardContent className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-sm text-muted-foreground">Tokens are inherited from the active preset until you override them.</span>
              <Button variant="outline" size="sm" onClick={resetTokens}><RotateCcw className="mr-2 h-4 w-4" />Reset tokens</Button>
            </div>
            {inkPaperContrast !== null && inkPaperContrast < MIN_CONTRAST && <div role="status" className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /><span>Low contrast between Ink and Paper ({inkPaperContrast.toFixed(2)}:1). WCAG AA needs at least {MIN_CONTRAST}:1 for body text.</span></div>}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{colors.map((color) => <div className="space-y-2" key={color}><Label htmlFor={`color-${color}`}>{sectionLabel(color)}</Label><div className="flex gap-2"><Input id={`color-${color}`} type="color" className="w-12 p-1" value={draft.theme.tokens.colors[color]} onChange={(event) => setToken("colors", color, event.target.value)} /><Input aria-label={`${color} hex`} value={draft.theme.tokens.colors[color]} onChange={(event) => setToken("colors", color, event.target.value)} /></div></div>)}</div>
            <div className="grid gap-4 sm:grid-cols-3"><SelectField label="Display font" value={draft.theme.tokens.typography.display} options={["editorial", "system"]} onChange={(value) => setToken("typography", "display", value)} /><SelectField label="Body font" value={draft.theme.tokens.typography.body} options={["system", "editorial"]} onChange={(value) => setToken("typography", "body", value)} /><SelectField label="Density" value={draft.theme.tokens.typography.density} options={[...DENSITY_OPTIONS]} onChange={(value) => setToken("typography", "density", value)} /></div>
            <div className="grid gap-4 sm:grid-cols-2"><Field label="Display font stack (CSS font-family)" value={draft.theme.tokens.typography.display_stack ?? ""} onChange={(value) => setToken("typography", "display_stack", value)} /><Field label="Body font stack" value={draft.theme.tokens.typography.body_stack ?? ""} onChange={(value) => setToken("typography", "body_stack", value)} /></div>
            <div className="grid gap-4 sm:grid-cols-3"><div className="space-y-2"><Label>Base size (px, 12-22)</Label><Input type="number" min={12} max={22} value={draft.theme.tokens.typography.base_size ?? 16} onChange={(event) => update((current) => ({ ...current, theme: { ...current.theme, tokens: { ...current.theme.tokens, typography: { ...current.theme.tokens.typography, base_size: Number(event.target.value) } } } }))} /></div><SelectField label="Radius" value={draft.theme.tokens.shape.radius} options={[...RADIUS_OPTIONS]} onChange={(value) => setToken("shape", "radius", value)} /><SelectField label="Card" value={draft.theme.tokens.shape.card} options={[...CARD_SHAPE_OPTIONS]} onChange={(value) => setToken("shape", "card", value)} /></div>
          </CardContent></Card>

          <Card><CardHeader><CardTitle>Presentation</CardTitle><CardDescription>Variants change display only and cannot change commerce truth.</CardDescription></CardHeader><CardContent className="grid gap-4 sm:grid-cols-3"><SelectField label="Product card" value={draft.presentation.product_card_variant} options={[...CARD_VARIANT_OPTIONS]} onChange={(value) => update((current) => ({ ...current, presentation: { ...current.presentation, product_card_variant: value } }))} /><SelectField label="Product detail" value={draft.presentation.product_detail_variant} options={[...PAGE_LAYOUT_OPTIONS]} onChange={(value) => update((current) => ({ ...current, presentation: { ...current.presentation, product_detail_variant: value } }))} /><SelectField label="Product list" value={draft.presentation.product_list_variant ?? "list-editorial"} options={[...LIST_LAYOUT_OPTIONS]} onChange={(value) => update((current) => ({ ...current, presentation: { ...current.presentation, product_list_variant: value } }))} /><SelectField label="Purchase" value={draft.presentation.purchase_variant} options={[...PURCHASE_VARIANT_OPTIONS]} onChange={(value) => update((current) => ({ ...current, presentation: { ...current.presentation, purchase_variant: value } }))} /><SelectField label="Quote mode" value={draft.purchase.quote_mode} options={[...QUOTE_MODE_OPTIONS]} labels={QUOTE_MODE_LABELS} onChange={(value) => update((current) => ({ ...current, purchase: { ...current.purchase, quote_mode: value as (typeof QUOTE_MODE_OPTIONS)[number] } }))} /><div className="rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground sm:col-span-3">{QUOTE_MODE_HINTS[draft.purchase.quote_mode]}</div></CardContent></Card>
          <Card><CardHeader><CardTitle>Header & footer</CardTitle><CardDescription>Chrome layout without touching markup.</CardDescription></CardHeader><CardContent className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3"><SelectField label="Header layout" value={draft.header.layout} options={[...HEADER_LAYOUT_OPTIONS]} onChange={(value) => update((current) => ({ ...current, header: { ...current.header, layout: value } }))} /><div className="flex items-end gap-4 pb-2"><label className="flex items-center gap-2 text-sm"><Switch checked={draft.header.announcement} onCheckedChange={(checked) => update((current) => ({ ...current, header: { ...current.header, announcement: checked } }))} /> Announcement</label><label className="flex items-center gap-2 text-sm"><Switch checked={draft.header.sticky} onCheckedChange={(checked) => update((current) => ({ ...current, header: { ...current.header, sticky: checked } }))} /> Sticky</label></div><SelectField label="Footer layout" value={draft.footer.layout} options={[...FOOTER_LAYOUT_OPTIONS]} onChange={(value) => update((current) => ({ ...current, footer: { ...current.footer, layout: value } }))} /></div>
            <div className="flex gap-4"><label className="flex items-center gap-2 text-sm"><Switch checked={draft.footer.show_help} onCheckedChange={(checked) => update((current) => ({ ...current, footer: { ...current.footer, show_help: checked } }))} /> Help links</label><label className="flex items-center gap-2 text-sm"><Switch checked={draft.footer.show_policies} onCheckedChange={(checked) => update((current) => ({ ...current, footer: { ...current.footer, show_policies: checked } }))} /> Policy links</label></div>
            <div className="space-y-2"><div className="flex items-center justify-between"><Label>Footer columns</Label><Button variant="outline" size="sm" onClick={() => update((current) => ({ ...current, footer: { ...current.footer, columns: [...current.footer.columns, { title: "", links: [] }] } }))}><Plus className="mr-2 h-4 w-4" />Add column</Button></div>
            {draft.footer.columns.map((column, i) => <div key={i} className="space-y-2 rounded-lg border p-3"><div className="flex items-center gap-2"><Input value={column.title} onChange={(event) => update((current) => ({ ...current, footer: { ...current.footer, columns: current.footer.columns.map((col, j) => j === i ? { ...col, title: event.target.value } : col) } }))} placeholder="Column title" maxLength={80} /><Button variant="ghost" size="icon" aria-label="Remove column" onClick={() => update((current) => ({ ...current, footer: { ...current.footer, columns: current.footer.columns.filter((_, j) => j !== i) } }))}><Trash2 className="h-4 w-4 text-destructive" /></Button></div><Textarea rows={3} value={column.links.map((l) => `${l.label} | ${l.href}`).join("\n")} onChange={(event) => update((current) => ({ ...current, footer: { ...current.footer, columns: current.footer.columns.map((col, j) => j === i ? { ...col, links: event.target.value.split("\n").map((line) => line.trim()).filter(Boolean).flatMap((line) => { const [label, ...rest] = line.split("|"); const href = rest.join("|").trim(); return label?.trim() && href ? [{ label: label.trim(), href }] : []; }) } : col) } }))} placeholder={"Sale | /sale"} /></div>)}
            </div>
          </CardContent></Card>
          <Card><CardHeader><CardTitle>Experiments</CardTitle><CardDescription>Scoped, presentation-only A/B configuration. The server rejects invalid, oversized, or unsafe variants.</CardDescription></CardHeader><CardContent><Label htmlFor="experiments-json">Allowlisted variants (JSON)</Label><Textarea id="experiments-json" className="mt-2 min-h-28 font-mono text-xs" value={JSON.stringify(draft.experiments ?? [], null, 2)} onChange={(event) => { try { const value = JSON.parse(event.target.value); if (Array.isArray(value) && value.length <= 50) update((current) => ({ ...current, experiments: value })); } catch { setError(new AdminApiError({ message: "Experiments must be valid JSON." })); } }} /><p className="mt-2 text-xs text-muted-foreground">Assignments are consent-gated and never change price, stock, payment, tax, or order truth.</p></CardContent></Card>

          <Card>
            <CardHeader><CardTitle>Homepage sections</CardTitle><CardDescription>Only registered section types can be added. Change order, enabled state, and basic copy without editing markup.</CardDescription></CardHeader>
            <CardContent className="space-y-3">
              {draft.homepage.sections.length === 0 && <EmptyState title="No homepage sections" description="Add an allowlisted section to compose the homepage." />}
              {orderedSections.map((section, index) => (
                <SectionRow
                  key={section.id}
                  section={section}
                  canMoveUp={index > 0}
                  canMoveDown={index < orderedSections.length - 1}
                  onChange={(next) => update((current) => ({ ...current, homepage: { sections: current.homepage.sections.map((item) => item.id === section.id ? next : item) } }))}
                  onMove={(direction) => update((current) => ({ ...current, homepage: { sections: moveHomepageSection(current.homepage.sections, section.id, direction) } }))}
                  onRemove={() => update((current) => ({ ...current, homepage: { sections: current.homepage.sections.filter((item) => item.id !== section.id) } }))}
                />
              ))}
              <div className="flex flex-wrap items-end gap-2">
                <div className="min-w-48 space-y-2">
                  <Label>New section type</Label>
                  <Select value={newSectionType} onValueChange={(value) => setNewSectionType(value as HomepageSectionType)}>
                    <SelectTrigger aria-label="New section type"><SelectValue /></SelectTrigger>
                    <SelectContent>{HOMEPAGE_SECTION_TYPES.map((type) => <SelectItem key={type} value={type}>{sectionLabel(type)}</SelectItem>)}</SelectContent>
                  </Select>
                </div>
                <Button variant="outline" onClick={() => update((current) => ({ ...current, homepage: { sections: addHomepageSection(current.homepage.sections, newSectionType) } }))}><Plus className="mr-2 h-4 w-4" />Add section</Button>
              </div>
            </CardContent>
          </Card>
        </div>

        <aside className="space-y-6">
          <Card><CardHeader><CardTitle>Workflow</CardTitle><CardDescription>Optimistic versioning protects concurrent edits.</CardDescription></CardHeader><CardContent className="space-y-2"><Button className="w-full" disabled={save.isPending || previewMutation.isPending || publish.isPending} onClick={() => { if (!requireBrand()) return; run(() => save.mutate(body(), { onSuccess: (value) => onSuccess(value, "Draft saved."), onError })); }}><Save className="mr-2 h-4 w-4" />{save.isPending ? "Saving..." : "Save draft"}</Button><div className="flex gap-2"><Button variant="outline" className="flex-1" disabled={save.isPending} onClick={() => run(exportDraft)}><Download className="mr-2 h-4 w-4" />Export</Button><label className="flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-md border px-3 py-2 text-center text-sm hover:bg-muted"><Upload className="h-4 w-4" />Import<input aria-label="Import theme draft JSON" type="file" accept="application/json,.json" className="sr-only" onChange={(event) => { const file = event.target.files?.[0]; if (file) importDraft(file); event.target.value = ""; }} /></label></div><label className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-md border px-3 py-2 text-center text-sm hover:bg-muted"><Upload className="h-4 w-4" />Import Siro theme<input aria-label="Import Siro theme package JSON" type="file" accept="application/json,.json" className="sr-only" onChange={(event) => { const file = event.target.files?.[0]; if (file) importThemePackage(file); event.target.value = ""; }} /></label><label className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-md border px-3 py-2 text-center text-sm hover:bg-muted"><Upload className="h-4 w-4" />Import theme archive (.zip)<input aria-label="Import Siro theme archive" type="file" accept=".zip,application/zip" className="sr-only" onChange={(event) => { const file = event.target.files?.[0]; if (file) importThemeArchive(file); event.target.value = ""; }} /></label><Button variant="outline" className="w-full" onClick={exportThemePackage}><Download className="mr-2 h-4 w-4" />Export Siro theme</Button><Button variant="outline" className="w-full" onClick={exportThemeArchive}><Download className="mr-2 h-4 w-4" />Export theme archive (.zip)</Button><Button variant="outline" className="w-full" disabled={previewMutation.isPending || save.isPending} onClick={() => run(() => previewMutation.mutate(body(), { onSuccess: (value) => { setPreview(value as ThemeConfig); onSuccess(value, "Preview generated."); }, onError }))}><Eye className="mr-2 h-4 w-4" />{previewMutation.isPending ? "Previewing..." : "Preview state"}</Button><Button variant="secondary" className="w-full" disabled={publish.isPending || save.isPending} onClick={() => confirmRun("Publish theme", `Publish theme draft v${draft.version}? The storefront will serve it immediately.`, () => publish.mutate({ expected_version: draft.version, key: operationKey() }, { onSuccess: (value) => onSuccess(value, "Theme published."), onError }))}><Send className="mr-2 h-4 w-4" />{publish.isPending ? "Publishing..." : "Publish"}</Button><p className="pt-1 text-xs text-muted-foreground">Import replaces the local draft only. It never saves or publishes until you choose.</p></CardContent></Card>
          {preview && <Card><CardHeader><CardTitle>Preview state</CardTitle><CardDescription>Unpublished v{preview.version} representation.</CardDescription></CardHeader><CardContent><div className="rounded-lg border p-4" style={{ backgroundColor: preview.theme.tokens.colors.paper, color: preview.theme.tokens.colors.ink }}><p className="text-xs uppercase tracking-widest opacity-70">{preview.brand.wordmark}</p><p className="mt-2 text-xl font-semibold">{preview.brand.name}</p><p className="mt-1 text-sm opacity-70">{preview.homepage.sections.filter((section) => section.enabled).length} enabled sections</p></div></CardContent></Card>}
          <RevisionPanel revisions={revisions.data ?? []} selected={selectedRevision} onSelect={setSelectedRevision} diff={diff.data?.changes ?? []} busy={restore.isPending} onRestore={(revision) => confirmRun("Restore revision", `Restore revision ${revision} as a new draft? Unsaved draft changes will be replaced.`, () => restore.mutate({ revision, expected_version: draft.version, key: operationKey() }, { onSuccess: (value) => onSuccess(value, "Revision restored as a new draft."), onError }))} />
        </aside>
      </div>
      <ConfirmDialog
        open={confirmation !== null}
        title={confirmation?.title ?? ""}
        description={confirmation?.description}
        isPending={applyPreset.isPending || restore.isPending || publish.isPending}
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

function Field({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) { return <div className="space-y-2"><Label>{label}</Label><Input value={value} onChange={(event) => onChange(event.target.value)} /></div>; }
function SelectField({ label, value, options, labels, onChange }: { label: string; value: string; options: string[]; labels?: Record<string, string>; onChange: (value: string) => void }) { return <div className="space-y-2"><Label>{label}</Label><Select value={value} onValueChange={onChange}><SelectTrigger aria-label={label}><SelectValue /></SelectTrigger><SelectContent>{options.map((option) => <SelectItem key={option} value={option}>{labels?.[option] ?? sectionLabel(option)}</SelectItem>)}</SelectContent></Select></div>; }

function SectionRow({ section, canMoveUp, canMoveDown, onChange, onMove, onRemove }: { section: HomepageSection; canMoveUp: boolean; canMoveDown: boolean; onChange: (section: HomepageSection) => void; onMove: (direction: "up" | "down") => void; onRemove: () => void }) {
  const setProp = (key: "title" | "subtitle" | "eyebrow", value: string) => onChange({ ...section, props: { ...section.props, [key]: value } });
  return (
    <div className="space-y-3 rounded-lg border p-3">
      <div className="grid gap-3 sm:grid-cols-[1fr_7rem_auto_auto_auto_auto] sm:items-end">
        <div className="space-y-1"><Label className="text-xs">Section</Label><Select value={section.type} onValueChange={(type) => onChange({ ...section, type: type as HomepageSection["type"] })}><SelectTrigger aria-label="Section type"><SelectValue /></SelectTrigger><SelectContent>{HOMEPAGE_SECTION_TYPES.map((type) => <SelectItem key={type} value={type}>{sectionLabel(type)}</SelectItem>)}</SelectContent></Select></div>
        <div className="space-y-1"><Label className="text-xs">Order</Label><Input aria-label="Section order" type="number" min={0} max={10000} value={section.order} onChange={(event) => onChange({ ...section, order: Math.min(10000, Math.max(0, Number(event.target.value))) })} /></div>
        <div className="flex items-center gap-2 pb-2"><Switch aria-label={`Enable ${sectionLabel(section.type)}`} checked={section.enabled} onCheckedChange={(enabled) => onChange({ ...section, enabled })} /><Label>Enabled</Label></div>
        <Button aria-label="Move section up" variant="ghost" size="icon" disabled={!canMoveUp} onClick={() => onMove("up")}><ArrowUp className="h-4 w-4" /></Button>
        <Button aria-label="Move section down" variant="ghost" size="icon" disabled={!canMoveDown} onClick={() => onMove("down")}><ArrowDown className="h-4 w-4" /></Button>
        <Button aria-label={`Remove ${sectionLabel(section.type)}`} variant="ghost" size="icon" onClick={onRemove}><Trash2 className="h-4 w-4 text-destructive" /></Button>
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="space-y-1"><Label className="text-xs">Title</Label><Input aria-label={`${sectionLabel(section.type)} title`} maxLength={120} value={section.props.title ?? ""} onChange={(event) => setProp("title", event.target.value)} /></div>
        <div className="space-y-1"><Label className="text-xs">Subtitle</Label><Input aria-label={`${sectionLabel(section.type)} subtitle`} maxLength={160} value={section.props.subtitle ?? ""} onChange={(event) => setProp("subtitle", event.target.value)} /></div>
        <div className="space-y-1"><Label className="text-xs">Eyebrow</Label><Input aria-label={`${sectionLabel(section.type)} eyebrow`} maxLength={80} value={section.props.eyebrow ?? ""} onChange={(event) => setProp("eyebrow", event.target.value)} /></div>
      </div>
    </div>
  );
}

function RevisionPanel({ revisions, selected, onSelect, diff, busy, onRestore }: { revisions: Array<{ id: string; version: number; action: string; created_at?: string }>; selected: string; onSelect: (id: string) => void; diff: Array<{ field: string; from: unknown; to: unknown }>; busy: boolean; onRestore: (id: string) => void }) { return <Card><CardHeader><CardTitle className="flex items-center gap-2"><History className="h-4 w-4" />Revisions</CardTitle><CardDescription>Restore creates a new revision; history is never deleted.</CardDescription></CardHeader><CardContent className="space-y-3">{revisions.length === 0 ? <EmptyState title="No revisions" description="Published and restored changes will appear here." /> : revisions.map((revision) => <div className="rounded-lg border p-3" key={revision.id}><div className="flex items-center justify-between gap-2"><span className="text-sm font-medium">v{revision.version} · {revision.action}</span><Button size="sm" variant="outline" disabled={busy} onClick={() => onRestore(revision.id)}>{busy ? "Restoring..." : "Restore"}</Button></div><p className="mt-1 text-xs text-muted-foreground">{revision.created_at ?? "Unknown time"}</p></div>)}{revisions.length > 1 && <div className="border-t pt-3"><Label>Compare revision</Label><Select value={selected} onValueChange={onSelect}><SelectTrigger className="mt-2"><SelectValue placeholder="Select revision" /></SelectTrigger><SelectContent>{revisions.map((revision) => <SelectItem key={revision.id} value={revision.id}>v{revision.version} · {revision.action}</SelectItem>)}</SelectContent></Select>{selected && <div className="mt-3 space-y-2 text-xs">{diff.length === 0 ? <p className="text-muted-foreground">No field changes.</p> : diff.map((change) => <div className="rounded border p-2" key={change.field}><strong>{change.field}</strong><div className="text-muted-foreground">{String(change.from)} → {String(change.to)}</div></div>)}</div>}</div>}</CardContent></Card>; }

export default function StorefrontThemePage() {
  const config = useStorefrontTheme();
  return <CapabilityGate capability="cms">{config.isLoading ? <div className="space-y-4"><Skeleton className="h-10 w-64" /><Skeleton className="h-64 w-full" /></div> : config.isError ? <ErrorState message={config.error instanceof Error ? config.error.message : "Unable to load the scoped theme configuration."} onRetry={() => config.refetch()} /> : config.data ? <Builder config={config.data} /> : <EmptyState title="Theme configuration is empty" description="The selected commerce scope has no theme configuration." />}</CapabilityGate>;
}
