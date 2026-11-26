"use client";

/**
 * Whether this browser ever held a session. The access token lives only in
 * memory, but the refresh token persists in localStorage under the same
 * prefix every storefront auth surface uses.
 */
const REFRESH_KEY = "siro_storefront_refresh_token";

export function hasStoredSession(): boolean {
  try {
    return (window.localStorage.getItem(REFRESH_KEY) ?? "").trim() !== "";
  } catch {
    return false;
  }
}
