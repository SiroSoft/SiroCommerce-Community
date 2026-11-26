/** Safe readers for the backend-authored homepage section props. Every value
 * that reaches the DOM is length-limited and URL-checked here so components
 * never need to trust the config payload. */

export type HomepageSectionProps = Record<string, unknown>;

export interface BannerGridItem {
  title: string;
  subtitle?: string;
  cta?: string;
  ctaHref?: string;
  href?: string;
  image?: string;
  imageAlt?: string;
}

export interface UspPoint {
  title: string;
  copy?: string;
  icon?: string;
}

export interface FaqEntry {
  question: string;
  answer: string;
}

const MAX_ITEMS = 8;
const MAX_TEXT = 320;

function text(value: unknown, max = MAX_TEXT): string | undefined {
  if (typeof value !== "string") return undefined;
  const trimmed = value.replace(/\s+/g, " ").trim();
  return trimmed ? trimmed.slice(0, max) : undefined;
}

function safeUrl(value: unknown): string | undefined {
  const url = text(value, 500);
  if (!url) return undefined;
  if (url.startsWith("/") && !url.startsWith("//")) return url;
  if (/^https:\/\//i.test(url)) return url;
  return undefined;
}

function recordArray(props: HomepageSectionProps | undefined, keys: string[]): Record<string, unknown>[] {
  if (!props) return [];
  for (const key of keys) {
    const value = props[key];
    if (Array.isArray(value)) {
      return value
        .filter((item): item is Record<string, unknown> => Boolean(item) && typeof item === "object" && !Array.isArray(item))
        .slice(0, MAX_ITEMS);
    }
  }
  return [];
}

export function readTextProp(props: HomepageSectionProps | undefined, key: string): string | undefined {
  return text(props?.[key]);
}

export function readBannerItems(props?: HomepageSectionProps): BannerGridItem[] {
  return recordArray(props, ["banners", "items"]).flatMap((item) => {
    const title = text(item.title);
    if (!title) return [];
    const rawCta = item.cta;
    let cta: string | undefined;
    let ctaHref: string | undefined;
    if (typeof rawCta === "string") cta = text(rawCta, 80);
    else if (rawCta && typeof rawCta === "object" && !Array.isArray(rawCta)) {
      const record = rawCta as Record<string, unknown>;
      cta = text(record.label, 80);
      ctaHref = safeUrl(record.href);
    }
    return [{
      title,
      subtitle: text(item.subtitle),
      cta,
      ctaHref,
      href: safeUrl(item.href) ?? ctaHref,
      image: safeUrl(item.image),
      imageAlt: text(item.image_alt) ?? text(item.imageAlt),
    }];
  });
}

export function readUspPoints(props?: HomepageSectionProps): UspPoint[] {
  return recordArray(props, ["items", "points"]).flatMap((item) => {
    const title = text(item.title);
    if (!title) return [];
    return [{ title, copy: text(item.copy), icon: text(item.icon, 40) }];
  });
}

export function readFaqEntries(props?: HomepageSectionProps): FaqEntry[] {
  return recordArray(props, ["items", "faqs"]).flatMap((item) => {
    const question = text(item.question) ?? text(item.summary);
    const answer = text(item.answer);
    if (!question || !answer) return [];
    return [{ question, answer }];
  });
}

export function hasSectionContent(type: string, props?: HomepageSectionProps): boolean {
  if (type === "banner_grid") return readBannerItems(props).length > 0;
  if (type === "usp_strip") return readUspPoints(props).length > 0;
  if (type === "faq") return readFaqEntries(props).length > 0;
  return true;
}
