import { getCopy, type Locale } from "@/lib/i18n";
import type { StorefrontConfig } from "@/services/storefront-config";
import type { FooterConfig } from "@/theme/types";
import { asFooterLayout } from "@/theme/layouts";
import { resolveNavigation } from "@/lib/navigation";
import { resolveFooterLayout } from "@/components/chrome/footer-layouts";

export function Footer({ config, locale }: { config?: Pick<StorefrontConfig, "brand" | "navigation" | "policies"> & { footer?: FooterConfig; purchase?: StorefrontConfig["purchase"] }; locale?: Locale }) {
  const text = getCopy(locale);
  const shopLinks = resolveNavigation(config?.navigation.items, text.navigation);
  const policyLinks = [
    ...(config?.policies.shippingUrl ? [{ label: text.footer.shippingPolicy, href: config.policies.shippingUrl }] : []),
    ...(config?.policies.returnsUrl ? [{ label: text.footer.returnsPolicy, href: config.policies.returnsUrl }] : []),
    ...(config?.policies.supportUrl ? [{ label: text.footer.supportPolicy, href: config.policies.supportUrl }] : []),
    { label: text.consent.privacyPolicy, href: "/pages/privacy" },
    { label: text.consent.cookiePolicy, href: "/pages/cookie-policy" },
  ];
  const brandName = config?.brand.name ?? "SIRO Commerce";
  const wordmark = config?.brand.wordmark ?? "SIRO/COMMERCE";
  const logoUrl = config?.brand.logoUrl ?? "/brand/siro-commerce-mark.svg";
  const footer = config?.footer ?? { layout: "standard", show_help: true, show_policies: true, columns: [] };
  const footerLayout = asFooterLayout(footer.layout);
  const showQuoteLink = (config?.purchase?.quoteMode ?? "required") === "required";
  const Layout = resolveFooterLayout(footerLayout);
  return <footer className={footerLayout === "standard" ? "site-footer" : `site-footer footer-${footerLayout}`} data-footer-layout={footerLayout} aria-labelledby="footer-heading">
    <Layout text={text} brandName={brandName} wordmark={wordmark} logoUrl={logoUrl} shopLinks={shopLinks} policyLinks={policyLinks} showHelp={footer.show_help ?? true} showPolicies={(footer.show_policies ?? true) && policyLinks.length > 0} showQuoteLink={showQuoteLink} columns={footer.columns ?? []} />
  </footer>;
}
