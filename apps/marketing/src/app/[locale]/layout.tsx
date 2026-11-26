import type { Metadata } from "next";
import type { ReactNode } from "react";
import { notFound } from "next/navigation";
import { defaultLocale, getContent, hreflangByLocale, isLocale, locales } from "@/content";
import { contactEmail, siteDescription, siteName, siteUrl } from "@/lib/site";
import "../globals.css";

export const dynamicParams = false;

export function generateStaticParams() {
  return locales.map((locale) => ({ locale }));
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: raw } = await params;
  const locale = isLocale(raw) ? raw : defaultLocale;
  const content = getContent(locale);
  const canonical = `/${locale}/`;
  const ogImage = locale === "en" ? "/og.png" : `/og-${locale}.png`;
  const languageAlternates = Object.fromEntries([
    ...locales.map((code) => [hreflangByLocale[code], `/${code}/`] as const),
    ["x-default", "/en/"] as const,
  ]);
  return {
    metadataBase: new URL(siteUrl),
    title: { absolute: content.meta.title },
    description: content.meta.description,
    keywords: content.meta.keywords,
    applicationName: siteName,
    authors: [{ name: "SiroSoft" }],
    creator: "SiroSoft",
    alternates: { canonical, languages: languageAlternates },
    robots: { index: true, follow: true },
    openGraph: {
      type: "website",
      locale: content.meta.ogLocale,
      alternateLocale: locales.filter((code) => code !== locale).map((code) => getContent(code).meta.ogLocale),
      url: `${siteUrl}/${locale}/`,
      siteName,
      title: content.meta.title,
      description: content.meta.description,
      images: [{ url: ogImage, width: 1200, height: 630, alt: content.meta.title }],
    },
    twitter: {
      card: "summary_large_image",
      title: content.meta.title,
      description: content.meta.description,
      images: [ogImage],
    },
    icons: { icon: [{ url: "/favicon.svg", type: "image/svg+xml" }], shortcut: "/favicon.svg" },
    other: { "content-language": content.meta.htmlLang },
  };
}

export default async function LocaleLayout({ children, params }: { children: ReactNode; params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const content = getContent(raw);
  const structuredData = {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        "@id": `${siteUrl}/#organization`,
        name: siteName,
        url: siteUrl,
        email: contactEmail,
        logo: `${siteUrl}/og.png`,
        description: siteDescription,
      },
      {
        "@type": "WebSite",
        "@id": `${siteUrl}/#website`,
        url: `${siteUrl}/${raw}/`,
        name: siteName,
        inLanguage: content.meta.htmlLang,
        publisher: { "@id": `${siteUrl}/#organization` },
      },
      {
        "@type": "SoftwareApplication",
        name: siteName,
        applicationCategory: "BusinessApplication",
        operatingSystem: "Web, Linux",
        url: siteUrl,
        description: content.meta.description,
        offers: {
          "@type": "Offer",
          price: "0",
          priceCurrency: "VND",
          description: raw === "vi" ? "Bản Community self-host miễn phí." : "Free self-hosted Community edition.",
        },
      },
    ],
  };

  return (
    <html lang={content.meta.htmlLang}>
      <head>
        <link rel="alternate" type="text/plain" href={raw === "en" ? "/llms.txt" : `/${raw}/llms.txt`} title="LLMs.txt" />
      </head>
      <body>
        <a className="skip-link" href="#main">{content.ui.skip}</a>
        {children}
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(structuredData) }} />
      </body>
    </html>
  );
}
