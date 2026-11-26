const SESSION_KEY = "sirocommerce:analytics-session";

/**
 * Pseudonymous browser-session id shared by analytics beacons and consent
 * records. The backend hashes it before storage, so it is never PII.
 */
export function storefrontSessionId(): string {
  if (typeof window === "undefined") return "server";
  try {
    const existing = window.sessionStorage.getItem(SESSION_KEY);
    if (existing) return existing;
    const value = crypto.randomUUID();
    window.sessionStorage.setItem(SESSION_KEY, value);
    return value;
  } catch {
    return crypto.randomUUID();
  }
}
