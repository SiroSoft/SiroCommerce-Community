import type { CommerceScope } from "@sirocommerce/scope";
import { scopeHeaders } from "@sirocommerce/scope";
import { requestLocale, requestScope } from "@/lib/request-locale";

export const CMS_REVALIDATE_SECONDS = 60;
export const CMS_PAGE_LIMIT = 500;
export const CMS_DEFAULT_SLUGS = ["about", "contact", "faq", "privacy", "terms", "shipping", "returns"] as const;

export interface CmsMediaReference {
  id?: string;
  url: string;
  alt?: string;
  title?: string;
  mime?: string;
}

export interface CmsBlock {
  id?: string;
  type: string;
  content: string;
  sort?: number;
  status?: string;
  media?: CmsMediaReference[];
}

export interface CmsSeo {
  title?: string | null;
  description?: string | null;
  canonical?: string | null;
  robots?: string | null;
}

export interface CmsPage {
  id?: string;
  slug: string;
  title: string;
  body?: string | null;
  locale?: string;
  status?: string;
  published_at?: string | null;
  scheduled_at?: string | null;
  updated_at?: string | null;
  blocks: CmsBlock[];
  media?: CmsMediaReference[];
  seo: CmsSeo;
}

export interface CmsFetchOptions {
  fetch?: typeof fetch;
  baseUrl?: string;
  scope?: CommerceScope;
  revalidate?: number;
  signal?: AbortSignal;
  timeoutMs?: number;
}

export class CmsRequestError extends Error {
  constructor(readonly status: number, message: string) {
    super(message);
    this.name = "CmsRequestError";
  }
}

export function cmsApiBaseUrl(): string | undefined {
  const value = process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? process.env.STOREFRONT_API_BASE_URL;
  const trimmed = typeof value === "string" ? value.trim() : "";
  return trimmed ? trimmed.replace(/\/$/, "") : undefined;
}

export async function requestCmsScope(): Promise<CommerceScope> {
  const scope = await requestScope();
  const locale = await requestLocale();
  return { ...scope, locale };
}

export function isValidCmsSlug(value: unknown): value is string {
  return typeof value === "string" && /^[a-z0-9][a-z0-9-]{0,159}$/.test(value);
}

/** Only same-origin absolute paths are accepted. */
export function safeInternalPath(value: unknown, max = 2048): string | undefined {
  if (typeof value !== "string") return undefined;
  const trimmed = value.trim();
  if (!trimmed || trimmed.length > max) return undefined;
  if (!trimmed.startsWith("/") || trimmed.startsWith("//")) return undefined;
  if (trimmed.includes("\\")) return undefined;
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f]/.test(trimmed)) return undefined;
  return trimmed;
}

export function safeHttpUrl(value: unknown, max = 2048): string | undefined {
  if (typeof value !== "string") return undefined;
  const trimmed = value.trim();
  if (!trimmed || trimmed.length > max) return undefined;
  if (/^https:\/\//i.test(trimmed)) return trimmed;
  return safeInternalPath(trimmed, max);
}

export function safeImageUrl(value: unknown): string | undefined {
  return safeHttpUrl(value);
}

function asRecord(value: unknown): Record<string, unknown> | undefined {
  return value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : undefined;
}

function parseMedia(value: unknown): CmsMediaReference[] {
  if (!Array.isArray(value)) return [];
  return value.slice(0, 20).flatMap((entry) => {
    const record = asRecord(entry);
    const url = record ? safeImageUrl(record.url) : undefined;
    if (!record || !url) return [];
    return [{
      url,
      ...(typeof record.id === "string" ? { id: record.id } : {}),
      ...(typeof record.alt === "string" ? { alt: record.alt.slice(0, 320) } : {}),
      ...(typeof record.title === "string" ? { title: record.title.slice(0, 320) } : {}),
      ...(typeof record.mime === "string" ? { mime: record.mime.slice(0, 80) } : {}),
    }];
  });
}

function parseBlock(value: unknown): CmsBlock | undefined {
  const record = asRecord(value);
  if (!record || typeof record.type !== "string" || !record.type.trim()) return undefined;
  return {
    type: record.type.trim().toLowerCase().slice(0, 64),
    content: typeof record.content === "string" ? record.content : "",
    ...(typeof record.id === "string" ? { id: record.id } : {}),
    ...(typeof record.sort === "number" && Number.isFinite(record.sort) ? { sort: record.sort } : {}),
    ...(typeof record.status === "string" ? { status: record.status } : {}),
    media: parseMedia(record.media),
  };
}

export function parseCmsPage(value: unknown): CmsPage | null {
  const record = asRecord(value);
  if (!record) return null;
  const slug = typeof record.slug === "string" ? record.slug : "";
  const title = typeof record.title === "string" ? record.title.trim() : "";
  if (!isValidCmsSlug(slug) || !title) return null;
  const blocks = Array.isArray(record.blocks) ? record.blocks.slice(0, 200).flatMap((entry) => {
    const block = parseBlock(entry);
    return block ? [block] : [];
  }) : [];
  const seo = asRecord(record.seo) ?? {};
  return {
    slug,
    title: title.slice(0, 255),
    ...(record.id === undefined ? {} : { id: String(record.id) }),
    body: typeof record.body === "string" ? record.body : null,
    ...(typeof record.locale === "string" ? { locale: record.locale } : {}),
    ...(typeof record.status === "string" ? { status: record.status } : {}),
    ...(typeof record.published_at === "string" ? { published_at: record.published_at } : {}),
    ...(typeof record.scheduled_at === "string" ? { scheduled_at: record.scheduled_at } : {}),
    ...(typeof record.updated_at === "string" ? { updated_at: record.updated_at } : {}),
    blocks,
    media: parseMedia(record.media),
    seo: {
      title: typeof seo.title === "string" ? seo.title.slice(0, 255) : null,
      description: typeof seo.description === "string" ? seo.description.slice(0, 512) : null,
      canonical: typeof seo.canonical === "string" ? seo.canonical.slice(0, 512) : null,
      robots: typeof seo.robots === "string" ? seo.robots.slice(0, 64) : null,
    },
  };
}

function headersFor(scope?: CommerceScope): Record<string, string> {
  return { Accept: "application/json", ...(scope ? scopeHeaders(scope) : {}) };
}

async function jsonRequest(url: string, options: CmsFetchOptions, headers: Record<string, string>): Promise<unknown> {
  const request = options.fetch ?? fetch;
  const controller = options.timeoutMs === undefined && !options.signal ? undefined : new AbortController();
  const timer = controller && options.timeoutMs !== undefined
    ? setTimeout(() => controller.abort(), options.timeoutMs)
    : undefined;
  try {
    const response = await request(url, {
      method: "GET",
      headers,
      ...(options.signal ? { signal: options.signal } : controller ? { signal: controller.signal } : {}),
      ...(options.revalidate === undefined ? {} : { next: { revalidate: options.revalidate } }),
    } as RequestInit);
    if (response.status === 404) return null;
    if (!response.ok) throw new CmsRequestError(response.status, `CMS request failed with status ${response.status}`);
    const body = await response.json() as unknown;
    const envelope = asRecord(body);
    return envelope && "data" in envelope ? envelope.data : body;
  } finally {
    if (timer !== undefined) clearTimeout(timer);
  }
}

export async function fetchCmsPage(slug: string, options: CmsFetchOptions = {}): Promise<CmsPage | null> {
  if (!isValidCmsSlug(slug)) return null;
  const baseUrl = options.baseUrl ?? cmsApiBaseUrl();
  if (!baseUrl) return null;
  const url = `${baseUrl}/api/pages/${encodeURIComponent(slug)}`;
  try {
    const data = await jsonRequest(url, { revalidate: CMS_REVALIDATE_SECONDS, ...options }, headersFor(options.scope));
    return data === null ? null : parseCmsPage(data);
  } catch (error) {
    if (error instanceof CmsRequestError) throw error;
    throw new CmsRequestError(503, "The CMS service is unavailable.");
  }
}

/** Server entry point: resolves the request scope once and fetches a published page. */
export async function getCmsPage(slug: string, options: CmsFetchOptions = {}): Promise<CmsPage | null> {
  const scope = options.scope ?? await requestCmsScope();
  return fetchCmsPage(slug, { ...options, scope });
}

function configuredSlugs(): string[] {
  const raw = process.env.STOREFRONT_CMS_SLUGS ?? process.env.NEXT_PUBLIC_STOREFRONT_CMS_SLUGS ?? "";
  const fromEnv = raw.split(",").map((entry) => entry.trim()).filter((entry) => isValidCmsSlug(entry));
  return [...new Set([...fromEnv, ...CMS_DEFAULT_SLUGS])];
}

/** Discover CMS pages linked from the public navigation menu (best effort). */
export async function discoverCmsMenuSlugs(options: CmsFetchOptions = {}): Promise<string[]> {
  const baseUrl = options.baseUrl ?? cmsApiBaseUrl();
  if (!baseUrl) return [];
  try {
    const data = await jsonRequest(`${baseUrl}/api/menus/primary`, { ...options, revalidate: 3600, timeoutMs: options.timeoutMs ?? 2000 }, headersFor(options.scope));
    const menu = asRecord(data);
    const items = menu && Array.isArray(menu.items) ? menu.items : [];
    const slugs: string[] = [];
    for (const entry of items) {
      const item = asRecord(entry);
      const target = item ? safeInternalPath(item.target) : undefined;
      if (!target) continue;
      const match = /^\/pages\/([a-z0-9][a-z0-9-]{0,159})\/?$/.exec(target.split("?")[0] ?? "");
      if (match?.[1] && isValidCmsSlug(match[1])) slugs.push(match[1]);
    }
    return slugs;
  } catch {
    return [];
  }
}

export interface CmsPageSummary {
  slug: string;
  title: string;
  lastModified?: string;
}

export async function listPublishedCmsPages(options: CmsFetchOptions & { slugs?: string[] } = {}): Promise<CmsPageSummary[]> {
  const menuSlugs = options.slugs ? [] : await discoverCmsMenuSlugs(options);
  const candidates = [...new Set([...(options.slugs ?? []), ...configuredSlugs(), ...menuSlugs].filter((slug) => isValidCmsSlug(slug)))].slice(0, CMS_PAGE_LIMIT);
  if (candidates.length === 0) return [];
  const pages: CmsPageSummary[] = [];
  const batchSize = 8;
  for (let index = 0; index < candidates.length; index += batchSize) {
    const batch = candidates.slice(index, index + batchSize);
    const results = await Promise.all(batch.map(async (slug) => {
      try {
        return await fetchCmsPage(slug, options);
      } catch {
        return null;
      }
    }));
    for (const page of results) {
      if (!page) continue;
      pages.push({
        slug: page.slug,
        title: page.title,
        ...(page.updated_at ?? page.published_at ? { lastModified: page.updated_at ?? page.published_at ?? undefined } : {}),
      });
    }
  }
  return pages.slice(0, CMS_PAGE_LIMIT);
}

const ALLOWED_TAGS = new Set([
  "p", "br", "strong", "b", "em", "i", "u", "s", "del", "ins", "small", "sup", "sub",
  "ul", "ol", "li", "blockquote", "h1", "h2", "h3", "h4", "h5", "h6", "hr",
  "a", "code", "pre", "span", "div", "details", "summary", "dl", "dt", "dd",
  "figure", "figcaption",
]);
const VOID_TAGS = new Set(["br", "hr"]);
const TAG_ATTRIBUTES: Record<string, Set<string>> = {
  a: new Set(["href", "title", "target"]),
  details: new Set(["open"]),
};
const DEFAULT_ATTRIBUTES = new Set(["class", "title"]);
const MAX_HTML_LENGTH = 200_000;

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function decodeBasicEntities(value: string): string {
  return value
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, "\"")
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, "&");
}

function safeClass(value: string): string | undefined {
  const tokens = value.split(/\s+/).filter((token) => /^[A-Za-z0-9_-]{1,80}$/.test(token)).slice(0, 8);
  return tokens.length > 0 ? tokens.join(" ") : undefined;
}

function readAttributes(source: string): Array<[string, string]> {
  const attributes: Array<[string, string]> = [];
  const pattern = /([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let match: RegExpExecArray | null;
  while ((match = pattern.exec(source)) !== null) {
    const name = (match[1] ?? "").toLowerCase();
    if (!name) continue;
    const value = match[2] ?? match[3] ?? match[4] ?? "";
    attributes.push([name, value]);
  }
  return attributes;
}

function renderAttributes(tag: string, source: string): string {
  const allowed = TAG_ATTRIBUTES[tag] ?? DEFAULT_ATTRIBUTES;
  const output: Array<[string, string]> = [];
  for (const [name, rawValue] of readAttributes(source)) {
    if (name.startsWith("on")) continue;
    if (name === "class") {
      const value = safeClass(rawValue);
      if (value) output.push(["class", value]);
      continue;
    }
    if (!(TAG_ATTRIBUTES[tag]?.has(name) ?? DEFAULT_ATTRIBUTES.has(name))) continue;
    if (name === "href") {
      const href = safeHttpUrl(rawValue);
      if (href) output.push(["href", href]);
      continue;
    }
    if (name === "target") {
      if (rawValue === "_blank" || rawValue === "_self") output.push(["target", rawValue]);
      continue;
    }
    if (name === "open") {
      output.push(["open", ""]);
      continue;
    }
    if (name === "title") {
      output.push(["title", rawValue.slice(0, 320)]);
    }
  }
  if (output.some(([name, value]) => name === "target" && value === "_blank")) {
    output.push(["rel", "noopener noreferrer"]);
  }
  return output.map(([name, value]) => value === "" ? ` ${name}` : ` ${name}="${escapeHtml(value)}"`).join("");
}

/**
 * Allowlist HTML sanitizer for merchant-authored CMS content.
 *
 * It is intentionally conservative: unknown tags are dropped, every event
 * handler is removed, only http(s) or same-origin links survive, and the result
 * is re-escaped so malformed markup cannot smuggle executable HTML. It does not
 * depend on a DOM, so the same output is produced on the server and in tests.
 */
export function sanitizeCmsHtml(input: unknown): string {
  if (typeof input !== "string" || !input) return "";
  const source = input.slice(0, MAX_HTML_LENGTH);
  const pattern = /<!--[\s\S]*?-->|<\/?[A-Za-z][A-Za-z0-9:-]*(?:\s+[^<>]*?)?\s*\/?>/g;
  const stack: string[] = [];
  let output = "";
  let lastIndex = 0;
  let match: RegExpExecArray | null;
  const close = (index: number) => {
    while (stack.length > index) {
      const tag = stack.pop();
      if (tag) output += `</${tag}>`;
    }
  };
  while ((match = pattern.exec(source)) !== null) {
    output += escapeHtml(source.slice(lastIndex, match.index));
    lastIndex = pattern.lastIndex;
    const token = match[0];
    if (token.startsWith("<!--")) continue;
    const closing = /^<\s*\//.test(token);
    const nameMatch = /^<\s*\/?\s*([A-Za-z][A-Za-z0-9:-]*)/.exec(token);
    const tag = nameMatch?.[1]?.toLowerCase();
    if (!tag || !ALLOWED_TAGS.has(tag)) continue;
    if (closing) {
      const index = stack.lastIndexOf(tag);
      if (index !== -1) close(index);
      continue;
    }
    if (VOID_TAGS.has(tag) || /\/\s*>$/.test(token)) {
      const attributes = renderAttributes(tag, token.replace(/^<\s*[A-Za-z][A-Za-z0-9:-]*/, "").replace(/\/\s*>$/, ""));
      output += `<${tag}${attributes} />`;
      continue;
    }
    const attributes = renderAttributes(tag, token.replace(/^<\s*[A-Za-z][A-Za-z0-9:-]*/, "").replace(/>$/, ""));
    stack.push(tag);
    output += `<${tag}${attributes}>`;
  }
  output += escapeHtml(source.slice(lastIndex));
  close(0);
  return output;
}

export function cmsPlainText(value: unknown, max = 500): string | undefined {
  if (typeof value !== "string") return undefined;
  if (/[<>]/.test(value)) {
    const text = decodeBasicEntities(sanitizeCmsHtml(value).replace(/<[^>]*>/g, " ")).replace(/\s+/g, " ").trim();
    return text ? text.slice(0, max) : undefined;
  }
  const text = value.replace(/\s+/g, " ").trim();
  return text ? text.slice(0, max) : undefined;
}

function parseJsonContent(content: string): unknown {
  const trimmed = content.trim();
  if (!trimmed || !(trimmed.startsWith("{") || trimmed.startsWith("["))) return undefined;
  try {
    return JSON.parse(trimmed) as unknown;
  } catch {
    return undefined;
  }
}

function firstMedia(block: CmsBlock): CmsMediaReference | undefined {
  return block.media?.[0];
}

export interface CmsHeroContent {
  title: string;
  subtitle?: string;
  subtitleHtml?: string;
  image?: string;
  imageAlt?: string;
  ctaLabel?: string;
  ctaHref?: string;
}

export function readCmsHero(block: CmsBlock, fallbackTitle: string): CmsHeroContent {
  const json = asRecord(parseJsonContent(block.content));
  const media = firstMedia(block);
  if (json) {
    const title = cmsPlainText(json.title ?? json.heading, 160) ?? fallbackTitle;
    const rawCta = json.cta;
    const ctaRecord = asRecord(rawCta);
    const subtitle = cmsPlainText(json.subtitle ?? json.lead ?? json.body, 800);
    return {
      title,
      ...(subtitle ? { subtitle } : {}),
      ...(safeImageUrl(json.image ?? json.image_url ?? json.imageUrl ?? json.src ?? media?.url) ? { image: safeImageUrl(json.image ?? json.image_url ?? json.imageUrl ?? json.src ?? media?.url) } : {}),
      ...((cmsPlainText(json.image_alt ?? json.imageAlt, 320) ?? media?.alt) ? { imageAlt: cmsPlainText(json.image_alt ?? json.imageAlt, 320) ?? media?.alt } : {}),
      ...(typeof rawCta === "string" ? { ctaLabel: cmsPlainText(rawCta, 80) } : {}),
      ...(ctaRecord && cmsPlainText(ctaRecord.label ?? ctaRecord.title, 80) ? { ctaLabel: cmsPlainText(ctaRecord.label ?? ctaRecord.title, 80) } : {}),
      ...(safeInternalPath(json.cta_href ?? json.ctaHref ?? ctaRecord?.href ?? ctaRecord?.url) ? { ctaHref: safeInternalPath(json.cta_href ?? json.ctaHref ?? ctaRecord?.href ?? ctaRecord?.url) } : {}),
    };
  }
  const raw = block.content.trim();
  if (!raw) {
    return {
      title: fallbackTitle,
      ...(media?.url ? { image: media.url } : {}),
      ...(media?.alt ? { imageAlt: media.alt } : {}),
    };
  }
  if (/<[a-z!/]/i.test(raw)) {
    return {
      title: fallbackTitle,
      subtitleHtml: sanitizeCmsHtml(raw),
      ...(media?.url ? { image: media.url } : {}),
      ...(media?.alt ? { imageAlt: media.alt } : {}),
    };
  }
  const lines = raw.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  const title = cmsPlainText(lines[0], 160) ?? fallbackTitle;
  const subtitle = cmsPlainText(lines.slice(1).join(" "), 800);
  return {
    title,
    ...(subtitle ? { subtitle } : {}),
    ...(media?.url ? { image: media.url } : {}),
    ...(media?.alt ? { imageAlt: media.alt } : {}),
  };
}

export interface CmsImageContent {
  src: string;
  alt: string;
  caption?: string;
  href?: string;
}

export function readCmsImage(block: CmsBlock): CmsImageContent | null {
  const json = asRecord(parseJsonContent(block.content));
  const media = firstMedia(block);
  const fromHtml = /<img[^>]+src\s*=\s*["']([^"']+)["']/i.exec(block.content)?.[1];
  const src = safeImageUrl(json?.src ?? json?.url ?? json?.image ?? fromHtml ?? media?.url);
  if (!src) return null;
  const alt = cmsPlainText(json?.alt ?? json?.image_alt ?? json?.imageAlt ?? media?.alt, 320) ?? "";
  return {
    src,
    alt,
    ...(cmsPlainText(json?.caption, 320) ? { caption: cmsPlainText(json?.caption, 320) } : {}),
    ...(safeInternalPath(json?.href) ? { href: safeInternalPath(json?.href) } : {}),
  };
}

export interface CmsCtaContent {
  label: string;
  href?: string;
  copy?: string;
}

export function readCmsCta(block: CmsBlock): CmsCtaContent | null {
  const json = asRecord(parseJsonContent(block.content));
  const anchor = /<a[^>]+href\s*=\s*["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/i.exec(block.content);
  const label = cmsPlainText(json?.label ?? json?.title, 120)
    ?? (anchor?.[2] ? cmsPlainText(anchor[2], 120) : undefined)
    ?? cmsPlainText(block.content, 120);
  if (!label) return null;
  const href = safeInternalPath(json?.href ?? json?.url) ?? (anchor?.[1] ? safeHttpUrl(anchor[1]) : undefined);
  return {
    label,
    ...(href ? { href } : {}),
    ...(cmsPlainText(json?.copy ?? json?.text, 320) ? { copy: cmsPlainText(json?.copy ?? json?.text, 320) } : {}),
  };
}

export interface CmsFaqEntry {
  question: string;
  answer: string;
}

export function readCmsFaq(block: CmsBlock): { entries: CmsFaqEntry[]; html?: string } {
  const parsed = parseJsonContent(block.content);
  const items = Array.isArray(parsed) ? parsed : Array.isArray(asRecord(parsed)?.items) ? asRecord(parsed)?.items as unknown[] : [];
  const entries = items.slice(0, 50).flatMap((entry) => {
    const record = asRecord(entry);
    if (!record) return [];
    const question = cmsPlainText(record.question ?? record.summary ?? record.q, 320);
    const answer = cmsPlainText(record.answer ?? record.a, 2000);
    return question && answer ? [{ question, answer }] : [];
  });
  if (entries.length > 0) return { entries };
  if (/<details/i.test(block.content)) return { entries: [], html: sanitizeCmsHtml(block.content) };
  return { entries: [] };
}

export interface CmsPageMetadata {
  title: string;
  description?: string;
  canonical: string;
  robots: { index: boolean; follow: boolean };
  openGraphDescription?: string;
}

export function resolveCmsMetadata(page: CmsPage, canonicalPath?: string): CmsPageMetadata {
  const title = cmsPlainText(page.seo.title, 255) ?? page.title;
  const description = cmsPlainText(page.seo.description, 512);
  const canonical = safeHttpUrl(page.seo.canonical) ?? canonicalPath ?? `/pages/${page.slug}`;
  const directives = (page.seo.robots ?? "index,follow").toLowerCase().split(/[\s,]+/).filter(Boolean);
  return {
    title,
    ...(description ? { description } : {}),
    canonical,
    robots: { index: !directives.includes("noindex") && !directives.includes("none"), follow: !directives.includes("nofollow") && !directives.includes("none") },
    ...(description ? { openGraphDescription: description } : {}),
  };
}
