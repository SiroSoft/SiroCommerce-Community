"use client";
import type { HeaderLayout } from "@/theme/types";
import { HEADER_LAYOUTS } from "@/theme/layouts";
import { AnnouncementBar, BrandLockup, HeaderActions, PrimaryNav, type ChromeParts } from "./header-parts";

function StandardHeader(parts: ChromeParts) {
  const { text, activeLocale, currency, pathname, navigation, brandName, wordmark, logoUrl, cartCount, bagLabel, menuOpen, menuButtonRef, firstMenuLinkRef, onNavigate, onToggleMenu } = parts;
  return <div className="header-inner">
    <BrandLockup text={text} brandName={brandName} wordmark={wordmark} logoUrl={logoUrl} />
    <PrimaryNav text={text} navigation={navigation} pathname={pathname} menuOpen={menuOpen} firstMenuLinkRef={firstMenuLinkRef} onNavigate={onNavigate} mega={parts.mega} />
    <HeaderActions text={text} locale={activeLocale} currency={currency} pathname={pathname} cartCount={cartCount} bagLabel={bagLabel} menuOpen={menuOpen} menuButtonRef={menuButtonRef} onToggleMenu={onToggleMenu} />
  </div>;
}

function MinimalHeader(parts: ChromeParts) {
  const { text, activeLocale, currency, pathname, brandName, wordmark, logoUrl, cartCount, bagLabel, menuOpen, menuButtonRef, onToggleMenu } = parts;
  return <div className="header-inner">
    <BrandLockup text={text} brandName={brandName} wordmark={wordmark} logoUrl={logoUrl} />
    <HeaderActions text={text} locale={activeLocale} currency={currency} pathname={pathname} cartCount={cartCount} bagLabel={bagLabel} menuOpen={menuOpen} menuButtonRef={menuButtonRef} onToggleMenu={onToggleMenu} />
  </div>;
}

function CenteredHeader(parts: ChromeParts) {
  const { text, activeLocale, currency, pathname, navigation, brandName, wordmark, logoUrl, cartCount, bagLabel, menuOpen, menuButtonRef, firstMenuLinkRef, onNavigate, onToggleMenu } = parts;
  return <div className="header-inner">
    <BrandLockup text={text} brandName={brandName} wordmark={wordmark} logoUrl={logoUrl} />
    <PrimaryNav text={text} navigation={navigation} pathname={pathname} menuOpen={menuOpen} firstMenuLinkRef={firstMenuLinkRef} onNavigate={onNavigate} mega={parts.mega} />
    <HeaderActions text={text} locale={activeLocale} currency={currency} pathname={pathname} cartCount={cartCount} bagLabel={bagLabel} menuOpen={menuOpen} menuButtonRef={menuButtonRef} onToggleMenu={onToggleMenu} />
  </div>;
}

function SplitHeader(parts: ChromeParts) {
  const { text, activeLocale, currency, pathname, navigation, brandName, wordmark, logoUrl, cartCount, bagLabel, menuOpen, menuButtonRef, firstMenuLinkRef, onNavigate, onToggleMenu } = parts;
  return <div className="header-inner">
    <PrimaryNav text={text} navigation={navigation} pathname={pathname} menuOpen={menuOpen} firstMenuLinkRef={firstMenuLinkRef} onNavigate={onNavigate} mega={parts.mega} />
    <BrandLockup text={text} brandName={brandName} wordmark={wordmark} logoUrl={logoUrl} />
    <HeaderActions text={text} locale={activeLocale} currency={currency} pathname={pathname} cartCount={cartCount} bagLabel={bagLabel} menuOpen={menuOpen} menuButtonRef={menuButtonRef} onToggleMenu={onToggleMenu} />
  </div>;
}

/**
 * THE one switch for header layouts (FE_THEME_LAYOUT_SPEC RULE-4).
 * Unknown keys render standard + warn in development (RULE-3).
 */
const headerLayoutRegistry: Record<HeaderLayout, (parts: ChromeParts) => React.JSX.Element> = {
  standard: StandardHeader,
  minimal: MinimalHeader,
  centered: CenteredHeader,
  split: SplitHeader,
};

export function resolveHeaderLayout(layout: HeaderLayout): (parts: ChromeParts) => React.JSX.Element {
  const resolved = (HEADER_LAYOUTS as readonly string[]).includes(layout) ? layout : "standard";
  if (resolved !== layout && process.env.NODE_ENV === "development") console.warn(`Unknown header layout "${layout}", falling back to standard.`);
  return headerLayoutRegistry[resolved];
}

export function HeaderAnnouncement({ show, text }: { show: boolean; text: Parameters<typeof AnnouncementBar>[0]["text"] }) {
  return show ? <AnnouncementBar text={text} /> : null;
}
