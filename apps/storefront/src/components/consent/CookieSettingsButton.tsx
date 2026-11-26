"use client";

import { useCopy } from "@/lib/i18n-client";
import { CONSENT_SETTINGS_EVENT } from "@/lib/consent";

export function CookieSettingsButton({ className = "text-button", label }: { className?: string; label?: string }) {
  const copy = useCopy().consent;
  return <button
    type="button"
    className={className}
    onClick={() => window.dispatchEvent(new Event(CONSENT_SETTINGS_EVENT))}
  >{label ?? copy.cookieSettings}</button>;
}
