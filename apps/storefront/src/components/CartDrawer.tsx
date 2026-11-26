"use client";

import Link from "next/link";
import { useEffect, useId, useRef, useState } from "react";
import type { CartLineSnapshot } from "@/lib/cart-session";
import { readCartCookies, toCartSnapshot } from "@/lib/cart-session";
import { formatMinor } from "@/lib/money";
import { useCopy, useLocale } from "@/lib/i18n-client";

export type CartDrawerLine = { lineId?: string; productId?: string; name: string; quantity: number; unitPriceMinor: number; lineTotalMinor?: number; currency?: string; slug?: string };

type CartOpenDetail = { line?: CartDrawerLine };
type CartPayload = { success?: boolean; data?: Record<string, unknown> };

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

function cartApiBase(): string {
  const configured = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL;
  return configured ? configured.replace(/\/$/, "") : "";
}

function cartHeaders(token?: string): Record<string, string> {
  const dataset = typeof document === "undefined" ? undefined : document.documentElement.dataset;
  return {
    Accept: "application/json; version=v1",
    "Content-Type": "application/json",
    ...(dataset?.storeId ? { "X-Store-Id": dataset.storeId } : {}),
    ...(dataset?.channelId ? { "X-Channel-Id": dataset.channelId } : {}),
    ...(dataset?.locale ? { "X-Locale": dataset.locale } : {}),
    ...(dataset?.currency ? { "X-Currency": dataset.currency } : {}),
    ...(token ? { "X-Cart-Token": token } : {}),
  };
}

async function cartRequest(path: string, init: RequestInit = {}, token?: string): Promise<Record<string, unknown>> {
  const response = await fetch(`${cartApiBase()}${path}`, {
    ...init,
    headers: { ...cartHeaders(token), ...(init.headers ?? {}) },
    cache: "no-store",
  });
  const payload = (await response.json().catch(() => undefined)) as CartPayload | undefined;
  if (!response.ok || payload?.success !== true || !payload.data) throw new Error(`cart_request_failed_${response.status}`);
  return payload.data;
}

function lineIdentity(line: CartDrawerLine): string {
  return line.lineId ?? line.slug ?? line.name;
}

function sameLine(entry: CartDrawerLine, line: CartDrawerLine): boolean {
  if (entry.lineId && line.lineId) return entry.lineId === line.lineId;
  if (entry.slug && line.slug) return entry.slug === line.slug;
  return entry.name === line.name;
}

function mergeLine(lines: CartDrawerLine[], line: CartDrawerLine): CartDrawerLine[] {
  const index = lines.findIndex((entry) => sameLine(entry, line));
  if (index === -1) return [...lines, line];
  return lines.map((entry, position) => position === index ? { ...entry, ...(line.lineId ? { lineId: line.lineId } : {}), ...(line.slug ? { slug: line.slug } : {}), quantity: entry.quantity + line.quantity, unitPriceMinor: line.unitPriceMinor || entry.unitPriceMinor, currency: line.currency ?? entry.currency } : entry);
}

function snapshotLine(line: CartLineSnapshot, currency: string): CartDrawerLine {
  return { ...(line.lineId ? { lineId: line.lineId } : {}), productId: line.productId, name: line.name, quantity: line.quantity, unitPriceMinor: line.unitPriceMinor, lineTotalMinor: line.lineTotalMinor, currency, ...(line.slug ? { slug: line.slug } : {}) };
}

export function CartDrawer({ initialLines = [], currency = "USD" }: { initialLines?: CartLineSnapshot[]; currency?: string }) {
  const copy = useCopy();
  const locale = useLocale();
  const [open, setOpen] = useState(false);
  const [lines, setLines] = useState<CartDrawerLine[]>(() => initialLines.map((line) => snapshotLine(line, currency)));
  const [busy, setBusy] = useState<string | undefined>();
  const [notice, setNotice] = useState("");
  const panelRef = useRef<HTMLElement | null>(null);
  const titleId = useId();

  async function refreshCart() {
    const { cartId, cartToken } = readCartCookies();
    if (!cartId) return;
    try {
      const data = await cartRequest(`/api/carts/${encodeURIComponent(cartId)}`, { method: "GET" }, cartToken);
      const snapshot = toCartSnapshot(cartId, data, currency, cartToken);
      setLines(snapshot.lines.map((line) => snapshotLine(line, snapshot.currency)));
    } catch {
      return;
    }
  }

  useEffect(() => {
    const onOpen = (event: Event) => {
      const detail = (event as CustomEvent<CartOpenDetail>).detail;
      if (detail?.line) setLines((current) => mergeLine(current, detail.line as CartDrawerLine));
      setOpen(true);
      void refreshCart();
    };
    window.addEventListener("cart:open", onOpen);
    return () => window.removeEventListener("cart:open", onOpen);
  }, [currency]);

  useEffect(() => {
    if (!open) return;
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const panel = panelRef.current;
    const focusables = () => Array.from(panel?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []);
    const closeButton = panel?.querySelector<HTMLElement>("[data-drawer-close]");
    (closeButton ?? focusables()[0])?.focus();
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        setOpen(false);
        return;
      }
      if (event.key !== "Tab") return;
      const items = focusables();
      if (items.length === 0) return;
      const first = items[0]!;
      const last = items[items.length - 1]!;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      } else if (!panel?.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.style.overflow = previousOverflow;
      previouslyFocused?.focus();
    };
  }, [open]);

  async function changeQuantity(line: CartDrawerLine, delta: number) {
    const key = lineIdentity(line);
    const nextQuantity = line.quantity + delta;
    if (nextQuantity < 1 || nextQuantity > 99 || busy === key) return;
    setNotice("");
    const { cartId, cartToken } = readCartCookies();
    if (cartId && line.lineId) {
      setBusy(key);
      try {
        const data = await cartRequest(`/api/carts/${encodeURIComponent(cartId)}/lines/${encodeURIComponent(line.lineId)}`, { method: "PATCH", body: JSON.stringify({ qty: nextQuantity }) }, cartToken);
        const snapshot = toCartSnapshot(cartId, data, currency, cartToken);
        setLines(snapshot.lines.map((entry) => snapshotLine(entry, snapshot.currency)));
        setNotice(copy.purchase.updatedBag);
      } catch {
        setNotice(copy.purchase.updateFailed);
      } finally {
        setBusy(undefined);
      }
      return;
    }
    setLines((current) => current.map((entry) => lineIdentity(entry) === key ? { ...entry, quantity: nextQuantity } : entry));
    setNotice(copy.purchase.updatedBag);
  }

  async function removeLine(line: CartDrawerLine) {
    const key = lineIdentity(line);
    if (busy === key) return;
    setNotice("");
    const { cartId, cartToken } = readCartCookies();
    if (cartId && line.lineId) {
      setBusy(key);
      try {
        const data = await cartRequest(`/api/carts/${encodeURIComponent(cartId)}/lines/${encodeURIComponent(line.lineId)}`, { method: "DELETE" }, cartToken);
        const snapshot = toCartSnapshot(cartId, data, currency, cartToken);
        setLines(snapshot.lines.map((entry) => snapshotLine(entry, snapshot.currency)));
        setNotice(copy.purchase.removedFromBag);
      } catch {
        setNotice(copy.purchase.removeFailed);
      } finally {
        setBusy(undefined);
      }
      return;
    }
    setLines((current) => current.filter((entry) => lineIdentity(entry) !== key));
    setNotice(copy.purchase.removedFromBag);
  }

  if (!open) return null;

  const close = () => setOpen(false);
  const activeCurrency = lines[0]?.currency ?? currency;
  const total = lines.reduce((sum, line) => sum + line.unitPriceMinor * line.quantity, 0);

  return <>
    <div className="sf-cart-drawer-overlay" onClick={close} aria-hidden="true" />
    <aside className="sf-cart-drawer" role="dialog" aria-modal="true" aria-labelledby={titleId} ref={panelRef}>
      <header className="sf-cart-drawer-head">
        <h2 id={titleId}>{copy.purchase.cartItems}</h2>
        <button className="sf-cart-drawer-close" type="button" onClick={close} aria-label={copy.purchase.closeCart} data-drawer-close>×</button>
      </header>
      {lines.length === 0 ? <div className="sf-cart-drawer-empty sf-shell-cart-empty">
        <p>{copy.purchase.cartEmpty}</p>
        <Link className="button" href="/search" onClick={close}>{copy.purchase.continueShopping}</Link>
      </div> : <ul className="sf-cart-lines">
        {lines.map((line) => {
          const key = lineIdentity(line);
          const lineTotal = line.unitPriceMinor * line.quantity;
          const working = busy === key;
          return <li className="sf-cart-line" key={key}>
            <span className="sf-cart-line-name">{line.name}</span>
            <span className="sf-cart-line-qty">× {line.quantity}</span>
            <strong className="sf-cart-line-price">{formatMinor(lineTotal, line.currency ?? activeCurrency, locale)}</strong>
            <div className="sf-shell-cart-controls">
              <button className="sf-shell-cart-qty-button" type="button" aria-label={`${copy.purchase.decreaseLine}: ${line.name}`} onClick={() => void changeQuantity(line, -1)} disabled={working || line.quantity <= 1}>−</button>
              <button className="sf-shell-cart-qty-button" type="button" aria-label={`${copy.purchase.increaseLine}: ${line.name}`} onClick={() => void changeQuantity(line, 1)} disabled={working || line.quantity >= 99}>+</button>
              <button className="sf-shell-cart-remove" type="button" aria-label={`${copy.purchase.removeLine}: ${line.name}`} onClick={() => void removeLine(line)} disabled={working}>{working ? copy.purchase.updatingLine : copy.purchase.removeLine}</button>
            </div>
          </li>;
        })}
      </ul>}
      <p className="sf-cart-total"><span>{copy.purchase.subtotal}</span> <strong>{formatMinor(total, activeCurrency, locale)}</strong></p>
      {notice && <p className="sf-shell-cart-notice" role="status">{notice}</p>}
      <div className="sf-cart-drawer-actions"><Link className="button" href="/cart" onClick={close}>{copy.purchase.viewCart}</Link></div>
    </aside>
  </>;
}
