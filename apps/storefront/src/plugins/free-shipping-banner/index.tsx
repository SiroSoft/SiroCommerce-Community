"use client";

import { useEffect, useState } from "react";

interface FreeShippingBannerPayload {
  active: boolean;
  message: string;
}

function readPayload(value: unknown): FreeShippingBannerPayload | null {
  if (typeof value !== "object" || value === null || Array.isArray(value)) return null;
  const record = value as Record<string, unknown>;
  const message = typeof record.message === "string" ? record.message.trim() : "";
  return {
    active: record.active === true,
    message,
  };
}

function publicEndpoint(): string {
  const base = (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "").replace(/\/+$/, "");
  return `${base}/api/ext/siro/free-shipping-banner/public`;
}

function scopeHeaders(): HeadersInit {
  return {
    Accept: "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
  };
}

/**
 * Storefront cart banner. Fetches the public extension payload on the client
 * with `cache: "no-store"` and renders nothing until an active, non-empty
 * message exists. No raw HTML/JS injection.
 */
export function FreeShippingBanner() {
  const [banner, setBanner] = useState<FreeShippingBannerPayload | null>(null);

  useEffect(() => {
    const endpoint = publicEndpoint();
    if (endpoint === "") return;

    const controller = new AbortController();
    void (async () => {
      try {
        const response = await fetch(endpoint, {
          cache: "no-store",
          headers: scopeHeaders(),
          signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Free shipping banner request failed: ${response.status}`);
        const payload = readPayload(await response.json());
        if (payload?.active && payload.message !== "") {
          setBanner(payload);
        } else {
          setBanner(null);
        }
      } catch {
        if (!controller.signal.aborted) setBanner(null);
      }
    })();

    return () => controller.abort();
  }, []);

  if (banner === null) return null;

  return (
    <p
      role="status"
      aria-live="polite"
      className="free-shipping-banner"
      data-testid="free-shipping-banner"
    >
      {banner.message}
    </p>
  );
}
