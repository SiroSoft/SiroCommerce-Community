import Link from "next/link";
import { fill, type getCopy } from "@/lib/i18n";
import type { FooterColumn } from "@/theme/types";
import type { FooterLayout } from "@/theme/types";
import { FOOTER_LAYOUTS } from "@/theme/layouts";
import { CookieSettingsButton } from "@/components/consent/CookieSettingsButton";

type Copy = ReturnType<typeof getCopy>;
const supportEmail = (process.env.NEXT_PUBLIC_STOREFRONT_SUPPORT_EMAIL ?? "").trim();
export type FooterNavItem = { label: string; href: string };

export type FooterParts = {
  text: Copy;
  brandName: string;
  wordmark: string;
  logoUrl: string;
  shopLinks: FooterNavItem[];
  policyLinks: FooterNavItem[];
  showHelp: boolean;
  showPolicies: boolean;
  showQuoteLink: boolean;
  columns: FooterColumn[];
};

function ShopNav({ text, shopLinks }: FooterParts) {
  return <nav aria-labelledby="footer-shop-heading">
    <h3 id="footer-shop-heading">{text.footer.shop}</h3>
    {shopLinks.map((item) => <Link key={item.href} href={item.href}>{item.label}</Link>)}
  </nav>;
}

function HelpNav({ text, showQuoteLink }: FooterParts) {
  return <nav aria-labelledby="footer-help-heading">
    <h3 id="footer-help-heading">{text.footer.help}</h3>
    <Link href="/search">{text.navigation.search}</Link>
    <Link href="/account">{text.footer.orders}</Link>
    {showQuoteLink ? <Link href="/quote">{text.footer.shippingQuote}</Link> : null}
    {supportEmail ? <a href={`mailto:${supportEmail}?subject=SiroCommerce%20support`}>{text.footer.contact}</a> : null}
  </nav>;
}

function CustomColumns({ columns }: FooterParts) {
  return <>{columns.map((column) => <nav key={column.title} aria-label={column.title}>
    <h3>{column.title}</h3>
    {column.links.map((item) => <Link key={item.href} href={item.href}>{item.label}</Link>)}
  </nav>)}</>;
}

function PoliciesNav({ text, policyLinks }: FooterParts) {
  if (policyLinks.length === 0) return null;
  return <nav aria-labelledby="footer-policies-heading">
    <h3 id="footer-policies-heading">{text.footer.policies}</h3>
    {policyLinks.map((item) => <a key={item.href} href={item.href}>{item.label}</a>)}
  </nav>;
}

function FooterBottom({ text, brandName }: FooterParts) {
  return <div className="footer-bottom">
    <p className="footer-support"><strong>{text.footer.needHelp}</strong>{supportEmail ? <> <a href={`mailto:${supportEmail}?subject=SiroCommerce%20support`}>{text.footer.emailSupport}</a></> : null}</p>
    <p className="footer-meta">{text.footer.confirmationNote}</p>
    <p className="footer-consent"><CookieSettingsButton className="text-button consent-settings-link" label={text.consent.cookieSettings} /></p>
    <small>Ac 2026 {brandName}. {text.footer.copyright}</small>
  </div>;
}

function FooterIntro({ text, brandName, wordmark, logoUrl }: FooterParts) {
  return <div className="footer-intro">
    <Link href="/" className="brand-lockup" aria-label={fill(text.header.brandHome, { name: brandName })}><img className="brand-logo" src={logoUrl} alt={fill(text.header.brandLogo, { name: brandName })} width="36" height="36" loading="lazy" decoding="async" /><span className="wordmark">{wordmark}</span></Link>
    <h2 id="footer-heading">{text.footer.heading}</h2>
    <p>{text.footer.story}</p>
    <p className="muted">{text.footer.checkoutNote}</p>
  </div>;
}

function StandardFooter(parts: FooterParts) {
  const { showHelp, showPolicies } = parts;
  return <>
    <FooterIntro {...parts} />
    <div className="footer-links">
      <ShopNav {...parts} />
      {showHelp && <HelpNav {...parts} />}
      <CustomColumns {...parts} />
      {showPolicies && <PoliciesNav {...parts} />}
    </div>
    <FooterBottom {...parts} />
  </>;
}

function MinimalFooter(parts: FooterParts) {
  const { text, brandName } = parts;
  return <div className="footer-bottom">
    <p className="footer-support"><Link href="/" aria-label={fill(text.header.brandHome, { name: brandName })}>{brandName}</Link>{supportEmail ? <> · <a href={`mailto:${supportEmail}?subject=SiroCommerce%20support`}>{text.footer.emailSupport}</a></> : null}</p>
    <small>© 2026 {brandName}. {text.footer.copyright}</small>
  </div>;
}

function MegaFooter(parts: FooterParts) {
  const { showHelp, showPolicies } = parts;
  return <>
    <FooterIntro {...parts} />
    <div className="footer-links footer-mega-grid">
      <ShopNav {...parts} />
      <CustomColumns {...parts} />
      {showHelp && <HelpNav {...parts} />}
      {showPolicies && <PoliciesNav {...parts} />}
    </div>
    <FooterBottom {...parts} />
  </>;
}

/**
 * THE one switch for footer layouts (FE_THEME_LAYOUT_SPEC RULE-4).
 * Unknown keys render standard + warn in development (RULE-3).
 */
const footerLayoutRegistry: Record<FooterLayout, (parts: FooterParts) => React.JSX.Element | null> = {
  standard: StandardFooter,
  minimal: MinimalFooter,
  mega: MegaFooter,
};

export function resolveFooterLayout(layout: FooterLayout): (parts: FooterParts) => React.JSX.Element | null {
  const resolved = (FOOTER_LAYOUTS as readonly string[]).includes(layout) ? layout : "standard";
  if (resolved !== layout && process.env.NODE_ENV === "development") console.warn(`Unknown footer layout "${layout}", falling back to standard.`);
  return footerLayoutRegistry[resolved];
}
