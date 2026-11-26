"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import { customer, CustomerRequestError, type CustomerAdapter, type CustomerReturn } from "@/services/customer";
import { formatMinor } from "@/lib/money";
import { fill } from "@/lib/i18n";
import { useCopy, useLocale } from "@/lib/i18n-client";
import type { Order } from "@/purchase/contracts";

export type AccountReturnOrder = Pick<Order, "id" | "number">;

type LoadState = "loading" | "ready" | "error";
type DetailState = "closed" | "loading" | "ready" | "error";
type LineDraft = { lineId: string; qty: string; reason: string };

const emptyLine = (): LineDraft => ({ lineId: "", qty: "1", reason: "" });

const loadFailedCopy = (copy: { loadError: string; signInRequired: string }, error: unknown): string =>
  error instanceof CustomerRequestError && error.status === 401 ? copy.signInRequired : copy.loadError;

export function AccountReturns({ orders, sessionToken, requestOrderId, adapter }: {
  orders: AccountReturnOrder[];
  sessionToken?: string;
  requestOrderId?: string;
  adapter?: CustomerAdapter;
}) {
  const copy = useCopy().accountReturns;
  const locale = useLocale();
  const client = useMemo(() => adapter ?? customer(), [adapter]);
  const signedIn = Boolean(sessionToken);
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [loadError, setLoadError] = useState("");
  const [reload, setReload] = useState(0);
  const [items, setItems] = useState<CustomerReturn[]>([]);
  const [selected, setSelected] = useState<CustomerReturn | null>(null);
  const [detailState, setDetailState] = useState<DetailState>("closed");
  const [formOpen, setFormOpen] = useState(false);
  const [orderId, setOrderId] = useState("");
  const [fulfillmentId, setFulfillmentId] = useState("");
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);
  const [reason, setReason] = useState("");
  const [formError, setFormError] = useState("");
  const [formSuccess, setFormSuccess] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!signedIn) return;
    let active = true;
    setLoadState("loading");
    setLoadError("");
    client.getReturns().then((value) => {
      if (!active) return;
      setItems(value);
      setLoadState("ready");
    }).catch((error: unknown) => {
      if (!active) return;
      setLoadState("error");
      setLoadError(loadFailedCopy(copy, error));
    });
    return () => { active = false; };
  }, [client, signedIn, reload, copy]);

  useEffect(() => {
    if (!requestOrderId) return;
    setOrderId(requestOrderId);
    setFormOpen(true);
  }, [requestOrderId]);

  if (!signedIn) {
    return (
      <section className="account-panel" aria-labelledby="account-returns-title">
        <div className="account-section-heading">
          <h3 id="account-returns-title">{copy.title}</h3>
          <span className="muted">{copy.subtitle}</span>
        </div>
        <p className="muted" role="note">{copy.signInRequired}</p>
      </section>
    );
  }

  async function openDetail(id: string) {
    setSelected(null);
    setDetailState("loading");
    try {
      const value = await client.getReturn(id);
      setSelected(value);
      setDetailState("ready");
    } catch {
      setDetailState("error");
    }
  }

  function closeDetail() {
    setSelected(null);
    setDetailState("closed");
  }

  function updateLine(index: number, patch: Partial<LineDraft>) {
    setLines((current) => current.map((line, position) => (position === index ? { ...line, ...patch } : line)));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setFormError("");
    setFormSuccess("");
    const trimmedOrder = orderId.trim();
    if (!trimmedOrder) { setFormError(copy.needOrder); return; }
    const trimmedFulfillment = fulfillmentId.trim();
    if (!trimmedFulfillment) { setFormError(copy.needFulfillment); return; }
    const payloadLines: Array<{ order_line_id: string; qty: number; reason?: string }> = [];
    for (const line of lines) {
      const lineId = line.lineId.trim();
      if (!lineId) continue;
      const qty = Number(line.qty);
      if (!Number.isSafeInteger(qty) || qty < 1 || qty > 99) { setFormError(copy.invalidQty); return; }
      payloadLines.push({ order_line_id: lineId, qty, ...(line.reason.trim() ? { reason: line.reason.trim() } : {}) });
    }
    if (payloadLines.length === 0) { setFormError(copy.needLine); return; }
    setSubmitting(true);
    try {
      const created = await client.requestReturn(trimmedOrder, {
        fulfillment_id: trimmedFulfillment,
        lines: payloadLines,
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      });
      setItems((current) => [created, ...current]);
      setFormSuccess(fill(copy.success, { id: String(created.id) }));
      setLines([emptyLine()]);
      setReason("");
    } catch {
      setFormError(copy.submitFailed);
    } finally {
      setSubmitting(false);
    }
  }

  // The customer return endpoints expose no transition timeline; the case
  // history below is built from the fields CustomerReturnResource discloses.
  const timeline = (item: CustomerReturn): Array<{ label: string; value: string }> => {
    const entries: Array<{ label: string; value: string }> = [];
    if (item.created_at) entries.push({ label: copy.created, value: item.created_at });
    if (item.completed_at) entries.push({ label: copy.completed, value: item.completed_at });
    if (item.refund_state) {
      const amount = typeof item.refund_amount_minor === "number" && item.refund_currency
        ? ` · ${formatMinor(item.refund_amount_minor, item.refund_currency, locale)}`
        : "";
      entries.push({ label: copy.refund, value: `${item.refund_state}${amount}` });
    }
    if (item.label_tracking || item.label_state) {
      entries.push({ label: copy.tracking, value: [item.label_tracking, item.label_state].filter(Boolean).join(" · ") });
    }
    return entries;
  };

  return (
    <section className="account-panel" aria-labelledby="account-returns-title">
      <div className="account-section-heading">
        <h3 id="account-returns-title">{copy.title}</h3>
        <span className="muted">{copy.subtitle}</span>
      </div>
      {loadState === "loading" && <p className="muted" role="status">{copy.loading}</p>}
      {loadState === "error" && (
        <>
          <p className="form-error" role="alert">{loadError}</p>
          <button className="text-button" type="button" onClick={() => setReload((value) => value + 1)}>
            {copy.retry}
          </button>
        </>
      )}
      {loadState === "ready" && detailState === "closed" && (items.length ? (
        <ol className="engagement-timeline">
          {items.map((item) => (
            <li key={String(item.id)}>
              <strong>{fill(copy.itemTitle, { id: String(item.id) })}</strong>
              <span>{item.state ?? ""}{item.refund_state ? ` · ${copy.refund} ${item.refund_state}` : ""}{item.label_tracking ? ` · ${copy.tracking} ${item.label_tracking}` : ""}</span>
              <button className="text-button" type="button" onClick={() => void openDetail(String(item.id))}>
                {copy.viewDetails}
              </button>
            </li>
          ))}
        </ol>
      ) : (
        <p className="muted">{copy.empty}</p>
      ))}
      {detailState !== "closed" && (
        <div className="account-capability">
          <button className="text-button" type="button" onClick={closeDetail}>
            {copy.backToList}
          </button>
          {detailState === "loading" && <p className="muted" role="status">{copy.detailLoading}</p>}
          {detailState === "error" && <p className="form-error" role="alert">{copy.detailFailed}</p>}
          {detailState === "ready" && selected && (
            <article aria-label={fill(copy.itemTitle, { id: String(selected.id) })}>
              <h4>{fill(copy.itemTitle, { id: String(selected.id) })}</h4>
              <dl className="return-detail">
                <div><dt>{copy.status}</dt><dd>{selected.state ?? ""}</dd></div>
                {selected.order_id !== undefined && <div><dt>{copy.order}</dt><dd>{String(selected.order_id)}</dd></div>}
                {selected.reason && <div><dt>{copy.reason}</dt><dd>{selected.reason}</dd></div>}
              </dl>
              {selected.lines && selected.lines.length > 0 && (
                <>
                  <h5>{copy.items}</h5>
                  <ul className="return-lines">
                    {selected.lines.map((line, index) => (
                      <li key={String(line.id ?? line.order_line_id ?? index)}>
                        <strong>{String(line.order_line_id ?? line.id ?? "")}</strong>
                        <span> · {copy.quantity}: {line.qty ?? ""}</span>
                        {line.reason && <span> · {line.reason}</span>}
                        {line.disposition && <span> · {line.disposition}</span>}
                        {line.condition && <span> · {line.condition}</span>}
                      </li>
                    ))}
                  </ul>
                </>
              )}
              {timeline(selected).length > 0 && (
                <>
                  <h5>{copy.timeline}</h5>
                  <ol className="engagement-timeline">
                    {timeline(selected).map((entry) => (
                      <li key={entry.label}>
                        <strong>{entry.label}</strong>
                        <span>{entry.value}</span>
                      </li>
                    ))}
                  </ol>
                </>
              )}
            </article>
          )}
        </div>
      )}
      <div className="return-request">
        <button className="text-button" type="button" onClick={() => setFormOpen((open) => !open)} aria-expanded={formOpen}>
          {copy.startRequest}
        </button>
        {formOpen && (
          <form className="auth-form" onSubmit={submit} noValidate aria-label={copy.requestTitle}>
            <p className="muted">{copy.requestHint}</p>
            {orders.length === 0 && <p className="muted" role="note">{copy.noOrders}</p>}
            <label htmlFor="account-return-order">
              {copy.orderField}
              <select id="account-return-order" value={orderId} onChange={(event) => setOrderId(event.target.value)}>
                <option value="">{copy.selectOrder}</option>
                {orders.map((entry) => <option key={entry.id} value={entry.id}>{entry.number}</option>)}
                {orderId && !orders.some((entry) => entry.id === orderId) && <option value={orderId}>{orderId}</option>}
              </select>
            </label>
            <label htmlFor="account-return-fulfillment">
              {copy.fulfillmentField}
              <input id="account-return-fulfillment" value={fulfillmentId} onChange={(event) => setFulfillmentId(event.target.value)} autoComplete="off" />
            </label>
            <fieldset>
              <legend>{copy.items}</legend>
              {lines.map((line, index) => (
                <div className="return-line-row" key={index}>
                  <label htmlFor={`account-return-line-${index}`}>
                    {copy.lineField}
                    <input id={`account-return-line-${index}`} value={line.lineId} onChange={(event) => updateLine(index, { lineId: event.target.value })} autoComplete="off" />
                  </label>
                  <label htmlFor={`account-return-qty-${index}`}>
                    {copy.qtyField}
                    <input id={`account-return-qty-${index}`} type="number" min={1} max={99} value={line.qty} onChange={(event) => updateLine(index, { qty: event.target.value })} />
                  </label>
                  <label htmlFor={`account-return-line-reason-${index}`}>
                    {copy.lineReasonField}
                    <input id={`account-return-line-reason-${index}`} value={line.reason} onChange={(event) => updateLine(index, { reason: event.target.value })} autoComplete="off" />
                  </label>
                  {lines.length > 1 && (
                    <button className="text-button" type="button" onClick={() => setLines((current) => current.filter((_, position) => position !== index))}>
                      {fill(copy.removeLine, { index: index + 1 })}
                    </button>
                  )}
                </div>
              ))}
              <button className="text-button" type="button" onClick={() => setLines((current) => [...current, emptyLine()])}>
                {copy.addLine}
              </button>
            </fieldset>
            <label htmlFor="account-return-reason">
              {copy.reasonField}
              <input id="account-return-reason" value={reason} onChange={(event) => setReason(event.target.value)} autoComplete="off" />
            </label>
            <button className={submitting ? "button is-loading" : "button"} type="submit" aria-busy={submitting} disabled={submitting}>
              {submitting ? copy.submitting : copy.submit}
            </button>
            {formError && <p className="form-error" role="alert">{formError}</p>}
            {formSuccess && <p className="form-notice" role="status">{formSuccess}</p>}
          </form>
        )}
      </div>
    </section>
  );
}
