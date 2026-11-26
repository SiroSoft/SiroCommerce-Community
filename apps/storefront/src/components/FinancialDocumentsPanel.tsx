"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { customer, type FinancialDocuments } from "@/services/customer";
import { formatMinor } from "@/lib/money";
import { useCopy, useLocale } from "@/lib/i18n-client";

export function FinancialDocumentsPanel({ orderId: providedOrderId }: { orderId?: string } = {}) {
  const copy = useCopy().finance;
  const locale = useLocale();
  const params = useSearchParams();
  const orderId = providedOrderId || params.get("order_id") || "";
  const [state, setState] = useState<"loading" | "ready" | "unavailable">("loading");
  const [data, setData] = useState<FinancialDocuments>();
  useEffect(() => { if (!orderId) { setState("unavailable"); return; } let active = true; customer().getFinancialDocuments(orderId).then((value) => { if (active) { setData(value); setState("ready"); } }).catch(() => { if (active) setState("unavailable"); }); return () => { active = false; }; }, [orderId]);
  return <section className="account-panel" aria-labelledby="financial-documents-title"><div className="account-section-heading"><h3 id="financial-documents-title">{copy.title}</h3><span className="muted">{copy.record}</span></div>{state === "loading" && <p className="muted" role="status">{copy.loading}</p>}{state === "unavailable" && <p className="account-unavailable">{copy.unavailable}</p>}{state === "ready" && data && <><p>{copy.total} <strong>{formatMinor(data.totals.grand_total_minor, data.currency, locale)}</strong> · {copy.refunded} <strong>{formatMinor(data.totals.refunded_minor, data.currency, locale)}</strong></p>{!data.available && <p className="muted">{copy.noDocuments}</p>}{[...data.invoices, ...data.credit_memos].map((document) => <p key={document.id}><strong>{document.number}</strong> · {document.state} · {formatMinor(document.amount_minor, document.currency, locale)} <span className="muted">· {copy.downloadUnavailable}</span></p>)}{data.refund_timeline.length > 0 && <ol className="engagement-timeline">{data.refund_timeline.map((refund) => <li key={refund.id}><strong>{copy.refundWord} {refund.state}</strong><span>{formatMinor(refund.amount_minor, refund.currency, locale)}{refund.reason ? ` · ${refund.reason}` : ""}</span></li>)}</ol>}</>}</section>;
}
