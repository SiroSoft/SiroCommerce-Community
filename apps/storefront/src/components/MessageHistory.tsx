"use client";

import { useEffect, useState } from "react";
import { customer, type MessageHistoryItem } from "@/services/customer";
import { useCopy } from "@/lib/i18n-client";
import { hasStoredSession } from "@/lib/session-hint";

export function MessageHistory() {
  const copy = useCopy();
  const [items, setItems] = useState<MessageHistoryItem[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "unavailable">("loading");
  useEffect(() => {
    let active = true;
    let settled = false;
    const load = () => {
      if (!active || settled) return;
      settled = true;
      setState("loading");
      customer().getMessageHistory().then((result) => { if (active) { setItems(result.items); setState("ready"); } }).catch(() => { if (active) setState("unavailable"); });
    };
    // Without any stored session a request is doomed to 401. A reload
    // restores the session asynchronously, and a same-page sign-in can arrive
    // at any time — wait for the session event instead of erroring noisily,
    // and show the signed-out state only when no session can be coming.
    if (!hasStoredSession()) setState("unavailable");
    const fallback = hasStoredSession() ? window.setTimeout(load, 2500) : undefined;
    window.addEventListener("siro:session-updated", load);
    return () => { active = false; settled = true; if (fallback !== undefined) window.clearTimeout(fallback); window.removeEventListener("siro:session-updated", load); };
  }, []);
  return <section className="account-panel" id="messages" aria-labelledby="message-history-title"><div className="account-section-heading"><h2 id="message-history-title">{copy.messages.title}</h2><span className="muted">{copy.messages.privateNote}</span></div>{state === "loading" && <p role="status">{copy.messages.loading}</p>}{state === "unavailable" && <p className="account-unavailable" role="note">{copy.messages.unavailable}</p>}{state === "ready" && (items.length ? <ol className="engagement-timeline">{items.map((item, index) => <li key={`${item.event}-${item.created_at ?? index}`}><strong>{item.event.replaceAll("_", " ")}</strong><span>{item.status === "sent" ? copy.messages.sent : item.status === "processing" ? copy.messages.processing : copy.messages.notDelivered}{item.sent_at ? ` · ${new Date(item.sent_at).toLocaleString()}` : ""}</span></li>)}</ol> : <p className="muted">{copy.messages.none}</p>)}</section>;
}
