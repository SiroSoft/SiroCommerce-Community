# Extension Admin UI — Spec (RULE-E8 v2)

Saleor-style embedded views, v1: extensions render **inside** the admin shell
from manifest-declared panels — no iframes, no separate app bridge, no
extension-owned top-level menus (RULE-E8 v1 constraints stay).

## Manifest (`admin` block, optional)

```php
'admin' => [
    'label' => 'Affiliates',            // required if admin present; 2..48 chars
    'section' => 'Growth',              // optional grouping, default 'Extensions'
    'panels' => [                       // max 8
        [
            'key' => 'overview',        // [a-z0-9_], unique per extension
            'title' => 'Overview',      // 2..64 chars
            'type' => 'stats|table',    // stats: [{label, value}]; table: {columns?, rows}
            'endpoint' => 'overview',   // relative to the extension API mount
            'permission' => 'affiliate:manage', // must be in manifest permissions
        ],
    ],
],
```

Rules (fail-closed at discovery/install):
- `permission` must be listed in manifest `permissions`, else install 422.
- `endpoint` is a relative path under `/api/ext/<id>/` — never absolute, no
  `..`, no scheme. Admin calls it with the staff session (same auth,
  throttle, capability middleware as any ext route).
- Unknown `type` → install 422. Max 8 panels.

## Backend

- `ExtensionManifest` carries `admin: {label, section, panels}` (validated,
  part of `toArray()`).
- Admin list/detail (`ExtensionAdminController`) exposes `admin_ui` per
  extension: `{label, section, href: '/extensions/<encoded-id>', panels}` —
  only for `enabled` extensions; others get `admin_ui: null`.
- New: `GET /api/admin/extensions/ui` → nav entries for enabled extensions
  with an admin block: `{id, label, section, href, panels: [{key, title,
  type}]}` (no endpoint URLs leaked beyond what panels need — actually
  endpoints ARE needed by admin to fetch; they are same-origin ext routes
  under the staff session, so include `endpoint`).
- Panel endpoints are ordinary extension routes (affiliate reference adds
  `GET admin/overview` returning `{stats: [...], table: {columns, rows}}`).
  The 10s+ rule applies: slow panels fail into `ApiState`, never the page.

## Admin

- `/extensions` keeps lifecycle management; each enabled extension with an
  admin block links to `/extensions/<id>` (id URL-encoded, slashes kept).
- `/extensions/[id]` page: header (label + state + version), lifecycle
  buttons (reuse), then one card per panel fetched from its endpoint:
  - `stats` → `{stats: [{label, value}]}` definition list.
  - `table` → `{columns?: [{key, label}], rows: [...]}` table (columns
    default to keys of the first row, max 50 rows rendered).
  - Fetch failure → per-panel `ApiState` with retry (one bad panel never
    breaks the page or siblings).
- Sidebar Extensions section lists entries per enabled extension (capability
  `products` holders see them; per-panel permission enforced by the ext
  route's own `cap:` middleware → 403 surfaces as panel error, not page).
- Command palette gains the extension pages (same keywords as sidebar).

## Limits (v1)

- No custom React per extension, no iframes, no dashboard widgets, no
  storefront UI (RULE-E8 v1 text unchanged).
- Panels are read-only GETs. Mutations stay on the extension's own routes
  with Idempotency-Key (affiliate payout keeps its existing flow).
