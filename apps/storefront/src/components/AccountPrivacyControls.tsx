"use client";

import { useState } from "react";
import { customer } from "@/services/customer";
import { setAnalyticsConsent } from "@/services/storefront-events";
import { fill } from "@/lib/i18n";
import { useCopy } from "@/lib/i18n-client";

export function AccountPrivacyControls() {
  const copy = useCopy().privacy;
  const [status, setStatus] = useState<string>("");
  const [busy, setBusy] = useState(false);
  async function consent(granted: boolean) {
    setBusy(true);
    try { setAnalyticsConsent(granted); await customer().recordConsent(granted); setStatus(granted ? copy.enabled : copy.disabled); }
    catch { setStatus(copy.saveFailed); }
    finally { setBusy(false); }
  }
  async function deletion() {
    if (!window.confirm(copy.confirmDeletion)) return;
    setBusy(true);
    try { const result = await customer().requestDeletion(); setStatus(fill(copy.deletionDone, { state: result.state, records: result.financial_records })); }
    catch { setStatus(copy.deletionFailed); }
    finally { setBusy(false); }
  }
  async function exportData() {
    setBusy(true);
    try {
      const data = await customer().exportPrivacy();
      const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }));
      const link = document.createElement("a"); link.href = url; link.download = "sirocommerce-personal-data.json"; link.click(); URL.revokeObjectURL(url);
      setStatus(copy.exportReady);
    } catch { setStatus(copy.exportFailed); }
    finally { setBusy(false); }
  }
  return <section id="privacy" aria-labelledby="privacy-heading" className="account-panel">
    <div className="account-section-heading"><h2 id="privacy-heading">{copy.title}</h2><span className="muted">{copy.controls}</span></div>
    <p>{copy.intro}</p>
    <div className="consent-actions" role="group" aria-label={copy.consentGroup}>
      <button className="button" type="button" onClick={() => void consent(true)} disabled={busy}>{copy.allow}</button>
      <button className="secondary-button" type="button" onClick={() => void consent(false)} disabled={busy}>{copy.optOut}</button>
    </div>
    <div className="data-actions">
      <button className="secondary-button" type="button" onClick={() => void exportData()} disabled={busy}>{copy.download}</button>
      <button className="text-button danger-action" type="button" onClick={() => void deletion()} disabled={busy}>{copy.requestDeletion}</button>
    </div>
    {status && <p className="form-notice" role="status" aria-live="polite">{status}</p>}
  </section>;
}
