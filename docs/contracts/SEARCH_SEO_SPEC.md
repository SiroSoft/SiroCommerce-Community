# Search SEO — URL Rewrites / Search Terms / Search Synonyms / Sitemaps (better than Bagisto)

Status: Spec v0.1 — presentation-only extension, backend truth, no fork, allow custom
Owners: 1 backend + 1 frontend

## 1. Goal (làm tốt hơn Bagisto /admin/marketing/search-seo)

Bagisto có 4 mục: URL Rewrites (request_path → target, 301/302), Search Terms (term, hits, misses), Search Synonyms (từ đồng nghĩa), Sitemaps (frequency/priority per Products/Categories/CMS/Brands). Siro đã có `SeoController:sitemap/redirects`, `GET /api/redirects/resolve`, bộ route `/api/admin/search-seo/*` (url-rewrites, search-terms, synonyms, sitemaps) và `SearchOperations` reindex; Sitemaps manager có `GET/PUT /api/admin/search-seo/sitemaps` và `POST /api/admin/search-seo/sitemaps/generate`.

Mục tiêu: gom thành **Search SEO registry** `search-seo` giống Configuration spec, có Search, CRUD, analytics, và Sitemaps multi-store + image sitemap, Search Terms tự log, Synonyms áp dụng trước query.

## 2. Non-goals
- Không thay `/api/products?q=` hiện có, chỉ wrap.
- Không cho HTML/JS trong rewrites/terms/synonyms (validate path, term, không `<script>`).
- Không lưu PII search user.

## 3. Ownership
Backend: migrations, validation, GET/POST/PUT/DELETE, scope cache, sitemap generate, synonym apply, OpenAPI, seed demo.
Frontend: `app/(dashboard)/search-seo/page.tsx` 4 tabs + Search + CRUD + preview, scope-aware.
Shared: custom thêm trường sitemap (ví dụ brand priority) qua seed, không fork.

## 4. Contracts (admin: auth:admin,owner,marketer, cap:seo/search, DemoGuard)

### URL Rewrites
```
GET    /api/admin/search-seo/url-rewrites?q=&page=&per_page=
POST   /api/admin/search-seo/url-rewrites          { request_path, target_path, redirect_type:301|302, store_id?, channel_id? }
PUT    /api/admin/search-seo/url-rewrites/{id}     { request_path?, target_path?, redirect_type? }
DELETE /api/admin/search-seo/url-rewrites/{id}
GET    /api/admin/search-seo/url-rewrites/{id}
```
`request_path` `^/[a-z0-9/_-]{1,200}$`, `target_path` `^/[a-z0-9/_-]{1,500}$` hoặc `https://`, `redirect_type` 301/302, `store_id/channel_id` optional scope. 422 nếu loop (`request==target`), 409 nếu duplicate `request_path+store` . Frontend: `GET /{request_path}` → 301/302 nếu match (middleware).

### Search Terms (analytics)
```
GET    /api/admin/search-seo/search-terms?q=&page=&per_page=&sort=hits|misses
POST   /api/admin/search-seo/search-terms/sync  (optional manual sync, nhưng auto log chính)
```
Auto log: mỗi `GET /api/products?q=term` (hoặc `/api/search?q=`) ghi `search_terms: term (lower, trim, 1-64), hits = results count>0?1:0, misses = hits?0:1, updated_at`. Không cần POST từ admin thường xuyên. Bagisto cho phép admin thêm term thủ công nhưng Siro auto là tốt hơn.

### Search Synonyms
```
GET    /api/admin/search-seo/synonyms?q=&page=&per_page=
POST   /api/admin/search-seo/synonyms            { term, synonyms: string[] (1-10, mỗi 1-64) }
PUT    /api/admin/search-seo/synonyms/{id}       { term?, synonyms? }
DELETE /api/admin/search-seo/synonyms/{id}
```
`term` `^[a-z0-9 _-]{1,64}$` lower, `synonyms` array 1-10, mỗi `^[a-z0-9 _-]{1,64}$`, không trùng term, không `<script>`. Áp dụng: trước khi `CatalogService` query, expand `q` → `q OR synonyms` (hoặc replace). Ví dụ `áo` → `ao, shirt` .

### Sitemaps
```
GET    /api/admin/search-seo/sitemaps
PUT    /api/admin/search-seo/sitemaps            { frequency: daily|weekly|monthly, priority: 0.0-1.0 per type: products, categories, cms_pages, brands, images_enabled: bool }
POST   /api/admin/search-seo/sitemaps/generate   (Idempotency-Key) → generate file(s)
GET    /sitemap.xml                              (public, đã có, mở rộng)
GET    /sitemap-products.xml , /sitemap-categories.xml ... (optional split)
```
Body `PUT`:
```json
{
  "products": { "frequency": "daily", "priority": 0.8 },
  "categories": { "frequency": "weekly", "priority": 0.6 },
  "cms_pages": { "frequency": "weekly", "priority": 0.5 },
  "brands": { "frequency": "monthly", "priority": 0.4 },
  "images_enabled": true
}
```
Validate: frequency in `always, hourly, daily, weekly, monthly, yearly, never`, priority 0.0-1.0, images_enabled bool.

Generate: ghi `storage/app/sitemap/*.xml` + update `public/sitemap.xml` index, cache 1h, cron `sitemap:generate` daily. Multi-store: `GET /sitemap.xml?store=default` hoặc `X-Store-Id`.

## 5. Persistence (additive)

```sql
CREATE TABLE url_rewrites (
  id VARCHAR(26) PRIMARY KEY,
  request_path VARCHAR(200) NOT NULL,
  target_path VARCHAR(500) NOT NULL,
  redirect_type SMALLINT NOT NULL DEFAULT 301,
  store_id VARCHAR(64) NOT NULL DEFAULT 'default',
  channel_id VARCHAR(64) NOT NULL DEFAULT 'web',
  created_by VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE(request_path, store_id, channel_id),
  INDEX(store_id, channel_id)
);

CREATE TABLE search_terms (
  id VARCHAR(26) PRIMARY KEY,
  term VARCHAR(64) NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  misses INT NOT NULL DEFAULT 0,
  results_avg INT NOT NULL DEFAULT 0,
  store_id VARCHAR(64) NOT NULL DEFAULT 'default',
  channel_id VARCHAR(64) NOT NULL DEFAULT 'web',
  updated_at DATETIME NOT NULL,
  UNIQUE(term, store_id, channel_id),
  INDEX(hits), INDEX(misses)
);

CREATE TABLE search_synonyms (
  id VARCHAR(26) PRIMARY KEY,
  term VARCHAR(64) NOT NULL,
  synonyms TEXT NOT NULL, -- JSON array
  store_id VARCHAR(64) NOT NULL DEFAULT 'default',
  channel_id VARCHAR(64) NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE(term, store_id, channel_id)
);

CREATE TABLE sitemap_settings (
  id VARCHAR(26) PRIMARY KEY,
  store_id VARCHAR(64) NOT NULL DEFAULT 'default',
  channel_id VARCHAR(64) NOT NULL DEFAULT 'web',
  settings_json TEXT NOT NULL, -- {products:{frequency,priority}, ...}
  version INT NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  UNIQUE(store_id, channel_id)
);
```

## 6. Validation
- `request_path/target_path` như trên, không `javascript:`, không `<script>`, `redirect_type` 301/302, scope keys `^[a-z0-9_-]{1,64}$`.
- `term/synonyms` lower, trim, 1-64, `synonyms` 1-10, không trùng term, không PII.
- `sitemap` frequency allowlist, priority 0.0-1.0, `images_enabled` bool.
- `Idempotency-Key` cho POST generate/POST rewrites (như storefront/config).

## 7. Apply synonyms (better than Bagisto)
- Trước khi search, `CatalogService` hoặc `SearchOperationsController` gọi `SynonymResolver::expand(q, store, channel)` → trả `q` + synonyms.
- Query: `WHERE name LIKE %q% OR name LIKE %syn1% ...` hoặc `OR` trong search index. Không thay đổi ranking core, chỉ mở rộng recall.
- Log Search Terms sau khi có results: `term` lower, `hits/misses` bump, `results_avg` moving avg.

## 8. Sitemaps better than Bagisto
- Bagisto chỉ cho frequency/priority per type, Siro thêm `images_enabled` (include `ProductImage` url), split files `sitemap-{type}.xml` + index, `store/channel` scope, `lastmod` từ `products.updated_at`, `changefreq` từ settings, cache 1h, cron daily `sitemap:generate`.
- Public `GET /sitemap.xml` (đã có) mở rộng để đọc `sitemap_settings` và generate nếu chưa có.

## 9. Admin UI
`app/(dashboard)/search-seo/page.tsx` 4 tabs:
- **URL Rewrites:** table `request → target (301)`, Search `q`, Create/Edit/Delete modal, preview redirect (fetch `/{request_path}` expect 301).
- **Search Terms:** table `term | hits | misses | results_avg | updated`, Search `q`, sort `hits/misses`, auto log, không cần create thủ công (nhưng có Sync button).
- **Search Synonyms:** table `term → synonyms`, Create/Edit (term + synonyms comma), Delete.
- **Sitemaps:** form `frequency/priority` per type + `images_enabled` Switch, Save (PUT), Generate button (POST generate với Idempotency-Key), preview `GET /sitemap.xml` link.

## 10. Tests
- Backend: `POST /url-rewrites` valid 201, duplicate 409, loop 422, `<script` 422, `GET /api/redirects/resolve` 301/302, `POST /synonyms` valid 201, apply synonyms expands query, `GET /search-terms` after `GET /api/products?q=ao` hits+1, `PUT /sitemaps` valid 200, `POST /generate` 202, `GET /sitemap.xml` 200 with products.
- Frontend: 4 tabs render, Search filter, CRUD modals, Sitemaps Save/Generate.

## 11. Rollout
1. Migrations 4 tables
2. Controllers `SearchSeoController` (rewrites/terms/synonyms/sitemaps) + `SynonymResolver` + `SitemapGenerator` + middleware rewrite
3. Admin page + Sidebar
4. Cron `sitemap:generate` daily + auto log terms

## 12. Required commands
```
composer test --working-dir backend
npm run typecheck --workspace apps/admin
```
