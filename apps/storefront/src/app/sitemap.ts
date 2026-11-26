import type { MetadataRoute } from "next";
import { CATEGORY_NAVIGATION } from "@/lib/navigation";
import { listPublishedCmsPages, requestCmsScope } from "@/lib/cms";
import { siteUrl } from "@/lib/locale";
import { requestScope } from "@/lib/request-locale";
import { catalog } from "@/services/catalog";

export const revalidate = 3600;

const MAX_PRODUCTS = 5000;
const PAGE_SIZE = 100;
const STATIC_PATHS = ["/", "/search"];

function safeSlug(value: unknown): string | undefined {
  return typeof value === "string" && /^[A-Za-z0-9][A-Za-z0-9._~-]{0,199}$/.test(value) ? value : undefined;
}

function entry(path: string, options: { lastModified?: Date; changeFrequency?: MetadataRoute.Sitemap[number]["changeFrequency"]; priority?: number } = {}): MetadataRoute.Sitemap[number] {
  return {
    url: siteUrl(path),
    ...(options.lastModified ? { lastModified: options.lastModified } : {}),
    ...(options.changeFrequency ? { changeFrequency: options.changeFrequency } : {}),
    ...(options.priority !== undefined ? { priority: options.priority } : {}),
  };
}

function validDate(value: unknown): Date | undefined {
  if (typeof value !== "string" || !value.trim()) return undefined;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? undefined : date;
}

async function productEntries(): Promise<MetadataRoute.Sitemap> {
  const source = catalog(await requestScope());
  const seen = new Set<string>();
  const entries: MetadataRoute.Sitemap = [];
  const maxPages = Math.ceil(MAX_PRODUCTS / PAGE_SIZE);
  for (let page = 1; page <= maxPages && entries.length < MAX_PRODUCTS; page += 1) {
    const batch = await source.listProducts({ page, perPage: PAGE_SIZE });
    if (!Array.isArray(batch) || batch.length === 0) break;
    for (const product of batch) {
      const slug = safeSlug(product.slug);
      if (!slug || seen.has(slug)) continue;
      seen.add(slug);
      entries.push(entry(`/product/${encodeURIComponent(slug)}`, { changeFrequency: "weekly", priority: 0.8 }));
      if (entries.length >= MAX_PRODUCTS) break;
    }
    if (batch.length < PAGE_SIZE) break;
  }
  return entries;
}

export async function buildSitemap(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = [
    entry("/", { changeFrequency: "daily", priority: 1 }),
    ...STATIC_PATHS.filter((path) => path !== "/").map((path) => entry(path, { changeFrequency: "monthly", priority: 0.3 })),
    ...CATEGORY_NAVIGATION.map((item) => entry(item.href, { changeFrequency: "weekly", priority: 0.7 })),
  ];

  try {
    entries.push(...await productEntries());
  } catch {
    // The sitemap must stay available even when the catalog API is down.
  }

  try {
    const pages = await listPublishedCmsPages({ scope: await requestCmsScope() });
    entries.push(...pages.map((page) => entry(`/pages/${encodeURIComponent(page.slug)}`, {
      ...(validDate(page.lastModified) ? { lastModified: validDate(page.lastModified) } : {}),
      changeFrequency: "monthly",
      priority: 0.5,
    })));
  } catch {
    // Published CMS pages are best-effort; static and catalog entries still ship.
  }

  return entries;
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  return buildSitemap();
}
