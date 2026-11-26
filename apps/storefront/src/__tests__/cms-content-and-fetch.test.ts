import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  CmsRequestError,
  cmsPlainText,
  discoverCmsMenuSlugs,
  fetchCmsPage,
  getCmsPage,
  isValidCmsSlug,
  listPublishedCmsPages,
  parseCmsPage,
  readCmsCta,
  readCmsFaq,
  readCmsHero,
  readCmsImage,
  resolveCmsMetadata,
  safeHttpUrl,
  safeImageUrl,
  safeInternalPath,
  sanitizeCmsHtml,
  type CmsBlock,
  type CmsPage,
} from "@/lib/cms";

const scope = { store_id: "store-1", channel_id: "web", locale: "en", currency: "USD" };

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function block(type: string, content: string, extra: Partial<CmsBlock> = {}): CmsBlock {
  return { type, content, ...extra };
}

function page(overrides: Partial<CmsPage> = {}): CmsPage {
  return { slug: "about", title: "About", blocks: [], seo: {}, ...overrides };
}

beforeEach(() => {
  vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "https://api.example.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe("CMS path safety", () => {
  it("accepts only same-origin absolute paths", () => {
    expect(safeInternalPath("/pages/about")).toBe("/pages/about");
    expect(safeInternalPath(" /pages/about ")).toBe("/pages/about");
    expect(safeInternalPath("https://example.test/x")).toBeUndefined();
    expect(safeInternalPath("//evil.example/x")).toBeUndefined();
    expect(safeInternalPath("/a\\b")).toBeUndefined();
    expect(safeInternalPath("/a\u0001b")).toBeUndefined();
    expect(safeInternalPath("/too-long", 5)).toBeUndefined();
    expect(safeInternalPath(42)).toBeUndefined();
    expect(safeInternalPath("")).toBeUndefined();
  });

  it("allows https and internal URLs but rejects other schemes", () => {
    expect(safeHttpUrl("https://cdn.example.test/img.png")).toBe("https://cdn.example.test/img.png");
    expect(safeHttpUrl("/local/img.png")).toBe("/local/img.png");
    expect(safeHttpUrl("http://cdn.example.test/img.png")).toBeUndefined();
    expect(safeHttpUrl("javascript:alert(1)")).toBeUndefined();
    expect(safeImageUrl(undefined)).toBeUndefined();
  });
});

describe("CMS page parsing", () => {
  it("rejects non-objects, invalid slugs and empty titles", () => {
    expect(parseCmsPage(null)).toBeNull();
    expect(parseCmsPage("nope")).toBeNull();
    expect(parseCmsPage({ slug: "Bad Slug", title: "x" })).toBeNull();
    expect(parseCmsPage({ slug: "valid", title: "   " })).toBeNull();
    expect(isValidCmsSlug("valid-slug")).toBe(true);
    expect(isValidCmsSlug("-bad")).toBe(false);
  });

  it("keeps allowlisted fields and drops malformed blocks and media", () => {
    const parsed = parseCmsPage({
      id: 12,
      slug: "about",
      title: "  About us  ",
      body: "<p>Body</p>",
      locale: "en",
      status: "published",
      published_at: "2026-01-01",
      scheduled_at: "2026-02-01",
      updated_at: "2026-01-02",
      media: [
        { id: "m-1", url: "https://cdn.example.test/a.png", alt: "A", title: "T", mime: "image/png" },
        { url: "javascript:alert(1)" },
        "bad",
      ],
      blocks: [
        { id: "b-1", type: " Hero ", content: "hi", sort: 2, status: "published", media: [{ url: "/media/b.png" }] },
        { type: "" },
        "bad",
        null,
      ],
      seo: { title: "Seo", description: "Desc", canonical: "/pages/about", robots: "noindex" },
    });

    expect(parsed).toMatchObject({ id: "12", slug: "about", title: "About us", locale: "en", status: "published" });
    expect(parsed?.media).toEqual([{ id: "m-1", url: "https://cdn.example.test/a.png", alt: "A", title: "T", mime: "image/png" }]);
    expect(parsed?.blocks).toEqual([{ id: "b-1", type: "hero", content: "hi", sort: 2, status: "published", media: [{ url: "/media/b.png" }] }]);
    expect(parsed?.seo).toEqual({ title: "Seo", description: "Desc", canonical: "/pages/about", robots: "noindex" });
    expect(parsed?.blocks).toHaveLength(1);
  });

  it("uses null body and seo defaults when absent", () => {
    const parsed = parseCmsPage({ slug: "faq", title: "FAQ" });
    expect(parsed).toMatchObject({ body: null, blocks: [], media: [], seo: { title: null, description: null, canonical: null, robots: null } });
  });
});

describe("CMS block readers", () => {
  it("reads JSON hero content including a string CTA", () => {
    const hero = readCmsHero(block("hero", JSON.stringify({ title: "Hi", lead: "Lead", image: "/a.png", image_alt: "Alt", cta: "Shop", cta_href: "/search" })), "Fallback");
    expect(hero).toEqual({ title: "Hi", subtitle: "Lead", image: "/a.png", imageAlt: "Alt", ctaLabel: "Shop", ctaHref: "/search" });
  });

  it("reads object CTA and media fallbacks for hero", () => {
    const hero = readCmsHero(block("hero", JSON.stringify({ heading: "Heading", cta: { label: "Go", href: "/go" } }), { media: [{ url: "/hero.png", alt: "Hero alt" }] }), "Fallback");
    expect(hero).toMatchObject({ title: "Heading", ctaLabel: "Go", ctaHref: "/go", image: "/hero.png", imageAlt: "Hero alt" });
  });

  it("renders HTML and newline hero bodies", () => {
    expect(readCmsHero(block("hero", "<p>Hello</p>"), "Fallback")).toMatchObject({ title: "Fallback", subtitleHtml: "<p>Hello</p>" });
    expect(readCmsHero(block("hero", "Title\nSubtitle line"), "Fallback")).toMatchObject({ title: "Title", subtitle: "Subtitle line" });
    expect(readCmsHero(block("hero", "   "), "Fallback")).toEqual({ title: "Fallback" });
  });

  it("reads image blocks from JSON, HTML and media", () => {
    expect(readCmsImage(block("image", JSON.stringify({ src: "/a.png", alt: "A", caption: "Cap", href: "/go" })))).toEqual({ src: "/a.png", alt: "A", caption: "Cap", href: "/go" });
    expect(readCmsImage(block("image", '<img src="/html.png" alt="Html">'))).toMatchObject({ src: "/html.png" });
    expect(readCmsImage(block("image", "", { media: [{ url: "/media.png", alt: "Media" }] }))).toEqual({ src: "/media.png", alt: "Media" });
    expect(readCmsImage(block("image", JSON.stringify({ src: "javascript:alert(1)" })))).toBeNull();
  });

  it("reads CTA blocks from JSON, anchors and plain text", () => {
    expect(readCmsCta(block("cta", JSON.stringify({ label: "Buy", href: "/buy", copy: "Now" })))).toEqual({ label: "Buy", href: "/buy", copy: "Now" });
    expect(readCmsCta(block("cta", '<a href="https://example.test/x">External</a>'))).toEqual({ label: "External", href: "https://example.test/x" });
    expect(readCmsCta(block("cta", "Just text"))).toEqual({ label: "Just text" });
    expect(readCmsCta(block("cta", ""))).toBeNull();
  });

  it("reads FAQ arrays, wrapped items, and details HTML", () => {
    expect(readCmsFaq(block("faq", JSON.stringify([{ q: "Q1", a: "A1" }, { summary: "Q2", answer: "A2" }, { question: "", answer: "x" }])))).toEqual({
      entries: [{ question: "Q1", answer: "A1" }, { question: "Q2", answer: "A2" }],
    });
    expect(readCmsFaq(block("faq", JSON.stringify({ items: [{ question: "Wrapped", answer: "Yes" }] }))).entries).toHaveLength(1);
    const details = readCmsFaq(block("faq", "<details><summary>Q</summary><p>A</p></details>"));
    expect(details.entries).toHaveLength(0);
    expect(details.html).toContain("<details>");
    expect(readCmsFaq(block("faq", "not json")).entries).toHaveLength(0);
  });

  it("converts plain text and sanitized HTML", () => {
    expect(cmsPlainText("<p>Hello &amp; <strong>bye</strong></p>")).toBe("Hello &amp; bye");
    expect(cmsPlainText("  plain   text  ")).toBe("plain text");
    expect(cmsPlainText("", 10)).toBeUndefined();
    expect(cmsPlainText(12)).toBeUndefined();
  });

  it("sanitizes attributes, comments and malformed nesting", () => {
    const html = sanitizeCmsHtml('<!-- note --><div class="one two three" data-x="1"><span title="ok" onclick="x">Hi</span></div><a href="/go" target="_blank" title="t">Go</a><a href="/self" target="_self">Self</a><details open><summary>Q</summary></details><br><hr/>');
    expect(html).not.toContain("<!--");
    expect(html).not.toContain("onclick");
    expect(html).not.toContain("data-x");
    expect(html).toContain('class="one two three"');
    expect(html).toContain('rel="noopener noreferrer"');
    expect(html).toContain('target="_self"');
    expect(html).toContain("<br />");
    expect(html).toContain("<hr />");
    expect(sanitizeCmsHtml(undefined)).toBe("");
  });

  it("drops unknown tags and stray closing tags", () => {
    expect(sanitizeCmsHtml("<script>bad()</script><p>ok</p></unknown>")).toBe("bad()<p>ok</p>");
    expect(sanitizeCmsHtml("<p>unclosed")).toBe("<p>unclosed</p>");
  });
});

describe("CMS metadata", () => {
  it("prefers SEO fields and derives robots directives", () => {
    const meta = resolveCmsMetadata(page({ seo: { title: "SEO", description: "Desc", canonical: "https://shop.example.test/about", robots: "noindex, nofollow" } }));
    expect(meta).toEqual({ title: "SEO", description: "Desc", canonical: "https://shop.example.test/about", robots: { index: false, follow: false }, openGraphDescription: "Desc" });
  });

  it("falls back to the page title and default canonical", () => {
    expect(resolveCmsMetadata(page({ slug: "contact", title: "Contact" }))).toEqual({ title: "Contact", canonical: "/pages/contact", robots: { index: true, follow: true } });
    expect(resolveCmsMetadata(page({ seo: { robots: "none" } }), "/custom").robots).toEqual({ index: false, follow: false });
  });
});

describe("CMS transport", () => {
  it("skips invalid slugs and missing base URLs", async () => {
    const transport = vi.fn();
    await expect(fetchCmsPage("Bad Slug", { fetch: transport, baseUrl: "https://api.example.test" })).resolves.toBeNull();
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "");
    await expect(fetchCmsPage("about", { fetch: transport })).resolves.toBeNull();
    expect(transport).not.toHaveBeenCalled();
  });

  it("returns null on 404 and unwraps data envelopes", async () => {
    const missing = vi.fn(async () => json({ success: false }, 404));
    await expect(fetchCmsPage("about", { fetch: missing, baseUrl: "https://api.example.test", scope })).resolves.toBeNull();

    const found = vi.fn(async () => json({ data: { slug: "about", title: "About", blocks: [], seo: {} } }));
    await expect(fetchCmsPage("about", { fetch: found, baseUrl: "https://api.example.test", scope })).resolves.toMatchObject({ slug: "about", title: "About" });
    const [url, init] = found.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("https://api.example.test/api/pages/about");
    expect(init.headers).toMatchObject({ Accept: "application/json" });
  });

  it("throws a typed error on non-OK responses and wraps transport failures", async () => {
    await expect(fetchCmsPage("about", { fetch: vi.fn(async () => json({}, 500)), baseUrl: "https://api.example.test" })).rejects.toBeInstanceOf(CmsRequestError);
    await expect(fetchCmsPage("about", { fetch: vi.fn(async () => { throw new TypeError("offline"); }), baseUrl: "https://api.example.test" })).rejects.toMatchObject({ status: 503 });
  });

  it("aborts a request that exceeds its timeout", async () => {
    vi.useFakeTimers();
    const transport = vi.fn((_url: string, init?: RequestInit) => new Promise<Response>((_resolve, reject) => {
      init?.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
    }));
    const pending = fetchCmsPage("about", { fetch: transport as unknown as typeof fetch, baseUrl: "https://api.example.test", timeoutMs: 10 });
    const settled = pending.catch((error: unknown) => error);
    await vi.advanceTimersByTimeAsync(20);
    expect(await settled).toMatchObject({ status: 503 });
  });

  it("resolves the request scope for getCmsPage only when not supplied", async () => {
    const transport = vi.fn(async () => json({ data: { slug: "about", title: "About", blocks: [], seo: {} } }));
    await expect(getCmsPage("about", { fetch: transport, baseUrl: "https://api.example.test", scope })).resolves.toMatchObject({ title: "About" });
    expect(transport).toHaveBeenCalledOnce();
  });

  it("discovers page slugs from the primary menu and ignores unsafe targets", async () => {
    vi.stubEnv("NEXT_PUBLIC_STOREFRONT_API_BASE_URL", "");
    await expect(discoverCmsMenuSlugs({ baseUrl: "" })).resolves.toEqual([]);

    const transport = vi.fn(async () => json({ data: { items: [{ target: "/pages/about" }, { target: "/pages/faq/" }, { target: "https://evil.test/pages/x" }, { target: "/pages/Bad Slug" }, { target: 42 }] } }));
    await expect(discoverCmsMenuSlugs({ fetch: transport, baseUrl: "https://api.example.test", scope })).resolves.toEqual(["about", "faq"]);

    const failing = vi.fn(async () => { throw new Error("down"); });
    await expect(discoverCmsMenuSlugs({ fetch: failing, baseUrl: "https://api.example.test" })).resolves.toEqual([]);
  });

  it("lists configured pages, de-duplicates and skips failing pages", async () => {
    const transport = vi.fn(async (url: string) => {
      if (url.endsWith("/pages/about")) return json({ data: { slug: "about", title: "About", updated_at: "2026-01-02", blocks: [], seo: {} } });
      if (url.endsWith("/pages/contact")) return json({ data: { slug: "contact", title: "Contact", published_at: "2026-01-01", blocks: [], seo: {} } });
      return json({}, 500);
    });
    const pages = await listPublishedCmsPages({ fetch: transport as unknown as typeof fetch, baseUrl: "https://api.example.test", slugs: ["about", "about", "contact", "broken"] });
    expect(pages).toEqual([
      { slug: "about", title: "About", lastModified: "2026-01-02" },
      { slug: "contact", title: "Contact", lastModified: "2026-01-01" },
    ]);
  });
});
