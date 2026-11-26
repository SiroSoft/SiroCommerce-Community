import { setMarketingConsent } from "./marketing-consent";
import { setAnalyticsConsent } from "@/services/storefront-events";
import { storefrontSessionId } from "./storefront-session";

export type ConsentChoices = {
  necessary: true;
  analytics: boolean;
  marketing: boolean;
};

export type StoredConsent = {
  version: string;
  choices: ConsentChoices;
  decided_at: string;
};

export type ConsentPolicySection = { id: string; title: string; body: string };

export type ConsentPolicy = {
  version: string;
  updated_at: string | null;
  sections: ConsentPolicySection[];
};

export const CONSENT_SETTINGS_EVENT = "siro:open-consent-settings";

/** Decisions are scoped per policy version so a policy change re-prompts. */
export function consentStorageKey(version: string): string {
  return `siro-consent:${version}`;
}

export function fallbackConsentPolicy(): ConsentPolicy {
  const version = (process.env.NEXT_PUBLIC_CONSENT_POLICY_VERSION ?? "1").trim() || "1";
  return { version, updated_at: null, sections: [] };
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

export function readStoredConsent(version: string): StoredConsent | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(consentStorageKey(version));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Record<string, unknown>;
    const choices = parsed.choices;
    if (!choices || typeof choices !== "object") return null;
    const analytics = asBoolean((choices as Record<string, unknown>).analytics);
    const marketing = asBoolean((choices as Record<string, unknown>).marketing);
    if (analytics === undefined || marketing === undefined) return null;
    return {
      version,
      choices: { necessary: true, analytics, marketing },
      decided_at: typeof parsed.decided_at === "string" ? parsed.decided_at : "",
    };
  } catch {
    return null;
  }
}

export function writeStoredConsent(version: string, choices: ConsentChoices): StoredConsent {
  const stored: StoredConsent = { version, choices, decided_at: new Date().toISOString() };
  if (typeof window !== "undefined") {
    try {
      window.localStorage.setItem(consentStorageKey(version), JSON.stringify(stored));
      window.localStorage.removeItem("sirocommerce:analytics-consent-legacy");
    } catch {
      // Storage may be unavailable (private mode); the in-memory decision still applies.
    }
  }
  return stored;
}

/** Propagates a decision to the low-level analytics/marketing gates. */
export function applyConsentChoices(choices: ConsentChoices): void {
  setAnalyticsConsent(choices.analytics);
  setMarketingConsent(choices.marketing);
}

function apiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "").trim();
}

function sanitizePolicy(value: unknown): ConsentPolicy | null {
  if (!value || typeof value !== "object") return null;
  const root = value as Record<string, unknown>;
  const nested = root.data && typeof root.data === "object" ? (root.data as Record<string, unknown>) : root;
  const version = typeof nested.version === "string" && nested.version.trim() ? nested.version.trim() : "";
  if (!version) return null;
  const sections: ConsentPolicySection[] = [];
  if (Array.isArray(nested.sections)) {
    for (const entry of nested.sections) {
      if (!entry || typeof entry !== "object") continue;
      const section = entry as Record<string, unknown>;
      if (typeof section.id !== "string" || typeof section.title !== "string" || typeof section.body !== "string") continue;
      sections.push({ id: section.id, title: section.title, body: section.body });
    }
  }
  return {
    version,
    updated_at: typeof nested.updated_at === "string" ? nested.updated_at : null,
    sections,
  };
}

export async function fetchConsentPolicy(fetcher: typeof fetch = fetch): Promise<ConsentPolicy> {
  const baseUrl = apiBaseUrl();
  if (!baseUrl) return fallbackConsentPolicy();
  try {
    const response = await fetcher(`${baseUrl.replace(/\/$/, "")}/api/consent/policy`, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) return fallbackConsentPolicy();
    return sanitizePolicy(await response.json()) ?? fallbackConsentPolicy();
  } catch {
    return fallbackConsentPolicy();
  }
}

/** Best-effort API record; the local decision is authoritative for this browser. */
export async function submitConsent(policyVersion: string, choices: ConsentChoices): Promise<void> {
  const baseUrl = apiBaseUrl();
  if (!baseUrl) return;
  try {
    await fetch(`${baseUrl.replace(/\/$/, "")}/api/consent`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Session-Id": storefrontSessionId(),
      },
      body: JSON.stringify({
        necessary: true,
        analytics: choices.analytics,
        marketing: choices.marketing,
        policy_version: policyVersion,
      }),
      keepalive: true,
    });
  } catch {
    // Consent must never block navigation or checkout.
  }
}
