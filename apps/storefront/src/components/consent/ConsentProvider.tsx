"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import { usePathname } from "next/navigation";
import { useCopy } from "@/lib/i18n-client";
import {
  applyConsentChoices,
  CONSENT_SETTINGS_EVENT,
  fallbackConsentPolicy,
  fetchConsentPolicy,
  readStoredConsent,
  submitConsent,
  writeStoredConsent,
  type ConsentChoices,
  type ConsentPolicy,
} from "@/lib/consent";

const SUPPRESSED_PATHS = ["/cart", "/checkout"];

function suppressesBanner(pathname: string | null): boolean {
  if (!pathname) return false;
  return SUPPRESSED_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`));
}

type ConsentCopy = ReturnType<typeof useCopy>["consent"];

function ConsentBanner({ copy, onAcceptAll, onNecessaryOnly, onPreferences }: {
  copy: ConsentCopy;
  onAcceptAll: () => void;
  onNecessaryOnly: () => void;
  onPreferences: () => void;
}) {
  return <section className="consent-banner" role="region" aria-label={copy.regionLabel}>
    <div className="consent-banner-copy">
      <h2>{copy.title}</h2>
      <p>{copy.body}</p>
      <p className="consent-banner-links">
        <a href="/pages/privacy">{copy.privacyPolicy}</a>
        <a href="/pages/cookie-policy">{copy.cookiePolicy}</a>
      </p>
    </div>
    <div className="consent-banner-actions" role="group" aria-label={copy.regionLabel}>
      <button type="button" className="button" onClick={onAcceptAll}>{copy.acceptAll}</button>
      <button type="button" className="secondary-button" onClick={onNecessaryOnly}>{copy.necessaryOnly}</button>
      <button type="button" className="text-button" onClick={onPreferences}>{copy.preferences}</button>
    </div>
  </section>;
}

function ConsentPreferences({ copy, initial, onClose, onSave }: {
  copy: ConsentCopy;
  initial: ConsentChoices;
  onClose: () => void;
  onSave: (choices: ConsentChoices) => void;
}) {
  const [analytics, setAnalytics] = useState(initial.analytics);
  const [marketing, setMarketing] = useState(initial.marketing);
  return <section className="consent-preferences" role="dialog" aria-modal="false" aria-labelledby="consent-preferences-title">
    <div className="consent-preferences-heading">
      <h2 id="consent-preferences-title">{copy.preferencesTitle}</h2>
      <button type="button" className="text-button" onClick={onClose} aria-label={copy.close}>{copy.close}</button>
    </div>
    <p>{copy.preferencesBody}</p>
    <div className="consent-preference-row">
      <label>
        <input type="checkbox" checked disabled aria-describedby="consent-necessary-hint" />
        <span><strong>{copy.necessary}</strong><small id="consent-necessary-hint">{copy.necessaryHint}</small></span>
      </label>
    </div>
    <div className="consent-preference-row">
      <label>
        <input type="checkbox" checked={analytics} onChange={(event) => setAnalytics(event.target.checked)} />
        <span><strong>{copy.analytics}</strong><small>{copy.analyticsHint}</small></span>
      </label>
    </div>
    <div className="consent-preference-row">
      <label>
        <input type="checkbox" checked={marketing} onChange={(event) => setMarketing(event.target.checked)} />
        <span><strong>{copy.marketing}</strong><small>{copy.marketingHint}</small></span>
      </label>
    </div>
    <div className="consent-preferences-actions">
      <button type="button" className="button" onClick={() => onSave({ necessary: true, analytics, marketing })}>{copy.save}</button>
      <button type="button" className="secondary-button" onClick={onClose}>{copy.cancel}</button>
    </div>
  </section>;
}

export function ConsentProvider({ children }: { children: ReactNode }) {
  const copy = useCopy().consent;
  const pathname = usePathname();
  const [policy, setPolicy] = useState<ConsentPolicy>(() => fallbackConsentPolicy());
  const [choices, setChoices] = useState<ConsentChoices>({ necessary: true, analytics: false, marketing: false });
  const [decided, setDecided] = useState(false);
  const [preferencesOpen, setPreferencesOpen] = useState(false);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    let active = true;
    void fetchConsentPolicy().then((next) => {
      if (!active) return;
      setPolicy(next);
      const stored = readStoredConsent(next.version);
      if (stored) {
        setChoices(stored.choices);
        setDecided(true);
        applyConsentChoices(stored.choices);
      } else {
        // A new policy version (or first visit) starts from a denied state.
        const denied: ConsentChoices = { necessary: true, analytics: false, marketing: false };
        setChoices(denied);
        setDecided(false);
        applyConsentChoices(denied);
      }
      setHydrated(true);
    });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    const open = () => setPreferencesOpen(true);
    window.addEventListener(CONSENT_SETTINGS_EVENT, open);
    return () => window.removeEventListener(CONSENT_SETTINGS_EVENT, open);
  }, []);

  const decide = useCallback((next: ConsentChoices) => {
    const normalized: ConsentChoices = {
      necessary: true,
      analytics: next.analytics === true,
      marketing: next.marketing === true,
    };
    writeStoredConsent(policy.version, normalized);
    applyConsentChoices(normalized);
    setChoices(normalized);
    setDecided(true);
    setPreferencesOpen(false);
    void submitConsent(policy.version, normalized);
  }, [policy.version]);

  const acceptAll = useCallback(() => decide({ necessary: true, analytics: true, marketing: true }), [decide]);
  const necessaryOnly = useCallback(() => decide({ necessary: true, analytics: false, marketing: false }), [decide]);
  const showBanner = hydrated && !decided && !suppressesBanner(pathname);

  return <>
    {children}
    {showBanner ? <ConsentBanner copy={copy} onAcceptAll={acceptAll} onNecessaryOnly={necessaryOnly} onPreferences={() => setPreferencesOpen(true)} /> : null}
    {preferencesOpen ? <ConsentPreferences copy={copy} initial={choices} onClose={() => setPreferencesOpen(false)} onSave={decide} /> : null}
  </>;
}
