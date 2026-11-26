"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { getCopy } from "@/lib/i18n";
import type { Locale } from "@/lib/i18n";
import type { StorefrontLocale } from "@/lib/locale";
import type { StorefrontConfig } from "@/services/storefront-config";
import type { HeaderConfig } from "@/theme/types";
import { asHeaderLayout } from "@/theme/layouts";
import type { StorefrontCurrency } from "@/lib/scope-cookie";
import { readCartRecovery, recoveryKey } from "@/lib/pwa";
import { resolveNavigation } from "@/lib/navigation";
import { categoryHref } from "@/lib/category-href";
import { HeaderAnnouncement, resolveHeaderLayout } from "@/components/chrome/header-layouts";

import { readBagCount } from "@/lib/cart-session";

function readCartCount() {
  const fromCookie = readBagCount();
  if (fromCookie > 0) return fromCookie;
  try {
    const value = readCartRecovery(window.sessionStorage.getItem(recoveryKey));
    const quantity = value?.cartQuantity ?? value?.quantity;
    return typeof quantity === "number" && Number.isInteger(quantity) && quantity > 0 ? quantity : 0;
  } catch { return 0; }
}

type HeaderCategory = { label: string; href: string };
type Copy = ReturnType<typeof getCopy>;

function navigationCategories(navigation: { label: string; href: string }[]): HeaderCategory[] {
  const seen = new Set<string>();
  return navigation.flatMap((item) => {
    if (!item.href.startsWith("/category/") || seen.has(item.href)) return [];
    seen.add(item.href);
    return [{ label: item.label, href: item.href }];
  }).slice(0, 6);
}

function parseCatalogCategories(value: unknown): HeaderCategory[] {
  if (!value || typeof value !== "object") return [];
  const data = (value as { data?: unknown }).data;
  if (!Array.isArray(data)) return [];
  const bySlug = new Map<string, HeaderCategory>();
  for (const entry of data) {
    if (!entry || typeof entry !== "object") continue;
    const record = entry as Record<string, unknown>;
    const slug = typeof record.category === "string" ? record.category.trim() : "";
    if (!slug || bySlug.has(slug)) continue;
    const label = typeof record.category_name === "string" && record.category_name.trim()
      ? record.category_name
      : typeof record.categoryName === "string" && record.categoryName.trim() ? record.categoryName : slug;
    bySlug.set(slug, { label, href: categoryHref(slug) });
    if (bySlug.size >= 6) break;
  }
  return [...bySlug.values()];
}

function hoverCapable(): boolean {
  return typeof window !== "undefined" && typeof window.matchMedia === "function" && window.matchMedia("(hover: hover)").matches;
}

/** Desktop mega-menu and its mobile accordion share the same link list. */
function CategoryMegaMenu({ categories, copy, mobile }: { categories: HeaderCategory[]; copy: Copy; mobile?: boolean }) {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const focusFirstLink = useRef(false);
  const panelId = mobile ? "sf-mobile-categories" : "sf-desktop-categories";

  useEffect(() => {
    if (!open || !focusFirstLink.current) return;
    focusFirstLink.current = false;
    panelRef.current?.querySelector<HTMLAnchorElement>("a")?.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: PointerEvent) => {
      const target = event.target as Node | null;
      if (target && (triggerRef.current?.contains(target) || panelRef.current?.contains(target))) return;
      setOpen(false);
    };
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open]);

  if (categories.length === 0) return null;
  const close = () => { setOpen(false); triggerRef.current?.focus(); };

  return <div
    className={`sf-mega${mobile ? " sf-mega--mobile" : ""}`}
    data-open={open || undefined}
    onMouseEnter={() => { if (!mobile && hoverCapable()) setOpen(true); }}
    onMouseLeave={() => { if (!mobile && hoverCapable() && !panelRef.current?.contains(document.activeElement)) setOpen(false); }}
    onFocus={() => { if (hoverCapable()) setOpen(true); }}
    onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOpen(false); }}
    onKeyDown={(event) => { if (event.key === "Escape" && open) { event.stopPropagation(); close(); } }}
  >
    <button
      ref={triggerRef}
      type="button"
      className="sf-mega-trigger"
      aria-haspopup="true"
      aria-expanded={open}
      aria-controls={panelId}
      onClick={(event) => {
        // Keyboard activation on a focus-opened menu keeps it open; pointer toggles.
        if (event.detail === 0 && open) return;
        setOpen((value) => !value);
      }}
      onKeyDown={(event) => {
        if (event.key === "ArrowDown") {
          event.preventDefault();
          if (open) panelRef.current?.querySelector<HTMLAnchorElement>("a")?.focus();
          else { focusFirstLink.current = true; setOpen(true); }
        } else if (event.key === "Escape") {
          event.stopPropagation();
          close();
        }
      }}
    >
      <span>{copy.category.prefix}</span>
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>
    </button>
    <div id={panelId} ref={panelRef} className="sf-mega-panel" hidden={!open}>
      <p className="sf-mega-heading">{copy.category.prefix}</p>
      <ul className="sf-mega-list">
        {categories.map((category) => <li key={category.href}>
          <Link href={category.href} aria-label={`${category.label} ${copy.discovery.collection}`} onClick={() => setOpen(false)}>
            <span>{category.label}</span>
            <span aria-hidden="true">→</span>
          </Link>
        </li>)}
      </ul>
    </div>
  </div>;
}

export function Header({ config, locale, currency }: { config?: Pick<StorefrontConfig, "brand" | "navigation"> & { header?: HeaderConfig }; locale?: Locale; currency?: StorefrontCurrency }) {
  const text = getCopy(locale);
  const pathname = usePathname() ?? "/";
  const activeLocale: StorefrontLocale = locale ?? "en";
  const [cartCount, setCartCount] = useState(0);
  const [menuOpen, setMenuOpen] = useState(false);
  const [remoteCategories, setRemoteCategories] = useState<HeaderCategory[]>([]);
  const [navElement, setNavElement] = useState<HTMLElement | null>(null);
  const menuButtonRef = useRef<HTMLButtonElement>(null);
  const firstMenuLinkRef = useRef<HTMLAnchorElement>(null);
  const navigation = resolveNavigation(config?.navigation.items, text.navigation);
  const categories = remoteCategories.length > 0 ? remoteCategories : navigationCategories(navigation);

  useEffect(() => {
    const refresh = () => setCartCount(readCartCount());
    refresh();
    const onBagUpdated = (event: Event) => {
      const count = (event as CustomEvent).detail;
      setCartCount(typeof count === "number" && Number.isInteger(count) && count > 0 ? Math.min(count, 99) : readCartCount());
    };
    window.addEventListener("siro:bag-updated", onBagUpdated as EventListener);
    window.addEventListener("storage", refresh);
    return () => { window.removeEventListener("siro:bag-updated", onBagUpdated as EventListener); window.removeEventListener("storage", refresh); };
  }, []);
  useEffect(() => {
    setNavElement(document.getElementById("primary-navigation"));
  }, [pathname]);
  useEffect(() => {
    if (process.env.STOREFRONT_CATALOG_SOURCE === "fixture" || typeof fetch !== "function") return;
    let active = true;
    const baseUrl = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? "";
    const url = `${baseUrl.replace(/\/$/, "")}/api/products?page=1&per_page=100`;
    fetch(url, { cache: "no-store" })
      .then((response) => (response.ok ? response.json().catch(() => null) : null))
      .then((body: unknown) => { if (active) { const parsed = parseCatalogCategories(body); if (parsed.length > 0) setRemoteCategories(parsed); } })
      .catch(() => undefined);
    return () => { active = false; };
  }, []);
  useEffect(() => {
    if (!menuOpen) return;
    firstMenuLinkRef.current?.focus();
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setMenuOpen(false);
        menuButtonRef.current?.focus();
      }
    };
    document.addEventListener("keydown", closeOnEscape);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", closeOnEscape);
      document.body.style.overflow = previousOverflow;
    };
  }, [menuOpen]);
  useEffect(() => {
    if (!menuOpen) return;
    const trapFocus = (event: KeyboardEvent) => {
      if (event.key !== "Tab") return;
      const nav = document.getElementById("primary-navigation");
      const toggle = menuButtonRef.current;
      const nodes = [...(toggle ? [toggle] : []), ...(nav ? [...nav.querySelectorAll<HTMLElement>("a[href], button:not([disabled])")] : [])];
      if (nodes.length === 0) return;
      const first = nodes[0]!;
      const last = nodes[nodes.length - 1]!;
      const active = document.activeElement;
      if (!nodes.includes(active as HTMLElement)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
      }
    };
    document.addEventListener("keydown", trapFocus);
    return () => document.removeEventListener("keydown", trapFocus);
  }, [menuOpen]);
  const brandName = config?.brand.name ?? "SIRO Commerce";
  const wordmark = config?.brand.wordmark ?? "SIRO/COMMERCE";
  const logoUrl = config?.brand.logoUrl ?? "/brand/siro-commerce-mark.svg";
  const header = config?.header ?? { announcement: true, layout: "standard", sticky: false };
  const headerLayout = asHeaderLayout(header.layout);
  const showAnnouncement = header.announcement ?? true;
  const bagLabel = cartCount > 0 ? `${text.header.openBag}, ${cartCount} ${cartCount === 1 ? text.header.item : text.header.items}` : text.header.openBag;
  const closeMenu = () => { setMenuOpen(false); menuButtonRef.current?.focus(); };
  const Layout = resolveHeaderLayout(headerLayout);
  return <header className={headerLayout === "standard" ? "site-header" : `site-header header-${headerLayout}`} data-header-layout={headerLayout} style={header.sticky ? { position: "sticky", top: 0, zIndex: 40 } : undefined}>
    <HeaderAnnouncement show={showAnnouncement} text={text} />
    <Layout text={text} locale={locale} activeLocale={activeLocale} currency={currency ?? "USD"} pathname={pathname} navigation={navigation} brandName={brandName} wordmark={wordmark} logoUrl={logoUrl} cartCount={cartCount} bagLabel={bagLabel} menuOpen={menuOpen} menuButtonRef={menuButtonRef} firstMenuLinkRef={firstMenuLinkRef} onNavigate={closeMenu} onToggleMenu={() => setMenuOpen((open) => !open)} />
    {menuOpen && navElement ? createPortal(<CategoryMegaMenu categories={categories} copy={text} mobile />, navElement) : null}
  </header>;
}
