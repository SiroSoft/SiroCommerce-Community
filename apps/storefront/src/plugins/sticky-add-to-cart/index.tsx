"use client";

import { useEffect, useRef, useState } from "react";
import type { SlotProps } from "@/slots";
import { StickyBar } from "@/components/ui-primitives";
import { formatMinor } from "@/lib/money";

/**
 * Sticky Add to Cart bar for `pdp.below-atc`. Renders a thin placeholder in
 * the slot; once the shopper scrolls past the main Add to Cart button the
 * bar pins to the bottom of the viewport (desktop only — on mobile the
 * core `.mobile-add` bar already does this job, and two fixed bars would
 * overlap). The bar itself is the shared `StickyBar` primitive
 * (`@/components/ui-primitives`); its action button submits the plain form
 * POST to `/cart/add`, exactly like the core PDP button, so cart creation
 * + `POST /api/carts/{id}/lines` stay core-owned. No price/total is ever
 * computed here.
 */

interface StickySettings {
  active: boolean;
  button_label: string;
  show_price: boolean;
}

interface ProductPayload {
  name: string;
  price_minor?: number;
  price?: number;
  currency: string;
  status: string;
}

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

function readSettings(payload: unknown): StickySettings | null {
  if (!isRecord(payload) || payload.success !== true || !isRecord(payload.data)) return null;
  const data = payload.data;
  if (data.active !== true) return { active: false, button_label: "", show_price: false };
  return {
    active: true,
    button_label: typeof data.button_label === "string" && data.button_label.trim() !== "" ? data.button_label : "Add to Cart",
    show_price: data.show_price === true,
  };
}

function readProduct(payload: unknown): ProductPayload | null {
  if (!isRecord(payload) || payload.success !== true || !isRecord(payload.data)) return null;
  const data = payload.data;
  if (typeof data.name !== "string" || data.name.trim() === "") return null;
  if (typeof data.currency !== "string" || data.currency.trim() === "") return null;
  if (typeof data.status !== "string") return null;
  const priceMinor = typeof data.price_minor === "number" ? data.price_minor : typeof data.price === "number" ? data.price : undefined;
  return {
    name: data.name,
    price_minor: priceMinor,
    currency: data.currency,
    status: data.status,
  };
}

function isPurchasable(product: ProductPayload): boolean {
  return product.status === "active" || product.status === "backorder";
}

export function StickyAddToCart({ locale, data }: SlotProps) {
  const productId = readString(data, "productId");
  const productSlug = readString(data, "productSlug");
  const anchorRef = useRef<HTMLDivElement | null>(null);
  const formRef = useRef<HTMLFormElement | null>(null);
  const [settings, setSettings] = useState<StickySettings | null>(null);
  const [product, setProduct] = useState<ProductPayload | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [scrolledPast, setScrolledPast] = useState(false);
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    if (!productId || !productSlug) {
      setLoaded(true);
      return;
    }
    const base = apiBase();
    if (base === "") {
      setLoaded(true);
      return;
    }
    const controller = new AbortController();
    void (async () => {
      try {
        const [settingsResponse, productResponse] = await Promise.all([
          fetch(`${base}/api/ext/siro/sticky-add-to-cart/public`, {
            cache: "no-store",
            headers: scopeHeaders(locale),
            signal: controller.signal,
          }),
          fetch(`${base}/api/products/${encodeURIComponent(productSlug)}`, {
            cache: "no-store",
            headers: scopeHeaders(locale),
            signal: controller.signal,
          }),
        ]);
        if (!controller.signal.aborted) {
          if (settingsResponse.ok) setSettings(readSettings(await settingsResponse.json()));
          if (productResponse.ok) setProduct(readProduct(await productResponse.json()));
        }
      } catch {
        if (!controller.signal.aborted) {
          setSettings(null);
          setProduct(null);
        }
      } finally {
        if (!controller.signal.aborted) setLoaded(true);
      }
    })();
    return () => controller.abort();
  }, [locale, productId, productSlug]);

  useEffect(() => {
    const anchor = anchorRef.current;
    if (!anchor) return;
    if (typeof IntersectionObserver === "undefined") {
      setScrolledPast(true);
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        const entry = entries[0];
        if (entry) setScrolledPast(!entry.isIntersecting && entry.boundingClientRect.top < 0);
      },
      { threshold: 0 },
    );
    observer.observe(anchor);
    return () => observer.disconnect();
  }, [loaded]);

  useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return;
    const query = window.matchMedia("(max-width: 700px)");
    const update = () => setIsMobile(query.matches);
    update();
    query.addEventListener("change", update);
    return () => query.removeEventListener("change", update);
  }, []);

  const showBar =
    loaded && settings?.active === true && product !== null && isPurchasable(product) && scrolledPast && !isMobile;

  return (
    <>
      <div ref={anchorRef} data-testid="sticky-add-to-cart-anchor" aria-hidden="true" />
      {showBar && product !== null && settings !== null ? (
        <div data-testid="sticky-add-to-cart">
          <form ref={formRef} method="post" action="/cart/add" style={{ margin: 0 }}>
            <input type="hidden" name="product_id" value={productId} />
            <input type="hidden" name="quantity" value="1" />
            <StickyBar
              title={product.name}
              price={
                settings.show_price && product.price_minor !== undefined
                  ? formatMinor(product.price_minor, product.currency, locale ?? "en")
                  : undefined
              }
              actionLabel={settings.button_label}
              onAction={() => formRef.current?.requestSubmit()}
              visible
              ariaLabel="Sticky add to cart"
            />
          </form>
        </div>
      ) : null}
    </>
  );
}
