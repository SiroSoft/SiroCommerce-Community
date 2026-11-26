"use client";

import { useEffect, useState } from "react";
import type { CSSProperties } from "react";
import type { SlotProps } from "@/slots";

export const CONTACT_WIDGET_ID = "siro/contact-widget";

export interface ContactWidgetLinks {
  zalo: string | null;
  whatsapp: string | null;
  email: string | null;
}

export interface ContactWidgetData {
  enabled: boolean;
  label: string;
  links: ContactWidgetLinks;
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value === "object" && value !== null && !Array.isArray(value)) {
    return value as Record<string, unknown>;
  }
  return null;
}

function readText(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const text = value.trim();
  return text.length > 0 ? text : null;
}

function parsePayload(raw: unknown): ContactWidgetData | null {
  const source = asRecord(raw);
  if (!source) return null;
  const payload = asRecord(source.data) ?? source;
  const links = asRecord(payload.links) ?? {};
  return {
    enabled: payload.enabled === true,
    label: readText(payload.label) ?? "Contact us",
    links: {
      zalo: readText(links.zalo),
      whatsapp: readText(links.whatsapp),
      email: readText(links.email),
    },
  };
}

function hasLinks(links: ContactWidgetLinks): boolean {
  return Boolean(links.zalo ?? links.whatsapp ?? links.email);
}

const containerStyle: CSSProperties = {
  position: "fixed",
  right: 16,
  bottom: 16,
  zIndex: 60,
  display: "flex",
  flexDirection: "column",
  alignItems: "flex-end",
  gap: 8,
};

function linkStyle(background: string): CSSProperties {
  return {
    display: "inline-flex",
    alignItems: "center",
    gap: 6,
    padding: "8px 12px",
    borderRadius: 999,
    background,
    color: "#ffffff",
    textDecoration: "none",
    fontSize: 13,
    fontWeight: 600,
    boxShadow: "0 6px 16px rgba(0, 0, 0, 0.2)",
  };
}

/**
 * Floating contact widget for the footer slot. Fetches the public payload for
 * the active store/channel and hides itself when disabled, empty or on error.
 */
export function ContactWidget({ scope, locale }: SlotProps) {
  const [data, setData] = useState<ContactWidgetData | null>(null);

  useEffect(() => {
    const base = (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "").trim();

    const controller = new AbortController();
    const storeId = (scope ?? "").trim() || (process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default");
    const channelId = process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web";
    const requestLocale = (locale ?? "").trim() || (process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en");

    fetch(`${base.replace(/\/$/, "")}/api/ext/siro/contact-widget/public`, {
      headers: {
        "X-Store-Id": storeId,
        "X-Channel-Id": channelId,
        "X-Locale": requestLocale,
      },
      cache: "no-store",
      signal: controller.signal,
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((json: unknown) => {
        if (!controller.signal.aborted) setData(parsePayload(json));
      })
      .catch(() => {
        if (!controller.signal.aborted) setData(null);
      });

    return () => controller.abort();
  }, [scope, locale]);

  if (!data || !data.enabled || !hasLinks(data.links)) return null;

  const { label, links } = data;

  return (
    <nav
      aria-label={label}
      data-testid="contact-widget"
      data-widget-id={CONTACT_WIDGET_ID}
      style={containerStyle}
    >
      {links.zalo ? (
        <a
          href={links.zalo}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={`${label}: Zalo`}
          data-channel="zalo"
          style={linkStyle("#0068ff")}
        >
          Zalo
        </a>
      ) : null}
      {links.whatsapp ? (
        <a
          href={links.whatsapp}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={`${label}: WhatsApp`}
          data-channel="whatsapp"
          style={linkStyle("#25d366")}
        >
          WhatsApp
        </a>
      ) : null}
      {links.email ? (
        <a
          href={`mailto:${links.email}`}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={`${label}: Email`}
          data-channel="email"
          style={linkStyle("#111827")}
        >
          Email
        </a>
      ) : null}
    </nav>
  );
}
