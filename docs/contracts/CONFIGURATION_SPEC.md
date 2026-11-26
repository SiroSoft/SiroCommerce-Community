# Configuration Registry (Bagisto-style, presentation-only extension)

Status: Spec v0.1 — no fork, backend truth, allow custom section via seed/config
Owners: 1 backend + 1 frontend

## 1. Goal
Gom các settings rời (settings, storefront/theme, commerce) thành một **Configuration registry** kiểu Bagisto: `groups → sections → fields`, có Search, lưu theo scope `store/channel`, và cho phép **custom thêm 1 mục** (demo `custom.promo_banner`) mà không sửa core component.

Bagisto mapping (bạn liệt kê) → SIRO:

- General Settings / General / Affiliate / Units / Breadcrumbs → `general`
- Content (header offer, scripts) / Design (logo, favicon) / Magic AI / Sitemap / GDPR / App version → `content`, `design`, `cms`
- Catalog Products / Rich Snippets / Category Page / Inventory → `catalog`
- Customer Address / Google Captcha / Settings (wishlist, login, newsletter, group, verification, social) → `customer`
- Email Settings / Notifications → `email`
- Sales Shipping Settings/Methods / Payment Methods / Order/Invoice Settings / Taxes / Checkout (guest, mini-cart, summary) → `sales`
- Sitemap Settings / eKYC OCR / Popup → `sales` + `custom`
- Custom demo → `custom.promo_banner` (title, enabled, image)

## 2. Non-goals
- Không thay `/api/settings` hiện có (giữ để backward compat, proxy qua registry).
- Không cho HTML/CSS/JS tùy ý trong values (validate type, length, URL, không `<script>`).
- Không lưu secrets, provider keys, cart tokens trong configuration.

## 3. Ownership
Backend: migration, validation, GET/PUT, scope, cache, audit, OpenAPI, seed.
Frontend: page `/admin/configuration` render động theo `type`, Search, scope-aware, safe fallback.
Shared: custom section thêm bằng seed/config, không fork app.

## 4. Public contracts (admin only)

```
GET    /api/admin/configuration
GET    /api/admin/configuration/{group}
GET    /api/admin/configuration/{group}/{section}
PUT    /api/admin/configuration/{group}/{section}
```

Auth: `auth:admin,owner` + `DemoGuard` (như settings), `cap:settings` nếu có. Scope từ headers `X-Store-Id/X-Channel-Id` (optional, default `default/web`), không cho client tự authorize store khác (check như StorefrontConfig).

Example `GET /api/admin/configuration` (200):
```json
{
  "success": true,
  "data": {
    "groups": [
      {
        "key": "general",
        "name": "General Settings",
        "sections": [
          {
            "key": "general",
            "name": "General",
            "fields": [
              {"key":"site_name","type":"text","label":"Site name","default":"SiroCommerce","required":true,"max":120},
              {"key":"timezone","type":"select","label":"Timezone","options":["Asia/Ho_Chi_Minh","UTC"],"default":"Asia/Ho_Chi_Minh"}
            ]
          }
        ]
      },
      {
        "key": "custom",
        "name": "Custom",
        "sections": [
          {
            "key": "promo_banner",
            "name": "Promo Banner",
            "fields": [
              {"key":"title","type":"text","label":"Banner title","max":120},
              {"key":"enabled","type":"boolean","label":"Enabled","default":false},
              {"key":"image","type":"image","label":"Image URL","max":512}
            ]
          }
        ]
      }
    ],
    "values": {
      "general.general.site_name": "SiroCommerce",
      "custom.promo_banner.title": "Summer Sale"
    }
  }
}
```

`PUT /api/admin/configuration/general/general`
```json
{ "values": { "site_name": "My Shop", "timezone": "UTC" }, "expected_version": 1 }
→ 200 { values, version }
→ 409 stale expected_version, 422 validation, 403 scope mismatch
```

`PUT /api/admin/configuration/custom/promo_banner`
```json
{ "values": { "title": "Hello", "enabled": true, "image": "/uploads/banner.jpg" } }
```

## 5. Persistence (additive)

```sql
CREATE TABLE configuration_values (
  id VARCHAR(26) PRIMARY KEY,
  group_key VARCHAR(64) NOT NULL,
  section_key VARCHAR(64) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  store_id VARCHAR(64) NOT NULL DEFAULT 'default',
  channel_id VARCHAR(64) NOT NULL DEFAULT 'web',
  value TEXT NOT NULL,
  version INT NOT NULL DEFAULT 1,
  updated_by VARCHAR(26) NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE(group_key, section_key, field_key, store_id, channel_id),
  INDEX(group_key, section_key)
);
-- No overwrite of `settings` table; settings becomes a view over `general.*` for compat.
```

Seed: `config/configuration.php` hoặc migration seed `configuration_groups` (in-code registry là source, DB chỉ lưu values). Registry hiện hardcode trong `ConfigurationRegistry` (backend), custom thêm bằng cách thêm entry vào registry hoặc seed `configuration_sections` (cho phép plugin-style, không fork).

## 6. Validation

- `key` group/section/field: `^[a-z][a-z0-9_]{1,31}$`, scope keys `^[a-z0-9_-]{1,64}$`
- `type` in `text, textarea, select, boolean, number, image, url, color`
- `value` theo type: text 1-512, boolean bool, number int, image/url `safeUrl` (http/https hoặc `/uploads/`), color `^#[0-9A-Fa-f]{6}$`, không `<script|javascript:|data:` trong bất kỳ value nào (check json encode).
- `select` options phải nằm trong allowlist.
- `version` optimistic: `expected_version` phải khớp `MAX(version)` của section, stale → 409.
- Idempotency: `Idempotency-Key` header tuỳ chọn cho PUT (như storefront/config), replay trả same result.

Cache: `configuration:values:{store_id}:{channel_id}:{group}:{section}:{version}` 60s, invalidate on PUT.

## 7. Custom thêm 1 mục (demo)

Không fork:
1. Thêm file `config/configuration.php` entry `custom.promo_banner` hoặc migration insert vào `configuration_registry` seed:
```php
'custom' => [
  'promo_banner' => [
    'name' => 'Promo Banner',
    'fields' => [
      'title' => ['type'=>'text','label'=>'Banner title','max'=>120],
      'enabled' => ['type'=>'boolean','label'=>'Enabled','default'=>false],
      'image' => ['type'=>'image','label'=>'Image URL','max'=>512],
    ]
  ]
]
```
2. Admin tự hiện section mới trong Search và render động, lưu qua `PUT /api/admin/configuration/custom/promo_banner`.

Storefront có thể đọc `custom.promo_banner` qua `GET /api/storefront/configuration` extension (optional, chỉ khi enabled) hoặc `GET /api/configuration/public` (scope, cache 60s, chỉ trả custom sections marked `public: true`).

## 8. Admin UI

`app/(dashboard)/configuration/page.tsx`:
- Sidebar Search (như Bagisto bạn gửi), filter groups/sections/fields theo query.
- Render field theo `type`: text→Input, textarea→Textarea, select→select, boolean→Switch/checkbox, number→Input number, image/url→Input + preview, color→color input.
- Mỗi section có Save (PUT với `expected_version` + `Idempotency-Key`), hiển thị version, error 409 → reload.
- Scope switcher `X-Store-Id/X-Channel-Id` (nếu chọn store khác, gọi lại GET).
- Custom section `custom.promo_banner` xuất hiện như các section khác, không cần code riêng.

## 9. Tests

- Backend: `GET /api/admin/configuration` trả groups có `custom.promo_banner`, `PUT` valid 200 + version bump, `PUT` invalid `<script` 422, stale `expected_version` 409, scope khác 403 nếu không có quyền, `PUT` idempotent replay same result.
- Frontend: render động cho mỗi type, Search filter, custom section hiện, Save → success notice.

## 10. Rollout

1. Migration `configuration_values`
2. `ConfigurationRegistry` + `ConfigurationController` + routes + seed default groups + custom.promo_banner
3. Admin `configuration/page.tsx` + Sidebar entry
4. (Optional) Storefront public read cho custom banner khi enabled

## 11. Required commands

```
composer test --working-dir backend
npm run typecheck --workspace apps/admin
npm run test --workspace apps/admin
```
