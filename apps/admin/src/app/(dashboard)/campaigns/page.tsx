"use client";

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PageHeader } from "@/components/page-header";
import { CapabilityGate } from "@/components/capability-gate";
import { Switch } from "@/components/ui/switch";
import { useAuth } from "@/hooks/use-auth";
import { canOperateMerchant } from "@/modules/merchant/permissions";
import { campaignAutomationService, type CampaignAutomationSettings } from "@/services/campaign-automation.service";
import { idempotencyKey } from "@/services/merchant.service";

const defaults: CampaignAutomationSettings = { enabled: false, max_messages_per_day: 1000, quiet_hours_start: 21, quiet_hours_end: 8, allowed_channels: ["email"] };

type LoadState = "loading" | "ready" | "error";
type Notice = { kind: "success" | "error"; text: string } | null;

function isIntInRange(raw: string, min: number, max: number) {
  if (!/^\d+$/.test(raw.trim())) return false;
  const value = Number(raw);
  return Number.isInteger(value) && value >= min && value <= max;
}

export default function CampaignsPage() {
  return <CapabilityGate capability="campaigns"><CampaignsContent /></CapabilityGate>;
}

function CampaignsContent() {
  const { user } = useAuth();
  const [settings, setSettings] = useState(defaults);
  const [cap, setCap] = useState(String(defaults.max_messages_per_day));
  const [quietStart, setQuietStart] = useState(String(defaults.quiet_hours_start));
  const [quietEnd, setQuietEnd] = useState(String(defaults.quiet_hours_end));
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [notice, setNotice] = useState<Notice>(null);
  const editable = canOperateMerchant(user, "campaigns");

  const load = useCallback(() => {
    setLoadState("loading");
    setNotice(null);
    campaignAutomationService.get()
      .then((value) => {
        const merged = { ...defaults, ...value };
        merged.allowed_channels = Array.isArray(merged.allowed_channels) ? merged.allowed_channels : [...defaults.allowed_channels];
        setSettings(merged);
        setCap(String(merged.max_messages_per_day));
        setQuietStart(String(merged.quiet_hours_start));
        setQuietEnd(String(merged.quiet_hours_end));
        setErrors([]);
        setLoadState("ready");
      })
      .catch(() => setLoadState("error"));
  }, []);

  useEffect(() => { load(); }, [load]);

  function toggleChannel(channel: string, checked: boolean) {
    setSettings((prev) => ({
      ...prev,
      allowed_channels: checked
        ? [...new Set([...prev.allowed_channels, channel])]
        : prev.allowed_channels.filter((item) => item !== channel),
    }));
  }

  async function save() {
    const nextErrors: string[] = [];
    if (!isIntInRange(cap, 0, 100000)) nextErrors.push("Daily message cap must be a whole number between 0 and 100000.");
    if (!isIntInRange(quietStart, 0, 23)) nextErrors.push("Quiet hours start must be a whole number between 0 and 23.");
    if (!isIntInRange(quietEnd, 0, 23)) nextErrors.push("Quiet hours end must be a whole number between 0 and 23.");
    if (settings.allowed_channels.length === 0) nextErrors.push("Select at least one allowed channel.");
    setErrors(nextErrors);
    setNotice(null);
    if (nextErrors.length > 0 || saving) return;
    setSaving(true);
    try {
      const saved = await campaignAutomationService.update({
        enabled: settings.enabled,
        max_messages_per_day: Number(cap),
        quiet_hours_start: Number(quietStart),
        quiet_hours_end: Number(quietEnd),
        allowed_channels: settings.allowed_channels,
      }, idempotencyKey());
      const merged = { ...defaults, ...saved };
      setSettings(merged);
      setNotice({ kind: "success", text: "Saved. Changes apply only to this commerce scope." });
    } catch {
      setNotice({ kind: "error", text: "Settings could not be saved." });
    } finally {
      setSaving(false);
    }
  }

  return <div className="space-y-6">
    <PageHeader title="Campaign automation" description="Bounded controls for consent-aware lifecycle messaging. Sending, recipients, prices, stock, and order state remain server-owned." />
    <Card>
      <CardHeader>
        <CardTitle>Safety limits</CardTitle>
        <CardDescription>Automation is off by default, capped per scope, and quiet during the configured hours.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {loadState === "loading" && <p role="status" className="text-sm text-muted-foreground">Loading settings...</p>}
        {loadState === "error" && (
          <div className="space-y-3">
            <p role="alert" className="text-sm text-destructive">Campaign settings are unavailable.</p>
            <Button variant="outline" onClick={load}>Try again</Button>
          </div>
        )}
        {loadState === "ready" && (
          <>
            <div className="flex items-center gap-3">
              <Switch id="campaign-enabled" checked={settings.enabled} disabled={!editable} onCheckedChange={(enabled) => setSettings({ ...settings, enabled })} />
              <label htmlFor="campaign-enabled">Enable campaign automation</label>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
              <label className="space-y-2 text-sm">Daily message cap
                <Input type="number" min={0} max={100000} value={cap} disabled={!editable} onChange={(event) => setCap(event.target.value)} aria-label="Daily message cap" />
              </label>
              <label className="space-y-2 text-sm">Quiet hours start (0-23)
                <Input type="number" min={0} max={23} value={quietStart} disabled={!editable} onChange={(event) => setQuietStart(event.target.value)} aria-label="Quiet hours start (0-23)" />
              </label>
              <label className="space-y-2 text-sm">Quiet hours end (0-23)
                <Input type="number" min={0} max={23} value={quietEnd} disabled={!editable} onChange={(event) => setQuietEnd(event.target.value)} aria-label="Quiet hours end (0-23)" />
              </label>
            </div>
            <fieldset>
              <legend className="mb-2 text-sm font-medium">Allowed channels</legend>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={settings.allowed_channels.includes("email")} disabled={!editable} onChange={(event) => toggleChannel("email", event.target.checked)} /> Email
              </label>
              <label className="mt-2 flex items-center gap-2 text-sm">
                <input type="checkbox" checked={settings.allowed_channels.includes("sms")} disabled={!editable} onChange={(event) => toggleChannel("sms", event.target.checked)} /> SMS
              </label>
            </fieldset>
            {errors.length > 0 && (
              <div role="alert" className="space-y-1 text-sm text-destructive">
                {errors.map((message) => <p key={message}>{message}</p>)}
              </div>
            )}
            <Button disabled={!editable || saving} onClick={() => void save()}>{saving ? "Saving..." : "Save settings"}</Button>
            {notice && (
              <div className="flex flex-wrap items-center gap-3">
                <p role={notice.kind === "error" ? "alert" : "status"} className={notice.kind === "error" ? "text-sm text-destructive" : "text-sm text-muted-foreground"}>
                  {notice.text}
                </p>
                {notice.kind === "error" && <Button variant="outline" size="sm" disabled={saving} onClick={() => void save()}>Try again</Button>}
              </div>
            )}
            {!editable && <p role="note" className="text-sm text-muted-foreground">You have read-only access to campaign settings.</p>}
          </>
        )}
      </CardContent>
    </Card>
  </div>;
}
