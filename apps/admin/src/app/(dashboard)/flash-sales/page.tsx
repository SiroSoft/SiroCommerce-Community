"use client";
import { useEffect, useState } from "react";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CapabilityGate } from "@/components/capability-gate";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { ApiState } from "@/modules/merchant/api-state";
import { useI18n } from "@/providers/i18n-provider";
import { flashSaleService, type FlashSale } from "@/services/flash-sale.service";
import { memberDealsService, type MemberDeal } from "@/services/member-deals.service";

function toLocalInput(iso: string) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function FlashSalesPage() {
  return <CapabilityGate capability="products"><FlashSalesContent /></CapabilityGate>;
}

function FlashSalesContent() {
  const { t } = useI18n();
  const [sales, setSales] = useState<FlashSale[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>();
  const [notice, setNotice] = useState("");
  const [name, setName] = useState("");
  const [starts, setStarts] = useState("");
  const [ends, setEnds] = useState("");
  const [variant, setVariant] = useState("");
  const [price, setPrice] = useState("");
  const [cap, setCap] = useState("100");
  const [saleId, setSaleId] = useState("");
  const [memberVariant, setMemberVariant] = useState("");
  const [memberTier, setMemberTier] = useState("gold");
  const [memberPrice, setMemberPrice] = useState("");
  const [members, setMembers] = useState<Record<string, unknown>[]>([]);
  const [busy, setBusy] = useState(false);
  const [confirmation, setConfirmation] = useState<{ description: string; run: () => void } | null>(null);

  const load = () => {
    setLoading(true); setError(undefined);
    flashSaleService.list().then(setSales).catch(setError).finally(() => setLoading(false));
  };
  useEffect(load, []);
  const loadMembers = () => {
    flashSaleService.memberPrices(memberVariant || undefined).then(setMembers).catch(setError);
  };
  useEffect(() => { loadMembers(); }, []);

  const create = async () => {
    if (!name.trim() || !starts || !ends) { setNotice("Name, start and end are required"); return; }
    setBusy(true); setError(undefined); setNotice("");
    try {
      await flashSaleService.create({ name: name.trim(), starts_at: new Date(starts).toISOString(), ends_at: new Date(ends).toISOString(), state: "draft" });
      setNotice("Flash sale created as draft — set active to run");
      setName(""); setStarts(""); setEnds("");
      load();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  const setState = (id: string, state: string) => {
    setConfirmation({
      description: `${state} sale ${id}? This changes the flash-sale state.`,
      run: () => void executeState(id, state),
    });
  };
  const executeState = async (id: string, state: string) => {
    setBusy(true); setError(undefined);
    try { await flashSaleService.update(id, { state }); load(); } catch (e) { setError(e); } finally { setBusy(false); }
  };
  const addItem = async () => {
    if (!saleId.trim() || !variant.trim() || !price) { setNotice("Sale, variant and price are required"); return; }
    const amount = Number(price);
    if (!Number.isInteger(amount) || amount < 0) { setNotice("Price must be a non-negative integer (minor units)"); return; }
    setBusy(true); setError(undefined); setNotice("");
    try {
      await flashSaleService.addItem(saleId.trim(), { variant_id: variant.trim(), sale_price_minor: amount, cap_qty: Number(cap) || 0 });
      setNotice("Item added");
      load();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  const saveMember = async () => {
    if (!memberVariant.trim() || !memberPrice) { setNotice("Variant and price are required"); return; }
    const amount = Number(memberPrice);
    if (!Number.isInteger(amount) || amount < 0) { setNotice("Member price must be a non-negative integer"); return; }
    setBusy(true); setError(undefined); setNotice("");
    try {
      await flashSaleService.saveMemberPrice({ variant_id: memberVariant.trim(), tier: memberTier, price_minor: amount });
      setNotice(`Member price saved (${memberTier})`);
      loadMembers();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-5">
      <PageHeader title={t("flash.title")} description={t("flash.description")} />
      {notice && <p role="status" className="rounded-md border bg-muted px-3 py-2 text-sm">{notice}</p>}
      {error ? <ApiState error={error} onRetry={load} /> : null}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardHeader><CardTitle>{t("flash.newSale")}</CardTitle><CardDescription>{t("flash.newSaleDesc")}</CardDescription></CardHeader>
          <CardContent className="space-y-3">
            <div><Label htmlFor="flash-name">Name</Label><Input id="flash-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Gio vang 20h" disabled={busy} /></div>
            <div className="grid grid-cols-2 gap-2">
              <div><Label htmlFor="flash-starts">Starts at</Label><Input id="flash-starts" type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} disabled={busy} /></div>
              <div><Label htmlFor="flash-ends">Ends at</Label><Input id="flash-ends" type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} disabled={busy} /></div>
            </div>
            <Button size="sm" onClick={create} disabled={busy || !name.trim()}>{t("flash.createSale")}</Button>
          </CardContent></Card>
        <Card><CardHeader><CardTitle>{t("flash.addItem")}</CardTitle><CardDescription>{t("flash.addItemDesc")}</CardDescription></CardHeader>
          <CardContent className="space-y-3">
            <div><Label htmlFor="flash-sale">Sale ID</Label><Input id="flash-sale" value={saleId} onChange={(e) => setSaleId(e.target.value)} placeholder="sale id" disabled={busy} /></div>
            <div className="grid grid-cols-3 gap-2">
              <div><Label htmlFor="flash-variant">Variant ID</Label><Input id="flash-variant" value={variant} onChange={(e) => setVariant(e.target.value)} disabled={busy} /></div>
              <div><Label htmlFor="flash-price">Sale price (minor)</Label><Input id="flash-price" type="number" min="0" value={price} onChange={(e) => setPrice(e.target.value)} disabled={busy} /></div>
              <div><Label htmlFor="flash-cap">Cap qty</Label><Input id="flash-cap" type="number" min="0" value={cap} onChange={(e) => setCap(e.target.value)} disabled={busy} /></div>
            </div>
            <Button size="sm" onClick={addItem} disabled={busy}>{t("flash.addItem")}</Button>
          </CardContent></Card>
      </div>
      <Card><CardHeader><CardTitle>{t("flash.sales")} ({sales.length})</CardTitle></CardHeader>
        <CardContent>
          {loading ? <p role="status" className="text-sm text-muted-foreground">Loading sales…</p> : sales.length === 0 ? <p className="text-sm text-muted-foreground">No flash sales yet.</p> : (
            <div className="space-y-3">{sales.map((s) => {
              const id = String(s.id);
              const items = Array.isArray(s.items) ? s.items as Record<string, unknown>[] : [];
              return <div key={id} className="rounded-md border p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div><strong>{String(s.name)}</strong> <span className="text-xs text-muted-foreground">{String(s.state)} · {toLocalInput(String(s.starts_at))} → {toLocalInput(String(s.ends_at))}{typeof s.min_tier === "string" && s.min_tier !== "" ? ` · ${s.min_tier}+ members` : ""}</span></div>
                  <div className="flex gap-2">
                    <select aria-label={`Minimum tier for ${String(s.name)}`} value={typeof s.min_tier === "string" ? s.min_tier : ""} disabled={busy} onChange={(e) => { const tier = e.target.value; setBusy(true); setError(undefined); void flashSaleService.update(id, { min_tier: tier }).then(load).catch(setError).finally(() => setBusy(false)); }} className="rounded-md border px-2 py-1 text-sm">
                      <option value="">All shoppers</option>
                      <option value="bronze">bronze+</option>
                      <option value="silver">silver+</option>
                      <option value="gold">gold+</option>
                      <option value="platinum">platinum</option>
                    </select>
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => setState(id, "active")}>{t("flash.activate")}</Button>
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => setState(id, "archived")}>{t("flash.archive")}</Button>
                  </div>
                </div>
                {items.length > 0 && <ul className="mt-2 divide-y text-sm">{items.map((it, i) => <li key={String(it.id ?? i)} className="flex flex-wrap justify-between gap-2 py-1"><span>{String(it.variant_id)} · {String(it.sale_price_minor)}</span><span className="text-muted-foreground">sold {String(it.sold_qty ?? 0)}/{Number(it.cap_qty ?? 0) === 0 ? "∞" : String(it.cap_qty)}</span></li>)}</ul>}
              </div>;
            })}</div>
          )}
        </CardContent></Card>
      <Card><CardHeader><CardTitle>{t("flash.memberTitle")}</CardTitle><CardDescription>{t("flash.memberDesc")}</CardDescription></CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-2 md:grid-cols-4">
            <div><Label htmlFor="member-variant">Variant ID</Label><Input id="member-variant" value={memberVariant} onChange={(e) => setMemberVariant(e.target.value)} disabled={busy} /></div>
            <div><Label htmlFor="member-tier">Tier</Label><select id="member-tier" className="flex h-9 w-full rounded-md border px-3 text-sm" value={memberTier} onChange={(e) => setMemberTier(e.target.value)} disabled={busy}>{["bronze", "silver", "gold", "platinum"].map((t) => <option key={t} value={t}>{t}</option>)}</select></div>
            <div><Label htmlFor="member-price">Price (minor)</Label><Input id="member-price" type="number" min="0" value={memberPrice} onChange={(e) => setMemberPrice(e.target.value)} disabled={busy} /></div>
            <div className="flex items-end gap-2"><Button size="sm" onClick={saveMember} disabled={busy}>{t("flash.save")}</Button><Button variant="outline" size="sm" onClick={loadMembers} disabled={busy}>{t("flash.reload")}</Button></div>
          </div>
          {members.length > 0 && <ul className="divide-y text-sm">{members.map((m, i) => <li key={String(m.id ?? i)} className="flex flex-wrap justify-between gap-2 py-1"><span>{String(m.variant_id)} · {String(m.tier)}</span><span className="text-muted-foreground">{String(m.price_minor)} {String(m.currency)}</span></li>)}</ul>}
        </CardContent></Card>
      <MemberDealsCard busy={busy} setBusy={setBusy} setError={setError} setNotice={setNotice} />
      <ConfirmDialog
        open={confirmation !== null}
        title="Update flash sale"
        description={confirmation?.description}
        isPending={busy}
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

function MemberDealsCard({ busy, setBusy, setError, setNotice }: { busy: boolean; setBusy: (v: boolean) => void; setError: (e: unknown) => void; setNotice: (s: string) => void }) {
  const [deals, setDeals] = useState<MemberDeal[]>([]);
  const [name, setName] = useState("");
  const [kind, setKind] = useState("tier_window");
  const [tier, setTier] = useState("");
  const [percent, setPercent] = useState("");
  const [starts, setStarts] = useState("");
  const [ends, setEnds] = useState("");
  const [confirmation, setConfirmation] = useState<{ description: string; run: () => void } | null>(null);
  const load = () => { void memberDealsService.list().then(setDeals).catch(setError); };
  useEffect(load, []);
  const create = async () => {
    const pct = Number(percent);
    if (!name.trim() || !Number.isInteger(pct) || pct < 1 || pct > 90) { setNotice("Name and percent 1..90 are required"); return; }
    setBusy(true); setError(undefined); setNotice("");
    try {
      await memberDealsService.create({
        name: name.trim(), kind,
        ...(tier ? { min_tier: tier } : {}),
        percent_off: pct,
        ...(starts ? { starts_at: new Date(starts).toISOString() } : {}),
        ...(ends ? { ends_at: new Date(ends).toISOString() } : {}),
      });
      setNotice("Member deal created");
      setName(""); setPercent(""); setStarts(""); setEnds(""); setTier("");
      load();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  const toggle = (deal: MemberDeal) => {
    const to = deal.state === "active" ? "disabled" : "active";
    setConfirmation({
      description: `${to} deal ${deal.name}? This changes whether the member deal applies at checkout.`,
      run: () => void executeToggle(deal, to),
    });
  };
  const executeToggle = async (deal: MemberDeal, to: string) => {
    setBusy(true); setError(undefined);
    try { await memberDealsService.update(deal.id, { state: to }); load(); } catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <Card><CardHeader><CardTitle>Member deals</CardTitle><CardDescription>Tier windows + birthday percent-off, auto-applied. Best single deal wins.</CardDescription></CardHeader>
      <CardContent className="space-y-3">
        <div className="grid gap-2 md:grid-cols-6">
          <div><Label htmlFor="deal-name">Name</Label><Input id="deal-name" value={name} onChange={(e) => setName(e.target.value)} disabled={busy} /></div>
          <div><Label htmlFor="deal-kind">Kind</Label><select id="deal-kind" className="flex h-9 w-full rounded-md border px-3 text-sm" value={kind} onChange={(e) => setKind(e.target.value)} disabled={busy}><option value="tier_window">Tier window</option><option value="birthday">Birthday</option></select></div>
          <div><Label htmlFor="deal-tier">Min tier</Label><select id="deal-tier" className="flex h-9 w-full rounded-md border px-3 text-sm" value={tier} onChange={(e) => setTier(e.target.value)} disabled={busy}><option value="">All members</option>{["bronze", "silver", "gold", "platinum"].map((t) => <option key={t} value={t}>{t}+</option>)}</select></div>
          <div><Label htmlFor="deal-percent">Percent off</Label><Input id="deal-percent" type="number" min="1" max="90" value={percent} onChange={(e) => setPercent(e.target.value)} disabled={busy} /></div>
          <div><Label htmlFor="deal-starts">Starts</Label><Input id="deal-starts" type="datetime-local" value={starts} onChange={(e) => setStarts(e.target.value)} disabled={busy} /></div>
          <div><Label htmlFor="deal-ends">Ends</Label><Input id="deal-ends" type="datetime-local" value={ends} onChange={(e) => setEnds(e.target.value)} disabled={busy} /></div>
        </div>
        <Button size="sm" onClick={create} disabled={busy}>Create deal</Button>
        {deals.length > 0 && <ul className="divide-y text-sm">{deals.map((deal) => <li key={deal.id} className="flex flex-wrap items-center justify-between gap-2 py-1"><span><strong>{deal.name}</strong> <span className="text-muted-foreground">{deal.kind} · {deal.percent_off}%{deal.min_tier ? ` · ${deal.min_tier}+` : ""} · {deal.state}</span></span><Button variant="outline" size="sm" disabled={busy} onClick={() => toggle(deal)}>{deal.state === "active" ? "Disable" : "Enable"}</Button></li>)}</ul>}
        <ConfirmDialog
          open={confirmation !== null}
          title="Update member deal"
          description={confirmation?.description}
          isPending={busy}
          onCancel={() => setConfirmation(null)}
          onConfirm={() => {
            const action = confirmation;
            setConfirmation(null);
            action?.run();
          }}
        />
      </CardContent></Card>
  );
}
