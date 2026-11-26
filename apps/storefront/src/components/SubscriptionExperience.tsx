"use client";

import { useEffect, useState } from "react";
import type { SubscriptionPlan } from "@/types";
import { customer, getCustomerAccessToken, type CustomerAdapter, type CustomerSubscription } from "@/services/customer";
import { formatMinor } from "@/lib/money";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";

type Props = { plans?: SubscriptionPlan[]; adapter?: CustomerAdapter; onCheckout?: (plan: SubscriptionPlan) => void };

const formatDate = (value: string | undefined, locale: string, notScheduled: string) => value ? new Intl.DateTimeFormat(locale, { dateStyle: "medium" }).format(new Date(value)) : notScheduled;
const cadence = (value: CustomerSubscription) => `${value.interval_count && value.interval_count !== 1 ? `${value.interval_count} ` : ""}${value.interval}`;
const messageFor = (error: unknown, fallback: string) => error instanceof Error ? error.message : fallback;

export function SubscriptionExperience({ plans = [], adapter = customer(), onCheckout }: Props) {
  const locale = useLocale();
  const copy = useCopy().subs;
  const [token, setToken] = useState("");
  const [items, setItems] = useState<CustomerSubscription[]>([]);
  const [selected, setSelected] = useState<CustomerSubscription>();
  const [state, setState] = useState<"idle" | "loading" | "ready" | "error" | "unauthorized">("idle");
  const [busy, setBusy] = useState("");
  const [consent, setConsent] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const checkout = (plan: SubscriptionPlan) => {
    if (!token) { setError(copy.signInFirst); return; }
    if (!consent) { setError(copy.confirmCadence); return; }
    if (onCheckout) { onCheckout(plan); return; }
    // Start directly against POST /api/account/subscriptions (idempotent):
    // the old /checkout?subscription_plan= redirect never created anything.
    setBusy(`start:${plan.id}`); setError(""); setNotice("");
    void adapter.startSubscription(plan.id, consent)
      .then(() => { setNotice(copy.started); return load(); })
      .catch((value) => setError(messageFor(value, copy.startFailed)))
      .finally(() => setBusy(""));
  };

  async function load(nextToken = token) {
    if (!nextToken) { setState("unauthorized"); return; }
    setState("loading"); setError("");
    try { setItems(await adapter.getSubscriptions()); setState("ready"); }
    catch (value) { setState("error"); setError(messageFor(value, copy.tempUnavailable)); }
  }

  useEffect(() => {
    const next = getCustomerAccessToken();
    setToken(next);
    void load(next);
    const onStorage = () => { const current = getCustomerAccessToken(); setToken(current); void load(current); };
    window.addEventListener("storage", onStorage);
    window.addEventListener("siro:customer-session", onStorage);
    return () => { window.removeEventListener("storage", onStorage); window.removeEventListener("siro:customer-session", onStorage); };
  }, []);

  async function mutate(id: string, action: "pause" | "resume" | "cancel") {
    if (!consent) { setError(copy.confirmChange); return; }
    setBusy(`${id}:${action}`); setError(""); setNotice("");
    try { await adapter.transitionSubscription(id, action, consent); setNotice(action === "cancel" ? copy.cancelScheduled : action === "pause" ? copy.paused : copy.resumed); await load(); if (selected?.id === id) setSelected(await adapter.getSubscription(id)); }
    catch (value) { setError(messageFor(value, copy.updateFailed)); }
    finally { setBusy(""); }
  }

  async function retry(id: string) {
    setBusy(`${id}:renew`); setError(""); setNotice("");
    try { await adapter.renewSubscription(id); setNotice(copy.retryQueued); await load(); }
    catch (value) { setError(messageFor(value, copy.retryFailed)); }
    finally { setBusy(""); }
  }

  return <section className="subscription-experience" aria-labelledby="subscriptions-title">
    <div className="account-section-heading"><div><p className="eyebrow">{copy.eyebrow}</p><h2 id="subscriptions-title">{copy.title}</h2></div>{state === "ready" && <span className="muted">{fill(items.length === 1 ? copy.countOne : copy.countMany, { count: items.length })}</span>}</div>
    {plans.length > 0 && <section aria-labelledby="plans-title" className="subscription-plans"><h3 id="plans-title">{copy.plansTitle}</h3><p className="muted">{copy.plansCopy}</p><div className="plan-grid">{plans.map((plan) => <article className="plan-card" key={plan.id}><h4>{plan.name}</h4><p className="plan-price">{formatMinor(plan.price, plan.currency, locale)} <span>/ {plan.interval}</span></p><p className="muted">{fill(copy.catalogPrice, { currency: plan.currency })}</p><button className="button" type="button" onClick={() => checkout(plan)} disabled={busy !== ""}>{busy === `start:${plan.id}` ? copy.starting : copy.continueCheckout}</button></article>)}</div></section>}
    {state === "unauthorized" && <p className="form-notice" role="status">{copy.unauthorizedNote}</p>}
    {state === "loading" && <p className="form-notice" role="status" aria-busy="true">{copy.loading}</p>}
    {state === "error" && <div className="form-error" role="alert"><p>{error}</p><button className="text-button" type="button" onClick={() => void load()}>{copy.tryAgain}</button></div>}
    {state === "ready" && items.length === 0 && <p className="empty-state">{copy.empty}</p>}
    {error && state !== "error" && <p className="form-error" role="alert">{error}</p>}
    {notice && <p className="form-notice" role="status">{notice}</p>}
    {state === "ready" && items.length > 0 && <div className="subscription-list">{items.map((item) => <article className="subscription-card" key={item.id}><div className="account-section-heading"><div><h3>{item.product_name ?? item.plan_id ?? copy.title}</h3><p className="muted">{formatMinor(item.price.amount_minor, item.price.currency, locale)} / {cadence(item)}</p></div><span className={`status-pill status-${item.status}`}>{item.status}</span></div><dl className="subscription-meta"><div><dt>{copy.nextRenewal}</dt><dd>{formatDate(item.next_charge_at, locale, copy.notScheduled)}</dd></div>{item.paused_at && <div><dt>{copy.pausedAt}</dt><dd>{formatDate(item.paused_at, locale, copy.notScheduled)}</dd></div>}{item.cancelled_at && <div><dt>{copy.cancelEffective}</dt><dd>{formatDate(item.cancelled_at, locale, copy.notScheduled)}</dd></div>}<div><dt>{copy.renewalStatus}</dt><dd>{item.renewal_status ?? copy.notProvided}</dd></div></dl><div className="state-actions"><button className="text-button" type="button" onClick={() => { setSelected(item); setNotice(""); }} aria-expanded={selected?.id === item.id}>{copy.viewDetails}</button>{item.status === "active" && <button className="text-button" type="button" onClick={() => void mutate(item.id, "pause")} disabled={busy !== ""}>{copy.pause}</button>}{item.status === "paused" && <button className="text-button" type="button" onClick={() => void mutate(item.id, "resume")} disabled={busy !== ""}>{copy.resume}</button>}{item.status === "active" && <button className="text-button danger-action" type="button" onClick={() => void mutate(item.id, "cancel")} disabled={busy !== ""}>{copy.cancel}</button>}{item.renewal_status === "failed" || item.renewal_status === "retryable" ? <button className="button" type="button" onClick={() => void retry(item.id)} disabled={busy !== ""}>{busy === `${item.id}:renew` ? copy.retrying : copy.retryPayment}</button> : null}</div>{selected?.id === item.id && <div className="subscription-detail" role="region" aria-label={`${item.product_name ?? copy.title} details`}><p>{copy.subId} <strong>{item.id}</strong></p><p>{copy.protectedNote}</p><button className="text-button" type="button" onClick={() => setSelected(undefined)}>{copy.closeDetails}</button></div>}</article>)}</div>}
    {(plans.length > 0 || items.length > 0) && <label className="subscription-consent"><input type="checkbox" checked={consent} onChange={(event) => setConsent(event.target.checked)} /> {copy.consentLabel}</label>}
  </section>;
}
