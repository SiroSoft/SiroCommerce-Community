# SiroCommerce marketing site

Static, multilingual marketing site for SiroCommerce. English is the default
locale at `/en/`; Vietnamese lives at `/vi/`, with German `/de/`, Japanese
`/ja/` and Simplified Chinese `/zh/`. There is no database and no backend call
at runtime — every page is exported as static HTML and is ready for Cloudflare
Pages.

## Local development

```bash
npm install
npm run dev --workspace apps/marketing
```

Open `http://localhost:3000/en/` or `http://localhost:3000/vi/`.

```bash
npm run typecheck --workspace apps/marketing
npm run build --workspace apps/marketing
```

The build writes static files to `apps/marketing/out/`.

## Cloudflare Pages

Create a Pages project connected to this repository:

| Setting | Value |
|---|---|
| Framework preset | None |
| Root directory | `/` (repository root) |
| Build command | `npm ci && npm run build --workspace apps/marketing` |
| Build output directory | `apps/marketing/out` |
| Node version | 20 or newer (`NODE_VERSION=20`) |

Environment variables (Production and Preview):

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SITE_URL` | `https://sirocommerce.com` | Canonical URLs, sitemap, JSON-LD |
| `NEXT_PUBLIC_CONTACT_EMAIL` | `hello@sirocommerce.com` | Every `mailto:` CTA |

`public/_redirects` sends `/` to `/en/`, and `/en`/`/vi` to their trailing-slash
variants. `public/_headers` adds security headers and long-lived caching for
`/_next/static/*`.

After the first deploy:

1. Add the custom domain in Cloudflare Pages and create the DNS record Cloudflare
   shows (usually `CNAME` to `<project>.pages.dev`).
2. Set `NEXT_PUBLIC_SITE_URL` to the final `https://` domain and redeploy so
   canonical, hreflang, sitemap and JSON-LD all point at the real host.
3. Submit `https://<domain>/sitemap.xml` in Google Search Console and Bing Webmaster
   Tools.
4. Replace `public/og.png` and `public/og-<locale>.png` if branding or the
   headline changes, then re-run a social preview check.

## SEO / GEO / AIO

- Per-locale metadata, canonical, and `hreflang` (`en`, `vi`, `de`, `ja`,
  `zh-Hans`, `x-default`).
- `sitemap.xml` includes both locales and their alternates; `robots.txt` points to it.
- JSON-LD: Organization, WebSite, SoftwareApplication on every page; FAQPage with
  the visible Q&A on each locale.
- `llms.txt` and `vi/llms.txt` give AI answer engines a short, factual product
  summary and explicitly mark Pro/Enterprise as planned, not available.
- Semantic headings, real `<details>` FAQ markup, skip link, and no client-side
  JavaScript beyond Next.js hydration.
- The site is light-only by design; there is no dark theme and no gradient/AI
  visual treatment.

## Editing content

All copy lives in `src/content/en.ts`, `vi.ts`, `de.ts`, `ja.ts` and `zh.ts`,
typed by `src/content/types.ts`. Keep every locale in sync;
`npm run typecheck --workspace apps/marketing` fails if a required field is
missing. Short AI summaries are generated from `src/lib/llms.ts` and exposed at
`/llms.txt`, `/vi/llms.txt`, `/de/llms.txt`, `/ja/llms.txt` and `/zh/llms.txt`.

To add a locale:

1. Add the locale to `Locale`, `locales` and `hreflangByLocale` in
   `src/content/types.ts`.
2. Add the matching content file and wire it in `src/content/index.ts`.
3. Add the language to `languageAlternates` (built from `locales`) and the
   `llmsText` branch in `src/lib/llms.ts`.
4. Add an `llms.txt` route under `src/app/<locale>/llms.txt/` and an OG image
   under `public/og-<locale>.png`.

## Accuracy rules

- Community is free and self-hosted. Pro and Enterprise are **in development**;
  never present planned features as shipped.
- Do not add customer logos, revenue numbers, uptime figures, or testimonials
  unless they are real and approved.
- Payment and shipping integrations are code that requires the merchant's own
  credentials; keep that wording.
