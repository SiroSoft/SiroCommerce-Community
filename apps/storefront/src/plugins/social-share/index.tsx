"use client";

import { useEffect, useState } from "react";
import type { CSSProperties } from "react";
import type { SlotProps } from "@/slots";

export const SOCIAL_SHARE_ID = "siro/social-share";

const NETWORKS = ["facebook", "x", "whatsapp", "telegram", "email", "copy"] as const;

type Network = (typeof NETWORKS)[number];

const NETWORK_LABELS: Record<Network, string> = {
  facebook: "Facebook",
  x: "X",
  whatsapp: "WhatsApp",
  telegram: "Telegram",
  email: "Email",
  copy: "Copy link",
};

const NETWORK_ICONS: Record<Network, string> = {
  facebook: "M13 3h4V0h-4a5 5 0 0 0-5 5v3H5v4h3v10h4V12h3l1-4h-4V5a2 2 0 0 1 2-2Z",
  x: "M18.9 2H22l-7.3 8.3L23.2 22h-6.7l-5.2-6.8L5.4 22H2.3l7.8-8.9L1.2 2h6.9l4.7 6.2L18.9 2Zm-1.2 18h1.8L7.2 3.9H5.3L17.7 20Z",
  whatsapp: "M12 2a10 10 0 0 0-8.5 15.3L2 22l4.8-1.4A10 10 0 1 0 12 2Zm0 2a8 8 0 1 1-4.2 14.8l-.3-.2-2.5.7.7-2.4-.2-.3A8 8 0 0 1 12 4Z",
  telegram: "M21.9 4.3 18.7 20c-.2 1-.9 1.3-1.7.8l-4.7-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.3-4.8L17.8 7c.4-.4-.1-.6-.6-.2L6.3 13.3 1.6 11.8c-1-.3-1-1 .2-1.5L20.5 3c.9-.3 1.6.2 1.4 1.3Z",
  email: "M3 5h18a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm9 7 8-4.5V7l-8 4.5L4 7v.5L12 12Z",
  copy: "M8 8h11v11H8V8Zm-3-3h11v2H7v9H5V5Z",
};

interface SocialShareConfig {
  enabled: boolean;
  networks: Network[];
  label: string;
}

type CopyState = "idle" | "copied" | "error";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isNetwork(value: unknown): value is Network {
  return typeof value === "string" && (NETWORKS as readonly string[]).includes(value);
}

function readText(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

function parsePayload(raw: unknown): SocialShareConfig | null {
  if (!isRecord(raw)) return null;
  const payload = isRecord(raw.data) ? raw.data : raw;
  const networks: Network[] = [];
  if (Array.isArray(payload.networks)) {
    for (const entry of payload.networks) {
      if (isNetwork(entry) && !networks.includes(entry)) networks.push(entry);
    }
  }
  return {
    enabled: payload.enabled === true,
    networks,
    label: readText(payload.label) || "Share this product",
  };
}

function apiBase(): string {
  const base = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "";
  return base.replace(/\/+$/, "");
}

function buildHref(network: Network, url: string, title: string): string | null {
  const encodedUrl = encodeURIComponent(url);
  switch (network) {
    case "facebook":
      return `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
    case "x":
      return `https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodeURIComponent(title)}`;
    case "whatsapp":
      return `https://wa.me/?text=${encodeURIComponent(`${title} ${url}`)}`;
    case "telegram":
      return `https://t.me/share/url?url=${encodedUrl}&text=${encodeURIComponent(title)}`;
    case "email":
      return `mailto:?subject=${encodeURIComponent(title)}&body=${encodedUrl}`;
    case "copy":
      return null;
  }
}

function fallbackCopy(text: string): void {
  const textarea = document.createElement("textarea");
  textarea.value = text;
  textarea.setAttribute("readonly", "");
  textarea.style.position = "fixed";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  textarea.select();
  const copied = document.execCommand("copy");
  document.body.removeChild(textarea);
  if (!copied) throw new Error("Copy failed");
}

const containerStyle: CSSProperties = {
  display: "flex",
  flexWrap: "wrap",
  alignItems: "center",
  gap: 8,
  margin: "16px 0",
};

const labelStyle: CSSProperties = {
  fontSize: 14,
  fontWeight: 700,
  marginRight: 4,
};

const actionStyle: CSSProperties = {
  display: "inline-flex",
  alignItems: "center",
  gap: 6,
  padding: "6px 10px",
  borderRadius: 999,
  border: "1px solid #d1d5db",
  background: "#ffffff",
  color: "#111827",
  textDecoration: "none",
  fontSize: 13,
  fontWeight: 600,
  cursor: "pointer",
};

const feedbackStyle: CSSProperties = {
  fontSize: 13,
  fontWeight: 600,
  color: "#047857",
};

function NetworkIcon({ network }: { network: Network }) {
  return (
    <svg width={16} height={16} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
      <path d={NETWORK_ICONS[network]} />
    </svg>
  );
}

/**
 * PDP share bar. Fetches the public payload for the active store/channel and
 * reads `window.location.href` plus `document.title` inside effects/handlers
 * only, so server rendering stays safe. Hides itself when disabled, empty or on
 * a failed request.
 */
export function SocialShare({ scope, locale }: SlotProps) {
  const [config, setConfig] = useState<SocialShareConfig | null>(null);
  const [pageUrl, setPageUrl] = useState("");
  const [pageTitle, setPageTitle] = useState("");
  const [copyState, setCopyState] = useState<CopyState>("idle");

  useEffect(() => {
    setPageUrl(window.location.href);
    setPageTitle(document.title);
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    const storeId = (scope ?? "").trim() || process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID || "default";
    const channelId = process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web";
    const requestLocale = (locale ?? "").trim() || process.env.NEXT_PUBLIC_STOREFRONT_LOCALE || "en";

    fetch(`${apiBase()}/api/ext/siro/social-share/public`, {
      headers: {
        Accept: "application/json",
        "X-Store-Id": storeId,
        "X-Channel-Id": channelId,
        "X-Locale": requestLocale,
        "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
      },
      cache: "no-store",
      signal: controller.signal,
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((json: unknown) => {
        if (!controller.signal.aborted) setConfig(parsePayload(json));
      })
      .catch(() => {
        if (!controller.signal.aborted) setConfig(null);
      });

    return () => controller.abort();
  }, [scope, locale]);

  useEffect(() => {
    if (copyState === "idle") return;
    const timer = window.setTimeout(() => setCopyState("idle"), 2500);
    return () => window.clearTimeout(timer);
  }, [copyState]);

  const copyLink = async () => {
    try {
      if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(pageUrl);
      } else {
        fallbackCopy(pageUrl);
      }
      setCopyState("copied");
    } catch {
      setCopyState("error");
    }
  };

  if (!config || !config.enabled || config.networks.length === 0 || pageUrl === "") return null;

  const title = pageTitle.trim() || config.label;

  return (
    <nav
      className="social-share"
      data-testid="social-share"
      data-widget-id={SOCIAL_SHARE_ID}
      aria-label={config.label}
      style={containerStyle}
    >
      <span style={labelStyle}>{config.label}</span>
      {config.networks.map((network) => {
        const label = NETWORK_LABELS[network];
        const accessibleLabel = `${config.label}: ${label}`;
        if (network === "copy") {
          return (
            <button
              key={network}
              type="button"
              onClick={() => void copyLink()}
              aria-label={accessibleLabel}
              data-network={network}
              style={actionStyle}
            >
              <NetworkIcon network={network} />
              <span>{label}</span>
            </button>
          );
        }
        const href = buildHref(network, pageUrl, title);
        if (href === null) return null;
        return (
          <a
            key={network}
            href={href}
            target={network === "email" ? undefined : "_blank"}
            rel="noopener noreferrer"
            aria-label={accessibleLabel}
            data-network={network}
            style={actionStyle}
          >
            <NetworkIcon network={network} />
            <span>{label}</span>
          </a>
        );
      })}
      {copyState === "copied" ? <span role="status" aria-live="polite" style={feedbackStyle}>Link copied</span> : null}
      {copyState === "error" ? <span role="status" aria-live="polite" style={{ ...feedbackStyle, color: "#b91c1c" }}>Copy failed</span> : null}
    </nav>
  );
}
