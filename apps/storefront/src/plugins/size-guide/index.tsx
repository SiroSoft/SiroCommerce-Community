"use client";

import { useEffect, useId, useRef, useState } from "react";
import type { SlotProps } from "@/slots";

interface SizeChart {
  category: string;
  title: string;
  columns: string[];
  rows: string[][];
  note: string | null;
}

type LoadState = "loading" | "ready" | "unavailable";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readString(data: SlotProps["data"], key: string): string {
  const value = data?.[key];
  return typeof value === "string" ? value.trim() : "";
}

function apiBase(): string {
  return (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "").replace(/\/+$/, "");
}

function scopeHeaders(locale?: string): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Store-Id": process.env.NEXT_PUBLIC_STOREFRONT_STORE_ID ?? "default",
    "X-Channel-Id": process.env.NEXT_PUBLIC_STOREFRONT_CHANNEL_ID ?? "web",
    "X-Locale": locale?.trim() || process.env.NEXT_PUBLIC_STOREFRONT_LOCALE || "en",
    "X-Currency": process.env.NEXT_PUBLIC_STOREFRONT_CURRENCY ?? "VND",
  };
  const feToken = process.env.NEXT_PUBLIC_FE_TOKEN;
  if (feToken) headers["X-Siro-FE"] = feToken;
  return headers;
}

function readStringArray(value: unknown): string[] | null {
  if (!Array.isArray(value)) return null;
  const result: string[] = [];
  for (const item of value) {
    if (typeof item !== "string") return null;
    result.push(item);
  }
  return result;
}

function parseChart(payload: unknown): SizeChart | null {
  if (!isRecord(payload) || payload.success !== true) return null;
  const data = payload.data;
  if (!isRecord(data)) return null;

  const title = typeof data.title === "string" ? data.title.trim() : "";
  const category = typeof data.category === "string" ? data.category.trim() : "";
  if (title === "" || category === "") return null;

  const columns = readStringArray(data.columns);
  if (columns === null || columns.length < 1 || columns.length > 8) return null;

  if (!Array.isArray(data.rows) || data.rows.length < 1 || data.rows.length > 50) return null;
  const rows: string[][] = [];
  for (const row of data.rows) {
    const cells = readStringArray(row);
    if (cells === null || cells.length !== columns.length) return null;
    rows.push(cells);
  }

  const note = typeof data.note === "string" && data.note.trim() !== "" ? data.note : null;
  return { category, title, columns, rows, note };
}

/**
 * PDP size guide. Reads `props.data.category`, fetches only that category's
 * active public chart with `cache: "no-store"`, and renders nothing while
 * loading, when the category has no chart, or when the API fails. Table cells
 * and notes render as text; no raw HTML injection.
 */
export function SizeGuideSlot({ locale, data }: SlotProps) {
  const headingId = useId();
  const dialogRef = useRef<HTMLDialogElement | null>(null);
  const category = readString(data, "category");
  const [chart, setChart] = useState<SizeChart | null>(null);
  const [loadState, setLoadState] = useState<LoadState>("loading");
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!category) {
      setChart(null);
      setLoadState("ready");
      return;
    }
    const controller = new AbortController();
    setLoadState("loading");
    const url = `${apiBase()}/api/ext/siro/size-guide/public?category=${encodeURIComponent(category)}`;
    void fetch(url, { headers: scopeHeaders(locale), cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(String(response.status));
        const payload: unknown = await response.json().catch(() => null);
        return parseChart(payload);
      })
      .then((next) => {
        setChart(next);
        setLoadState("ready");
      })
      .catch(() => {
        if (controller.signal.aborted) return;
        setChart(null);
        setLoadState("unavailable");
      });
    return () => controller.abort();
  }, [category, locale]);

  useEffect(() => {
    const node = dialogRef.current;
    if (!node) return;
    if (open) {
      if (typeof node.showModal === "function") {
        if (!node.open) node.showModal();
      } else if (!node.open) {
        node.setAttribute("open", "");
      }
    } else if (node.open) {
      node.close();
    }
  }, [open]);

  if (!category || loadState === "loading" || chart === null) return null;

  return (
    <section className="size-guide" data-testid="size-guide">
      <button className="button" type="button" onClick={() => setOpen(true)}>
        Size guide
      </button>
      <dialog
        ref={dialogRef}
        className="size-guide-dialog"
        aria-labelledby={`${headingId}-title`}
        onClose={() => setOpen(false)}
        onCancel={() => setOpen(false)}
      >
        <div className="size-guide-body">
          <div className="section-heading">
            <h2 id={`${headingId}-title`}>{chart.title}</h2>
            <button className="button" type="button" onClick={() => setOpen(false)} aria-label="Close size guide">
              Close
            </button>
          </div>
          <table>
            <caption>{chart.title}</caption>
            <thead>
              <tr>
                {chart.columns.map((column, index) => (
                  <th key={`${column}-${index}`} scope="col">
                    {column}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {chart.rows.map((row, rowIndex) => (
                <tr key={rowIndex}>
                  {row.map((cell, cellIndex) =>
                    cellIndex === 0 ? (
                      <th key={cellIndex} scope="row">
                        {cell}
                      </th>
                    ) : (
                      <td key={cellIndex}>{cell}</td>
                    ),
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          {chart.note ? <p className="muted">{chart.note}</p> : null}
        </div>
      </dialog>
    </section>
  );
}
