"use client";

import { useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { ApiState } from "@/modules/merchant/api-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { pickupPointsService, type PickupPoint } from "@/services/pickup-points.service";

type PickupConfirmation = { title: string; description: string; run: () => void };

export default function PickupPointsPage() {
  const [items, setItems] = useState<PickupPoint[]>([]);
  const [confirmation, setConfirmation] = useState<PickupConfirmation | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [source, setSource] = useState("");
  const [address, setAddress] = useState("");
  const [city, setCity] = useState("");

  const load = () => {
    setState("loading"); setError(undefined);
    void pickupPointsService.list()
      .then((value) => { setItems(value); setState("ready"); })
      .catch((cause) => { setError(cause); setState("error"); });
  };
  useEffect(load, []);

  const create = async () => {
    if (busy) return;
    setBusy(true); setError(undefined); setNotice("");
    try {
      const point = await pickupPointsService.create({
        code: code.trim().toUpperCase(), name: name.trim(),
        ...(source.trim() ? { source_id: source.trim() } : {}),
        ...(address.trim() ? { address_line: address.trim() } : {}),
        ...(city.trim() ? { city: city.trim() } : {}),
      });
      setNotice(`${point.code} is taking pickup orders.`);
      setCode(""); setName(""); setSource(""); setAddress(""); setCity("");
      load();
    } catch (cause) { setError(cause); }
    finally { setBusy(false); }
  };

  const applyToggle = async (point: PickupPoint, to: "active" | "disabled") => {
    if (busy) return;
    setBusy(true); setError(undefined); setNotice("");
    try {
      await pickupPointsService.update(point.code, { state: to });
      setNotice(`${point.code} ${to}.`);
      load();
    } catch (cause) { setError(cause); }
    finally { setBusy(false); }
  };
  const toggle = (point: PickupPoint) => {
    if (busy) return;
    const to = point.state === "active" ? "disabled" : "active";
    if (to === "disabled") {
      setConfirmation({
        title: "Disable pickup point",
        description: `Disable ${point.code}? New quotes will reject it; staged handover still completes.`,
        run: () => void applyToggle(point, to),
      });
      return;
    }
    void applyToggle(point, to);
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Pickup points" description="Click & Collect counters. Each point draws from one inventory source; quotes fail closed when the source cannot cover the cart." />
      {notice && <p role="status" className="rounded-md border border-primary/30 bg-primary/5 p-3 text-sm">{notice}</p>}
      <Card>
        <CardHeader><CardTitle>Add point</CardTitle></CardHeader>
        <CardContent className="flex flex-wrap items-end gap-3">
          <label className="text-sm">Code<Input aria-label="Point code" value={code} onChange={(event) => setCode(event.target.value)} placeholder="HN-01" className="mt-1 w-32" /></label>
          <label className="text-sm">Name<Input aria-label="Point name" value={name} onChange={(event) => setName(event.target.value)} placeholder="Hoan Kiem Store" className="mt-1 w-56" /></label>
          <label className="text-sm">Source (optional)<Input aria-label="Inventory source" value={source} onChange={(event) => setSource(event.target.value)} placeholder="default" className="mt-1 w-40" /></label>
          <label className="text-sm">Address<Input aria-label="Address" value={address} onChange={(event) => setAddress(event.target.value)} className="mt-1 w-56" /></label>
          <label className="text-sm">City<Input aria-label="City" value={city} onChange={(event) => setCity(event.target.value)} className="mt-1 w-40" /></label>
          <Button disabled={busy} onClick={() => void create()}>{busy ? "Saving…" : "Add point"}</Button>
        </CardContent>
      </Card>
      <Card>
        <CardHeader><CardTitle>Active points</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          {state === "loading" && <p role="status" className="text-sm text-muted-foreground">Loading…</p>}
          {state === "error" && <ApiState error={error} onRetry={load} />}
          {state === "ready" && (items.length === 0 ? <p className="text-sm text-muted-foreground">No pickup points yet.</p> : (
            <div className="divide-y rounded-md border">
              {items.map((item) => (
                <div key={item.code} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <p className="font-medium">{item.code} <Badge variant={item.state === "active" ? "default" : "destructive"}>{item.state}</Badge></p>
                    <p className="text-sm text-muted-foreground">{item.name}{item.city ? ` · ${item.city}` : ""} · source {item.source_id} · ready in {item.sla_hours}h</p>
                  </div>
                  <Button variant="outline" size="sm" disabled={busy} onClick={() => void toggle(item)}>{item.state === "active" ? "Disable" : "Enable"}</Button>
                </div>
              ))}
            </div>
          ))}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={confirmation !== null}
        title={confirmation?.title ?? ""}
        description={confirmation?.description}
        destructive
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
