"use client";
import Link from "next/link";
import type { RefObject } from "react";
import { fill, type getCopy, type Locale } from "@/lib/i18n";
import type { StorefrontLocale } from "@/lib/locale";
import { RegionSelector } from "@/components/RegionSelector";
import type { StorefrontCurrency } from "@/lib/scope-cookie";

type Copy = ReturnType<typeof getCopy>;
export type NavItem = { label: string; href: string };

export function AnnouncementBar({ text }: { text: Copy }) {
  return <div className="announcement"><span>{text.header.announcementShipping}</span><span aria-hidden="true">•</span><span>{text.header.announcementSourcing}</span></div>;
}

export function BrandLockup({ text, brandName, wordmark, logoUrl }: { text: Copy; brandName: string; wordmark: string; logoUrl: string }) {
  const normalize = (value: string): string => value.toLowerCase().replace(/[^a-z0-9]/g, "");
  const showBrandName = normalize(brandName) !== normalize(wordmark);
  return <Link href="/" className="brand-lockup" aria-label={fill(text.header.brandHome, { name: brandName })}>
    <img className="brand-logo" src={logoUrl} alt={fill(text.header.brandLogo, { name: brandName })} width="36" height="36" decoding="async" />
    <span className="brand-copy"><span className="wordmark">{wordmark}</span>{showBrandName ? <span className="brand-name">{brandName}</span> : null}</span>
  </Link>;
}

export function PrimaryNav({ text, navigation, pathname, menuOpen, firstMenuLinkRef, onNavigate, mega }: {
  text: Copy; navigation: NavItem[]; pathname: string; menuOpen: boolean;
  firstMenuLinkRef: RefObject<HTMLAnchorElement | null>; onNavigate: () => void; mega?: React.ReactNode;
}) {
  return <nav id="primary-navigation" className="primary-navigation" aria-label={text.header.primaryNav} data-open={menuOpen || undefined} aria-hidden={!menuOpen && undefined}>
    <span className="nav-kicker">{text.header.explore}</span>
    {mega}
    {navigation.map((item, index) => <Link ref={index === 0 ? firstMenuLinkRef : undefined} key={item.href} href={item.href} aria-current={pathname === item.href ? "page" : undefined} onClick={onNavigate}>{item.label}</Link>)}
  </nav>;
}

export function HeaderActions({ text, locale, currency, pathname, cartCount, bagLabel, menuOpen, menuButtonRef, onToggleMenu }: {
  text: Copy; locale: StorefrontLocale; currency: StorefrontCurrency; pathname: string;
  cartCount: number; bagLabel: string; menuOpen: boolean;
  menuButtonRef: RefObject<HTMLButtonElement | null>; onToggleMenu: () => void;
}) {
  return <div className="header-actions">
    <div className="utility-links">
      <Link href="/search" className="header-action" aria-label={text.header.searchProducts} aria-current={pathname === "/search" ? "page" : undefined}><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8" /><path d="m16 16 5 5" /></svg><span className="action-label">{text.navigation.search}</span></Link>
      <Link href="/cart" className="header-action" aria-label={bagLabel} aria-current={pathname === "/cart" ? "page" : undefined}><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 8.5h14l-1 12H6l-1-12Z" /><path d="M9 9V6a3 3 0 0 1 6 0v3" /></svg><span className="action-label">{text.navigation.bag}</span>{cartCount > 0 ? <span className="bag-count" key={cartCount} aria-live="polite">{cartCount}</span> : null}</Link>
      <Link href="/account" className="header-action account-action" aria-label={text.header.account} aria-current={pathname === "/account" ? "page" : undefined}><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" /></svg><span className="action-label">{text.header.account}</span></Link>
    </div>
    <RegionSelector locale={locale} currency={currency} />
    <button ref={menuButtonRef} className="menu-toggle" type="button" aria-expanded={menuOpen} aria-controls="primary-navigation" aria-label={menuOpen ? text.header.closeMenu : text.header.openMenu} onClick={onToggleMenu}><svg aria-hidden="true" viewBox="0 0 24 24">{menuOpen ? <path d="m6 6 12 12M18 6 6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}</svg><span className="menu-label">{menuOpen ? text.header.close : text.header.menu}</span></button>
  </div>;
}

export type ChromeParts = {
  text: Copy;
  locale: Locale | undefined;
  activeLocale: StorefrontLocale;
  currency: StorefrontCurrency;
  pathname: string;
  navigation: NavItem[];
  brandName: string;
  wordmark: string;
  logoUrl: string;
  cartCount: number;
  bagLabel: string;
  menuOpen: boolean;
  menuButtonRef: RefObject<HTMLButtonElement | null>;
  firstMenuLinkRef: RefObject<HTMLAnchorElement | null>;
  onNavigate: () => void;
  onToggleMenu: () => void;
  mega?: React.ReactNode;
};
