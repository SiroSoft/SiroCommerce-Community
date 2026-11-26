import type { Metadata } from "next";
import { cookies } from "next/headers";
import { Header } from "@/components/Header";
import { Footer } from "@/components/Footer";
import { PromoBanner } from "@/components/PromoBanner";
import "./globals.css";
import "./site-extras.css";
import { storefrontLocale } from "@/lib/locale";
import { getConfiguredStorefrontConfig } from "@/services/storefront-config";
import { requestCurrency, requestLocale } from "@/lib/request-locale";
import { ThemeProvider } from "@/theme/ThemeProvider";
import { ThemeMediaProvider } from "@/theme/ThemeMediaContext";
import { PresentationVariantProvider } from "@/presentation/variant-context";
import { siteUrl } from "@/lib/locale";
import { getCopy } from "@/lib/i18n";
import { LocaleProvider } from "@/lib/i18n-client";
import { PwaRuntime } from "@/components/PwaRuntime";
import { StorefrontShell } from "@/components/StorefrontShell";
import { ConsentProvider } from "@/components/consent/ConsentProvider";
import { Slot, SlotProvider, defaultSlotRegistry } from "@/slots";
import { SlotPluginBootstrap } from "@/plugins/SlotPluginBootstrap";

export const viewport = { themeColor: "#1e2420", width: "device-width", initialScale: 1 };
const PREVIEW_COOKIE = "siro_theme_preview";
async function previewTheme(): Promise<string | undefined> {
  try {
    return (await cookies()).get(PREVIEW_COOKIE)?.value;
  } catch {
    return undefined;
  }
}

export async function generateMetadata(): Promise<Metadata> {
  const preview = await previewTheme();
  const config = await getConfiguredStorefrontConfig({ previewTheme: preview });
  const copy = getCopy(await requestLocale());
  const description = copy.home.description;
  return { metadataBase: new URL(siteUrl()), title: { default: preview ? `Preview · ${copy.home.title}` : copy.home.title, template: `%s | ${config.brand.name}` }, description, applicationName: config.brand.name, keywords: [config.brand.name, "commerce", "shop"], robots: preview ? { index: false, follow: false } : { index: true, follow: true }, alternates: { canonical: siteUrl() }, icons: { icon: [{ url: "/icon.svg", type: "image/svg+xml" }, { url: "/brand/siro-commerce-mark.svg", type: "image/svg+xml" }], shortcut: "/icon.svg" }, manifest: "/manifest.webmanifest", openGraph: { title: preview ? `Preview · ${copy.home.title}` : copy.home.title, description, type: "website", url: siteUrl(), siteName: config.brand.name, locale: storefrontLocale() }, twitter: { card: "summary" } };
}
export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const preview = await previewTheme();
  const config = await getConfiguredStorefrontConfig({ previewTheme: preview });
  const locale = await requestLocale();
  const currency = await requestCurrency();
  const copy = getCopy(locale);
  const scope = config.scope;
  return <html lang={locale} data-scroll-behavior="smooth" data-store-id={scope?.store_id} data-channel-id={scope?.channel_id} data-locale={scope?.locale} data-currency={scope?.currency}><body><ThemeMediaProvider images={config.productImages}><ThemeProvider theme={config.theme}><PresentationVariantProvider card={config.presentation.productCardVariant} detail={config.presentation.productDetailVariant} list={config.presentation.productListVariant} purchase={config.presentation.purchaseVariant}><LocaleProvider locale={locale}><ConsentProvider><StorefrontShell><SlotProvider registry={defaultSlotRegistry}><SlotPluginBootstrap />{preview ? <div className="theme-preview-bar" role="status">Preview theme: <strong>{config.brand.name}</strong> · <a href="/preview">Theme gallery</a> · <a href="/preview/exit">Exit preview</a></div> : null}<PwaRuntime /><a className="skip-link" href="#main-content">{copy.header.skipToContent}</a><Header config={config} locale={locale} currency={currency} /><Slot name="header" /><PromoBanner /><main id="main-content" className="main">{children}</main><Slot name="footer" /><Footer config={config} locale={locale} /></SlotProvider></StorefrontShell></ConsentProvider></LocaleProvider></PresentationVariantProvider></ThemeProvider></ThemeMediaProvider></body></html>;
}
