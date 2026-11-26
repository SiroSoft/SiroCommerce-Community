"use client";

import { useEffect, useId, useRef, useState } from "react";
import type { CSSProperties } from "react";
import type { SlotProps } from "@/slots";

export const STORE_LOCATOR_ID = "siro/store-locator";

export interface StoreLocation {
  id: string;
  name: string;
  address: string;
  city: string;
  phone: string | null;
  hours: string | null;
  latitude: number | null;
  longitude: number | null;
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

function readNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }
  return null;
}

function readLocation(value: unknown): StoreLocation | null {
  const source = asRecord(value);
  if (!source) return null;
  const id = readText(source.id);
  const name = readText(source.name);
  const address = readText(source.address);
  if (!id || !name || !address) return null;
  return {
    id,
    name,
    address,
    city: readText(source.city) ?? "",
    phone: readText(source.phone),
    hours: readText(source.hours),
    latitude: readNumber(source.latitude),
    longitude: readNumber(source.longitude),
  };
}

export function parseStoreLocations(raw: unknown): StoreLocation[] {
  const source = asRecord(raw);
  const list = Array.isArray(raw)
    ? raw
    : source && Array.isArray(source.data)
      ? source.data
      : null;
  if (!list) return [];
  const locations: StoreLocation[] = [];
  for (const entry of list) {
    const location = readLocation(entry);
    if (location) locations.push(location);
  }
  return locations;
}

function apiBase(): string {
  return (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "").replace(/\/$/, "");
}

function scopeHeaders(scope: string | undefined, locale: string | undefined): Record<string, string> {
  return {
    Accept: "application/json",
    "X-Store-Id": (scope ?? "").trim() || (process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default"),
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": (locale ?? "").trim() || (process.env.NEXT_PUBLIC_STOREFRONT_LOCALE ?? "en"),
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
  };
}

function mapUrl(latitude: number, longitude: number): string {
  return `https://www.google.com/maps?q=${latitude},${longitude}`;
}

const triggerStyle: CSSProperties = {
  display: "inline-flex",
  alignItems: "center",
  gap: 6,
  padding: "8px 14px",
  borderRadius: 999,
  border: "1px solid #111827",
  background: "#111827",
  color: "#ffffff",
  fontSize: 13,
  fontWeight: 600,
  cursor: "pointer",
};

const overlayStyle: CSSProperties = {
  position: "fixed",
  inset: 0,
  zIndex: 80,
  display: "flex",
  alignItems: "center",
  justifyContent: "center",
  padding: 16,
  background: "rgba(0, 0, 0, 0.55)",
};

const dialogStyle: CSSProperties = {
  width: "min(100%, 560px)",
  maxHeight: "80vh",
  overflowY: "auto",
  borderRadius: 12,
  background: "#ffffff",
  color: "#111827",
  boxShadow: "0 20px 50px rgba(0, 0, 0, 0.3)",
};

const headerStyle: CSSProperties = {
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 12,
  padding: "16px 20px",
  borderBottom: "1px solid #e5e7eb",
};

const closeStyle: CSSProperties = {
  display: "inline-flex",
  alignItems: "center",
  justifyContent: "center",
  width: 32,
  height: 32,
  borderRadius: 999,
  border: "1px solid #d1d5db",
  background: "#ffffff",
  color: "#111827",
  fontSize: 18,
  lineHeight: 1,
  cursor: "pointer",
};

const listStyle: CSSProperties = {
  margin: 0,
  padding: "8px 20px 20px",
  listStyle: "none",
  display: "flex",
  flexDirection: "column",
  gap: 16,
};

const itemStyle: CSSProperties = {
  paddingTop: 12,
  borderTop: "1px solid #f3f4f6",
};

/**
 * Footer store locator. Fetches the active store/channel locations and hides
 * itself when the list is empty or the request fails. The "Stores" button
 * opens an accessible modal dialog that can be closed with its button, the
 * overlay or Escape.
 */
export function StoreLocatorFooter({ scope, locale }: SlotProps) {
  const headingId = useId();
  const closeRef = useRef<HTMLButtonElement | null>(null);
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const [locations, setLocations] = useState<StoreLocation[]>([]);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    void fetch(`${apiBase()}/api/ext/siro/store-locator/public`, {
      headers: scopeHeaders(scope, locale),
      cache: "no-store",
      signal: controller.signal,
    })
      .then(async (response) => {
        if (!response.ok) throw new Error(`Store locator request failed: ${response.status}`);
        const payload: unknown = await response.json().catch(() => null);
        return parseStoreLocations(payload);
      })
      .then((next) => {
        if (!controller.signal.aborted) setLocations(next);
      })
      .catch(() => {
        if (!controller.signal.aborted) setLocations([]);
      });

    return () => controller.abort();
  }, [scope, locale]);

  useEffect(() => {
    if (!open) return;
    closeRef.current?.focus();
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        setOpen(false);
        triggerRef.current?.focus();
      }
    };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open]);

  if (locations.length === 0) return null;

  const close = () => {
    setOpen(false);
    triggerRef.current?.focus();
  };

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        data-testid="store-locator-trigger"
        data-widget-id={STORE_LOCATOR_ID}
        onClick={() => setOpen(true)}
        style={triggerStyle}
      >
        Stores
      </button>
      {open ? (
        <div
          style={overlayStyle}
          onClick={(event) => {
            if (event.target === event.currentTarget) close();
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby={headingId}
            data-testid="store-locator-dialog"
            style={dialogStyle}
          >
            <div style={headerStyle}>
              <h2 id={headingId} style={{ margin: 0, fontSize: 18 }}>
                Stores
              </h2>
              <button
                ref={closeRef}
                type="button"
                aria-label="Close store list"
                data-testid="store-locator-close"
                onClick={close}
                style={closeStyle}
              >
                &times;
              </button>
            </div>
            <ul style={listStyle}>
              {locations.map((location) => {
                const latitude = location.latitude;
                const longitude = location.longitude;
                const hasCoordinates = latitude !== null && longitude !== null;
                return (
                  <li key={location.id} style={itemStyle}>
                    <h3 style={{ margin: "0 0 6px", fontSize: 15 }}>{location.name}</h3>
                    <p style={{ margin: "0 0 4px" }}>{location.address}</p>
                    {location.city ? <p style={{ margin: "0 0 4px" }}>{location.city}</p> : null}
                    {location.phone ? (
                      <p style={{ margin: "0 0 4px" }}>
                        <a href={`tel:${location.phone.replace(/\s+/g, "")}`}>{location.phone}</a>
                      </p>
                    ) : null}
                    {location.hours ? <p style={{ margin: "0 0 4px" }}>{location.hours}</p> : null}
                    {hasCoordinates ? (
                      <a
                        href={mapUrl(latitude, longitude)}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={{ display: "inline-block", marginTop: 4, fontSize: 13, fontWeight: 600 }}
                      >
                        View on map
                      </a>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          </div>
        </div>
      ) : null}
    </>
  );
}

export const StoreLocator = StoreLocatorFooter;
