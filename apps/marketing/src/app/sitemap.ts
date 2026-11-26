import type { MetadataRoute } from "next";
import { hreflangByLocale, locales } from "@/content";
import { siteUrl } from "@/lib/site";

export const dynamic = "force-static";

const lastModified = new Date("2026-11-21");

export default function sitemap(): MetadataRoute.Sitemap {
  const languages = Object.fromEntries([
    ...locales.map((locale) => [hreflangByLocale[locale], `${siteUrl}/${locale}/`] as const),
    ["x-default", `${siteUrl}/en/`] as const,
  ]);
  return locales.map((locale) => ({
    url: `${siteUrl}/${locale}/`,
    lastModified,
    changeFrequency: "monthly",
    priority: locale === "en" ? 1 : 0.9,
    alternates: { languages },
  }));
}
