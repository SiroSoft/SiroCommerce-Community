# 05 — SEO & Growth Spec (P0 architecture)

## 1. SEO Engine
- Mọi entity indexable có: title, description, slug, canonical, robots, OG.
- Structured data native: Product, Breadcrumb, Organization.
- Sitemap/index + redirect manager (`from UNIQUE → to, code 301/302`).
- Facet/filter/sort/pagination: canonical + indexability matrix (VD: page>1 → canonical page 1 + noindex follow; sort/filter → canonical clean URL).
- Detector: broken-link, orphan-product, duplicate-title, missing-desc, missing-ALT.
- SEO Health workflow: **Detect → Explain → Fix** (safe fix 1-click hoặc guided edit).

## 2. URL/indexability matrix (làm ở Phase 0, ví dụ)
| URL pattern | Canonical | Index |
|---|---|---|
| /c/shoes | self | index |
| /c/shoes?page=2 | /c/shoes | noindex,follow |
| /c/shoes?sort=price | /c/shoes | noindex,follow |
| /c/shoes?color=red | self nếu facet SEO-able, else canonical clean | cấu hình/facet allowlist |
| /p/slug?utm_* | clean slug | index (strip utm) |

## 3. Commerce analytics (P1)
Funnel: `View → AddToCart → Checkout → Payment → Order`. Surface cart/checkout abandonment, payment failure rate. So baseline gần nhất, flag anomaly material. Cấm claim causation từ correlation; link signal → trace để điều tra.

## 4. Storefront perf (liên kết 09)
Mobile-first, accessible, SSR/SEO-conscious, nằm trong performance budgets.
