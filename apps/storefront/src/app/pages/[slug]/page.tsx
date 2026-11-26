import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { CmsBlocks } from "@/components/cms/CmsBlocks";
import { CmsRequestError, getCmsPage, isValidCmsSlug, resolveCmsMetadata, safeHttpUrl } from "@/lib/cms";
import { siteUrl } from "@/lib/locale";
import { requestLocale } from "@/lib/request-locale";

export const revalidate = 60;
export const dynamic = "force-dynamic";

const loadPage = cache(async (slug: string) => getCmsPage(slug));

function canonicalFor(canonical: string): string {
  const safe = safeHttpUrl(canonical) ?? canonical;
  return safe.startsWith("/") ? siteUrl(safe) : safe;
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  if (!isValidCmsSlug(slug)) return { title: "Page not found", robots: { index: false, follow: false } };
  try {
    const page = await loadPage(slug);
    if (!page) return { title: "Page not found", robots: { index: false, follow: false } };
    const meta = resolveCmsMetadata(page, `/pages/${page.slug}`);
    const canonical = canonicalFor(meta.canonical);
    const locale = await requestLocale();
    return {
      title: meta.title,
      ...(meta.description ? { description: meta.description } : {}),
      alternates: { canonical },
      robots: meta.robots,
      openGraph: {
        title: meta.title,
        ...(meta.openGraphDescription ? { description: meta.openGraphDescription } : {}),
        url: canonical,
        locale,
        type: "website",
      },
    };
  } catch (error) {
    if (error instanceof CmsRequestError) return { title: "Page unavailable", robots: { index: false, follow: false } };
    throw error;
  }
}

export default async function CmsPageRoute({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  if (!isValidCmsSlug(slug)) notFound();
  let page;
  try {
    page = await loadPage(slug);
  } catch (error) {
    if (error instanceof CmsRequestError) throw error;
    throw error;
  }
  if (!page) notFound();
  const hasHero = page.blocks.some((block) => block.type === "hero" && (!block.status || block.status === "published"));
  return <article className="sf-cms-page" data-cms-slug={page.slug}>
    {!hasHero && <header className="sf-cms-header"><h1>{page.title}</h1></header>}
    <CmsBlocks blocks={page.blocks} body={page.body} title={page.title} />
  </article>;
}
