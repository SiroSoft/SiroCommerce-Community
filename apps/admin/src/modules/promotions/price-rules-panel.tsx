"use client";

import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/confirm-dialog";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiState } from "@/modules/merchant/api-state";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import {
  useCreatePromotionRule,
  useDeletePromotionRule,
  usePromotionRules,
  useUpdatePromotionRule,
} from "@/hooks/use-promotion-rules";
import { useCategories } from "@/hooks/use-categories";
import { useAuthStore } from "@/store/auth.store";
import type { PromotionRule, PromotionRuleInput, PromotionRuleKind } from "@/services/promotion-rules.service";

type FormState = {
  name: string;
  kind: PromotionRuleKind;
  value: string;
  minSubtotal: string;
  categories: string[];
  startsAt: string;
  endsAt: string;
  maxUses: string;
  state: "active" | "disabled";
};

const EMPTY_FORM: FormState = {
  name: "",
  kind: "cart_percent",
  value: "10",
  minSubtotal: "",
  categories: [],
  startsAt: "",
  endsAt: "",
  maxUses: "",
  state: "active",
};

function localDateTime(value: unknown): string {
  if (typeof value !== "string" || value.trim() === "") return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : date.toISOString().slice(0, 16);
}

function isoOrNull(value: string): string | null {
  if (value.trim() === "") return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function formOf(rule: PromotionRule): FormState {
  return {
    name: rule.name,
    kind: rule.kind,
    value: String(rule.value ?? ""),
    minSubtotal: rule.min_subtotal_minor != null ? String(rule.min_subtotal_minor) : "",
    categories: Array.isArray(rule.category_slugs) ? rule.category_slugs : [],
    startsAt: localDateTime(rule.starts_at),
    endsAt: localDateTime(rule.ends_at),
    maxUses: rule.max_uses != null ? String(rule.max_uses) : "",
    state: rule.state === "disabled" ? "disabled" : "active",
  };
}

function payloadOf(form: FormState): PromotionRuleInput {
  return {
    name: form.name.trim(),
    kind: form.kind,
    value: Number(form.value),
    min_subtotal_minor: form.minSubtotal.trim() === "" ? null : Number(form.minSubtotal),
    category_slugs: form.categories,
    starts_at: isoOrNull(form.startsAt),
    ends_at: isoOrNull(form.endsAt),
    max_uses: form.maxUses.trim() === "" ? null : Number(form.maxUses),
    state: form.state,
  };
}

function kindLabel(kind: string): string {
  if (kind === "cart_fixed") return "Cart fixed";
  if (kind === "catalog_percent") return "Catalog percent";
  return "Cart percent";
}

export function PriceRulesPanel() {
  const user = useAuthStore((state) => state.user);
  const canWrite = canOperateMerchant(user, "coupons");
  const { rules, isLoading, isError, error, refetch } = usePromotionRules();
  const { categories } = useCategories({ per_page: 100 });
  const createRule = useCreatePromotionRule();
  const updateRule = useUpdatePromotionRule();
  const deleteRule = useDeletePromotionRule();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<PromotionRule | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formError, setFormError] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<PromotionRule | null>(null);

  useEffect(() => {
    if (!dialogOpen) return;
    setForm(editing ? formOf(editing) : EMPTY_FORM);
    setFormError(null);
  }, [dialogOpen, editing]);

  const openCreate = () => { setEditing(null); setDialogOpen(true); };
  const openEdit = (rule: PromotionRule) => { setEditing(rule); setDialogOpen(true); };

  const submit = async () => {
    if (!canWrite) return;
    const percentKind = form.kind !== "cart_fixed";
    const value = Number(form.value);
    if (form.name.trim() === "") return setFormError("Name is required.");
    if (!Number.isInteger(value) || (percentKind ? value < 1 || value > 90 : value < 1 || value > 1000000000)) {
      return setFormError(percentKind ? "Percent must be an integer from 1 to 90." : "Fixed value must be 1..1000000000 minor.");
    }
    if (form.startsAt && form.endsAt && new Date(form.startsAt) >= new Date(form.endsAt)) {
      return setFormError("Start must be before end.");
    }
    if (form.maxUses.trim() !== "" && (!Number.isInteger(Number(form.maxUses)) || Number(form.maxUses) < 1)) {
      return setFormError("Max uses must be at least 1.");
    }
    setFormError(null);
    try {
      if (editing) {
        await updateRule.mutateAsync({ id: editing.id, data: payloadOf(form) });
      } else {
        await createRule.mutateAsync(payloadOf(form));
      }
      setDialogOpen(false);
      setEditing(null);
    } catch (cause) {
      setFormError(cause instanceof Error ? cause.message : "The rule was not accepted.");
    }
  };

  const busy = createRule.isPending || updateRule.isPending || deleteRule.isPending;

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-3">
        <div>
          <CardTitle>Price rules</CardTitle>
          <p className="text-xs text-muted-foreground">Best single rule wins. Rules never stack, and shipping/tax are never changed.</p>
        </div>
        <Button onClick={openCreate} disabled={!canWrite || busy}>
          <Plus className="mr-1 h-4 w-4" /> New rule
        </Button>
      </CardHeader>
      <CardContent className="space-y-3">
        {isError ? <ApiState error={error} onRetry={() => refetch()} /> : null}
        {isLoading ? <p role="status">Loading price rules...</p> : null}
        {!isLoading && !isError && rules.length === 0 ? <p className="text-sm text-muted-foreground">No price rules configured.</p> : null}
        {!isLoading && !isError && rules.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="px-2 py-2">Name</th>
                  <th className="px-2 py-2">Kind</th>
                  <th className="px-2 py-2">Value</th>
                  <th className="px-2 py-2">Window</th>
                  <th className="px-2 py-2">Uses</th>
                  <th className="px-2 py-2">State</th>
                  <th className="px-2 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rules.map((rule) => (
                  <tr key={rule.id} className="border-b last:border-0">
                    <td className="px-2 py-2">
                      <p className="font-medium">{rule.name}</p>
                      {rule.min_subtotal_minor != null ? <p className="text-xs text-muted-foreground">Min {rule.min_subtotal_minor} minor</p> : null}
                    </td>
                    <td className="px-2 py-2">{kindLabel(rule.kind)}</td>
                    <td className="px-2 py-2">{rule.kind === "cart_fixed" ? `${rule.value} minor` : `${rule.value}%`}</td>
                    <td className="px-2 py-2 text-xs text-muted-foreground">{rule.starts_at ?? "any"} → {rule.ends_at ?? "any"}</td>
                    <td className="px-2 py-2">{rule.uses ?? 0}{rule.max_uses != null ? ` / ${rule.max_uses}` : ""}</td>
                    <td className="px-2 py-2"><Badge variant={rule.state === "active" ? "default" : "outline"}>{rule.state}</Badge></td>
                    <td className="px-2 py-2">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="icon" aria-label={`Edit ${rule.name}`} disabled={!canWrite || busy} onClick={() => openEdit(rule)}>
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" aria-label={`Delete ${rule.name}`} disabled={!canWrite || busy} onClick={() => setDeleteTarget(rule)}>
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {!canWrite ? <p role="alert" className="text-sm text-muted-foreground">Read-only: your role cannot change price rules.</p> : null}
      </CardContent>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>{editing ? "Edit price rule" : "Create price rule"}</DialogTitle>
            <DialogDescription>Values are validated by the backend; percent rules must be 1..90.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-3 md:grid-cols-2">
            <div className="md:col-span-2">
              <Label htmlFor="rule-name">Name *</Label>
              <Input id="rule-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="rule-kind">Kind</Label>
              <select id="rule-kind" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as PromotionRuleKind })} disabled={busy} className="h-9 w-full rounded-md border bg-background px-3 text-sm">
                <option value="cart_percent">Cart percent</option>
                <option value="cart_fixed">Cart fixed (minor)</option>
                <option value="catalog_percent">Catalog percent</option>
              </select>
            </div>
            <div>
              <Label htmlFor="rule-value">Value *</Label>
              <Input id="rule-value" type="number" min={1} max={form.kind === "cart_fixed" ? 1000000000 : 90} value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="rule-min">Min subtotal (minor)</Label>
              <Input id="rule-min" type="number" min={0} value={form.minSubtotal} onChange={(e) => setForm({ ...form, minSubtotal: e.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="rule-max">Max uses</Label>
              <Input id="rule-max" type="number" min={1} value={form.maxUses} onChange={(e) => setForm({ ...form, maxUses: e.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="rule-start">Starts at</Label>
              <Input id="rule-start" type="datetime-local" value={form.startsAt} onChange={(e) => setForm({ ...form, startsAt: e.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="rule-end">Ends at</Label>
              <Input id="rule-end" type="datetime-local" value={form.endsAt} onChange={(e) => setForm({ ...form, endsAt: e.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="rule-state">State</Label>
              <select id="rule-state" value={form.state} onChange={(e) => setForm({ ...form, state: e.target.value as "active" | "disabled" })} disabled={busy} className="h-9 w-full rounded-md border bg-background px-3 text-sm">
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
              </select>
            </div>
            <div className="md:col-span-2">
              <Label>Categories (empty = all)</Label>
              <div className="mt-1 max-h-40 space-y-1 overflow-y-auto rounded-md border p-2">
                {categories.length === 0 ? <p className="text-xs text-muted-foreground">No categories returned.</p> : categories.map((category) => {
                  const slug = String(category.slug ?? "");
                  if (slug === "") return null;
                  return (
                    <label key={String(category.id ?? slug)} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={form.categories.includes(slug)}
                        onChange={(e) => setForm({ ...form, categories: e.target.checked ? [...form.categories, slug] : form.categories.filter((item) => item !== slug) })}
                        disabled={busy}
                      />
                      {String(category.name ?? slug)}
                    </label>
                  );
                })}
              </div>
            </div>
          </div>
          {formError ? <p role="alert" className="text-sm text-destructive">{formError}</p> : null}
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={busy}>Cancel</Button>
            <Button onClick={submit} disabled={!canWrite || busy}>{busy ? "Saving..." : editing ? "Save rule" : "Create rule"}</Button>
          </div>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Delete price rule?"
        description={deleteTarget ? `${deleteTarget.name} will stop participating in quotes immediately.` : undefined}
        isPending={deleteRule.isPending}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={() => {
          const target = deleteTarget;
          setDeleteTarget(null);
          if (target) deleteRule.mutate(target.id);
        }}
      />
    </Card>
  );
}
