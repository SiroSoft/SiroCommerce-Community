"use client";

import { useState } from "react";
import { getCustomerAccessToken } from "@/services/customer";
import { useCopy, useLocale } from "@/lib/i18n-client";
import { fromMinorUnits, isSupportedCurrency, minorUnit, toMinorUnits } from "@/lib/money";

const unitsFor = (currency?: string) => {
  try { return currency && isSupportedCurrency(currency) ? minorUnit(currency) : 2; } catch { return 2; }
};

export function StockAlertSignup({ productId, variantId, isPreorder, priceMinor, currency }: { productId: string; variantId?: string; isPreorder?: boolean; priceMinor?: number; currency?: string }) {
  const copy = useCopy().product;
  const locale = useLocale();
  void locale;
  const [email, setEmail] = useState("");
  const [kind, setKind] = useState<"back_in_stock" | "price_drop" | "preorder">(isPreorder ? "preorder" : "back_in_stock");
  const [target, setTarget] = useState("");
  const [state, setState] = useState<"idle" | "busy" | "done" | "error">("idle");
  const [message, setMessage] = useState("");
  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (state === "busy") return;
    setState("busy");
    setMessage("");
    try {
      const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
      const url = baseUrl
        ? `${baseUrl.replace(/\/$/, "")}/api/products/${encodeURIComponent(productId)}/stock-alerts`
        : `/api/products/${encodeURIComponent(productId)}/stock-alerts`;
      const token = getCustomerAccessToken();
      const supportedCurrency = currency && isSupportedCurrency(currency) ? currency : undefined;
      const targetMinor = kind === "price_drop" ? (supportedCurrency ? toMinorUnits(target, supportedCurrency) : Math.round(Number(target) * 100)) : NaN;
      if (kind === "price_drop" && !(Number.isFinite(targetMinor) && targetMinor > 0)) throw new Error("target");
      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        body: JSON.stringify({ ...(variantId ? { variant_id: variantId } : {}), ...(email.trim() ? { email: email.trim() } : {}), type: kind, ...(kind === "price_drop" ? { target_price_minor: targetMinor } : {}) }),
      });
      const body = (await response.json().catch(() => null)) as { success?: boolean; message?: string } | null;
      if (!response.ok || body?.success !== true) throw new Error(body?.message || "failed");
      setState("done");
    } catch {
      setState("error");
      setMessage(copy.alertFailed ?? "Could not save the alert. Try again.");
    }
  };
  if (state === "done") return <p className="form-notice" role="status">{copy.alertSaved ?? "Saved. We will email you when it triggers."}</p>;
  const units = unitsFor(currency);
  const targetPlaceholder = priceMinor !== undefined
    ? (currency && isSupportedCurrency(currency) ? fromMinorUnits(priceMinor, currency) : priceMinor / 100).toFixed(units)
    : units === 0 ? "1000" : "99.00";
  return <form className="stock-alert-form" onSubmit={(event) => void submit(event)}>
    <p className="stock">{copy.joinWaitlist}</p>
    <label className="muted" htmlFor={`stock-alert-kind-${productId}`}>{copy.alertKind ?? "Notify me about"}
      <select id={`stock-alert-kind-${productId}`} value={kind} onChange={(event) => setKind(event.target.value as typeof kind)}>
        <option value="back_in_stock">{copy.alertKindBack ?? "Back in stock"}</option>
        <option value="price_drop">{copy.alertKindDrop ?? "Price drop"}</option>
        {isPreorder ? <option value="preorder">{copy.alertKindPreorder ?? "Preorder availability"}</option> : null}
      </select>
    </label>
    {kind === "price_drop" ? <label className="muted" htmlFor={`stock-alert-target-${productId}`}>{copy.alertTarget ?? "Target price"}
      <input id={`stock-alert-target-${productId}`} type="number" min={10 ** -units} step={10 ** -units} value={target} onChange={(event) => setTarget(event.target.value)} placeholder={targetPlaceholder} />
    </label> : null}
    <label className="muted" htmlFor={`stock-alert-${productId}`}>{copy.alertEmail ?? "Email for the note"}
      <input id={`stock-alert-${productId}`} type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="you@example.com" maxLength={160} />
    </label>
    <button className="button" type="submit" disabled={state === "busy"}>{state === "busy" ? copy.alertSaving ?? "Saving..." : copy.notifyMe ?? "Notify me"}</button>
    {state === "error" && <p className="form-error" role="alert">{message}</p>}
  </form>;
}
