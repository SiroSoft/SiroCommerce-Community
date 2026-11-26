"use client";

import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CapabilityGate } from "@/components/capability-gate";
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
import { PageHeader } from "@/components/page-header";
import { ApiState } from "@/modules/merchant/api-state";
import {
  useCreateTaxRule,
  useDeleteTaxRule,
  useTaxRules,
  useUpdateTaxRule,
} from "@/hooks/use-tax-rules";
import type { TaxRule, TaxRuleInput, TaxRuleState } from "@/services/tax-rules.service";

type FormState = {
  code: string;
  country: string;
  region: string;
  postalCode: string;
  taxClass: string;
  rateBasisPoints: string;
  priority: string;
  state: TaxRuleState;
  compound: boolean;
  effectiveAt: string;
  expiresAt: string;
};

const EMPTY_FORM: FormState = {
  code: "",
  country: "",
  region: "",
  postalCode: "",
  taxClass: "standard",
  rateBasisPoints: "1000",
  priority: "0",
  state: "active",
  compound: false,
  effectiveAt: "",
  expiresAt: "",
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

function formOf(rule: TaxRule): FormState {
  return {
    code: rule.code,
    country: rule.destination_country ?? "",
    region: rule.destination_region ?? "",
    postalCode: rule.destination_postal_code ?? "",
    taxClass: rule.tax_class,
    rateBasisPoints: String(rule.rate_basis_points ?? ""),
    priority: String(rule.priority ?? 0),
    state: rule.state === "disabled" ? "disabled" : "active",
    compound: Number(rule.compound) === 1,
    effectiveAt: localDateTime(rule.effective_at),
    expiresAt: localDateTime(rule.expires_at),
  };
}

function payloadOf(form: FormState): TaxRuleInput {
  return {
    code: form.code.trim(),
    destination_country: form.country.trim() === "" ? null : form.country.trim().toUpperCase(),
    destination_region: form.region.trim() === "" ? null : form.region.trim(),
    destination_postal_code: form.postalCode.trim() === "" ? null : form.postalCode.trim(),
    tax_class: form.taxClass.trim() || "standard",
    rate_basis_points: Number(form.rateBasisPoints),
    priority: Number(form.priority),
    state: form.state,
    compound: form.compound,
    effective_at: isoOrNull(form.effectiveAt),
    expires_at: isoOrNull(form.expiresAt),
  };
}

function formatRate(basisPoints: number): string {
  return `${(basisPoints / 100).toFixed(2).replace(/\.?0+$/, "")}%`;
}

function destinationLabel(rule: TaxRule): string {
  const parts = [rule.destination_country, rule.destination_region, rule.destination_postal_code].filter((part) => typeof part === "string" && part !== "");
  return parts.length > 0 ? parts.join(" / ") : "All destinations";
}

export default function TaxPage() {
  return (
    <CapabilityGate capability="finance">
      <TaxContent />
    </CapabilityGate>
  );
}

function TaxContent() {
  const [state, setState] = useState("");
  const [country, setCountry] = useState("");
  const [taxClass, setTaxClass] = useState("");
  const { rules, isLoading, isError, error, refetch } = useTaxRules({
    state: state || undefined,
    country: country.trim() ? country.trim().toUpperCase() : undefined,
    tax_class: taxClass.trim() || undefined,
  });
  const createRule = useCreateTaxRule();
  const updateRule = useUpdateTaxRule();
  const deleteRule = useDeleteTaxRule();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<TaxRule | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formError, setFormError] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<TaxRule | null>(null);

  useEffect(() => {
    if (!dialogOpen) return;
    setForm(editing ? formOf(editing) : EMPTY_FORM);
    setFormError(null);
  }, [dialogOpen, editing]);

  const openCreate = () => { setEditing(null); setDialogOpen(true); };
  const openEdit = (rule: TaxRule) => { setEditing(rule); setDialogOpen(true); };

  const submit = async () => {
    const rate = Number(form.rateBasisPoints);
    const priority = Number(form.priority);
    if (form.code.trim() === "") return setFormError("Code is required.");
    if (!Number.isInteger(rate) || rate < 0 || rate > 10000) return setFormError("Rate must be an integer from 0 to 10000 basis points.");
    if (!Number.isInteger(priority)) return setFormError("Priority must be an integer.");
    if (form.country.trim() !== "" && !/^[A-Za-z]{2}$/.test(form.country.trim())) return setFormError("Country must be an ISO2 code.");
    if (form.effectiveAt && form.expiresAt && new Date(form.effectiveAt) >= new Date(form.expiresAt)) {
      return setFormError("Effective date must be before the expires date.");
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
      setFormError(cause instanceof Error ? cause.message : "The tax rule was not accepted.");
    }
  };

  const busy = createRule.isPending || updateRule.isPending || deleteRule.isPending;

  return (
    <div className="space-y-4">
      <PageHeader title="Tax rules" description="Native tax rules with optional compound rates. The most specific single rule wins unless a matching rule opts into compounding.">
        <Button onClick={openCreate} disabled={busy}>
          <Plus className="mr-2 h-4 w-4" />
          New tax rule
        </Button>
      </PageHeader>

      <div className="flex flex-wrap items-end gap-2" aria-label="Tax rule filters">
        <label className="grid gap-1 text-sm">State
          <select className="h-10 rounded-md border bg-background px-3" value={state} onChange={(event) => setState(event.target.value)} aria-label="Filter tax rules by state">
            <option value="">All states</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
          </select>
        </label>
        <label className="grid gap-1 text-sm">Country
          <Input className="max-w-32" value={country} onChange={(event) => setCountry(event.target.value)} placeholder="ISO2" aria-label="Filter tax rules by country" />
        </label>
        <label className="grid gap-1 text-sm">Tax class
          <Input className="max-w-48" value={taxClass} onChange={(event) => setTaxClass(event.target.value)} placeholder="standard" aria-label="Filter tax rules by tax class" />
        </label>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Tax rules</CardTitle>
          <p className="text-xs text-muted-foreground">Create, edit, and delete rules. Compound rules sum every matching rule by priority, then rate.</p>
        </CardHeader>
        <CardContent className="space-y-3">
          {isError ? <ApiState error={error} onRetry={() => refetch()} /> : null}
          {isLoading ? <p role="status">Loading tax rules...</p> : null}
          {!isLoading && !isError && rules.length === 0 ? <p role="status" className="text-sm text-muted-foreground">No tax rules configured.</p> : null}
          {!isLoading && !isError && rules.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm" aria-label="Tax rules">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="px-2 py-2">Code</th>
                    <th className="px-2 py-2">Destination</th>
                    <th className="px-2 py-2">Class</th>
                    <th className="px-2 py-2">Rate</th>
                    <th className="px-2 py-2">Priority</th>
                    <th className="px-2 py-2">Compound</th>
                    <th className="px-2 py-2">State</th>
                    <th className="px-2 py-2">Window</th>
                    <th className="px-2 py-2 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {rules.map((rule) => (
                    <tr key={rule.id} className="border-b last:border-0">
                      <td className="px-2 py-2 font-mono text-xs">{rule.code}</td>
                      <td className="px-2 py-2">{destinationLabel(rule)}</td>
                      <td className="px-2 py-2">{rule.tax_class}</td>
                      <td className="px-2 py-2">{formatRate(rule.rate_basis_points)}</td>
                      <td className="px-2 py-2">{rule.priority}</td>
                      <td className="px-2 py-2"><Badge variant={Number(rule.compound) === 1 ? "default" : "outline"}>{Number(rule.compound) === 1 ? "Compound" : "Single"}</Badge></td>
                      <td className="px-2 py-2"><Badge variant={rule.state === "active" ? "default" : "outline"}>{rule.state}</Badge></td>
                      <td className="px-2 py-2 text-xs text-muted-foreground">{rule.effective_at ?? "any"} &rarr; {rule.expires_at ?? "any"}</td>
                      <td className="px-2 py-2">
                        <div className="flex justify-end gap-1">
                          <Button variant="ghost" size="icon" aria-label={`Edit ${rule.code}`} disabled={busy} onClick={() => openEdit(rule)}>
                            <Pencil className="h-4 w-4" />
                          </Button>
                          <Button variant="ghost" size="icon" aria-label={`Delete ${rule.code}`} disabled={busy} onClick={() => setDeleteTarget(rule)}>
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
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>{editing ? "Edit tax rule" : "Create tax rule"}</DialogTitle>
            <DialogDescription>Rates use basis points (1000 = 10%). Windows are validated by the backend.</DialogDescription>
          </DialogHeader>
          <div className="grid gap-3 md:grid-cols-2">
            <div>
              <Label htmlFor="tax-code">Code *</Label>
              <Input id="tax-code" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-class">Tax class</Label>
              <Input id="tax-class" value={form.taxClass} onChange={(event) => setForm({ ...form, taxClass: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-country">Country (ISO2)</Label>
              <Input id="tax-country" maxLength={2} value={form.country} onChange={(event) => setForm({ ...form, country: event.target.value.toUpperCase() })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-region">Region</Label>
              <Input id="tax-region" value={form.region} onChange={(event) => setForm({ ...form, region: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-postal">Postal code</Label>
              <Input id="tax-postal" value={form.postalCode} onChange={(event) => setForm({ ...form, postalCode: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-rate">Rate (basis points) *</Label>
              <Input id="tax-rate" type="number" min={0} max={10000} value={form.rateBasisPoints} onChange={(event) => setForm({ ...form, rateBasisPoints: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-priority">Priority</Label>
              <Input id="tax-priority" type="number" value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-state">State</Label>
              <select id="tax-state" value={form.state} onChange={(event) => setForm({ ...form, state: event.target.value as TaxRuleState })} disabled={busy} className="h-9 w-full rounded-md border bg-background px-3 text-sm">
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
              </select>
            </div>
            <div>
              <Label htmlFor="tax-effective">Effective at</Label>
              <Input id="tax-effective" type="datetime-local" value={form.effectiveAt} onChange={(event) => setForm({ ...form, effectiveAt: event.target.value })} disabled={busy} />
            </div>
            <div>
              <Label htmlFor="tax-expires">Expires at</Label>
              <Input id="tax-expires" type="datetime-local" value={form.expiresAt} onChange={(event) => setForm({ ...form, expiresAt: event.target.value })} disabled={busy} />
            </div>
            <label className="flex items-center gap-2 text-sm md:col-span-2">
              <input type="checkbox" checked={form.compound} onChange={(event) => setForm({ ...form, compound: event.target.checked })} disabled={busy} />
              Compound: sum every matching rule (priority ASC, rate DESC) instead of the single most specific rule
            </label>
          </div>
          {formError ? <p role="alert" className="text-sm text-destructive">{formError}</p> : null}
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={busy}>Cancel</Button>
            <Button onClick={submit} disabled={busy}>{busy ? "Saving..." : editing ? "Save rule" : "Create rule"}</Button>
          </div>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Delete tax rule?"
        description={deleteTarget ? `${deleteTarget.code} will stop participating in quotes immediately.` : undefined}
        confirmLabel="Delete"
        destructive
        isPending={deleteRule.isPending}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={() => {
          const target = deleteTarget;
          setDeleteTarget(null);
          if (target) deleteRule.mutate(target.id);
        }}
      />
    </div>
  );
}
