"use client";

import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ApiState } from "@/modules/merchant/api-state";
import { fe1Service, type RecordValue } from "@/services/fe1.service";

export function ProductOptionsEditor({ productId }: { productId: number }) {
  const [options, setOptions] = useState<RecordValue[]>([]); const [name, setName] = useState(""); const [value, setValue] = useState(""); const [selected, setSelected] = useState(""); const [error, setError] = useState<unknown>(); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false); const lock = useRef(false);
  const load = () => { setLoading(true); void fe1Service.options(productId).then((items) => { setOptions(items); setSelected((current) => current || String(items[0]?.id ?? "")); }).catch(setError).finally(() => setLoading(false)); };
  useEffect(load, [productId]);
  const submit = async (action: () => Promise<unknown>) => { if (lock.current) return; lock.current = true; setBusy(true); setError(undefined); try { await action(); load(); } catch (cause) { setError(cause); } finally { lock.current = false; setBusy(false); } };
  return <Card><CardHeader><CardTitle>Options and values</CardTitle></CardHeader><CardContent className="space-y-4"><p className="text-sm text-muted-foreground">Supported option editor. Variants remain separate and require the generated variant contract.</p>{loading ? <p role="status">Loading options...</p> : options.length ? <div className="flex flex-wrap gap-2">{options.map((item, index) => <Button key={String(item.id ?? index)} size="sm" variant={selected === String(item.id) ? "default" : "outline"} onClick={() => setSelected(String(item.id))}>{String(item.name ?? "Option")}</Button>)}</div> : <p className="text-sm text-muted-foreground">No options yet.</p>}<div className="grid gap-2 sm:grid-cols-[1fr_auto]"><Input aria-label="New option name" value={name} onChange={(event) => setName(event.target.value)} disabled={busy} placeholder="New option, e.g. Color" /><Button disabled={busy || !name.trim()} onClick={() => submit(async () => { await fe1Service.createOption(productId, { name: name.trim() }); setName(""); })}>Add option</Button></div><div className="grid gap-2 sm:grid-cols-[1fr_auto]"><Input aria-label="New option value" value={value} onChange={(event) => setValue(event.target.value)} disabled={busy || !selected} placeholder="New value, e.g. Blue" /><Button disabled={busy || !selected || !value.trim()} onClick={() => submit(async () => { await fe1Service.addOptionValue(productId, selected, { value: value.trim() }); setValue(""); })}>Add value</Button></div>{error ? <ApiState error={error} onRetry={load} /> : null}</CardContent></Card>;
}
