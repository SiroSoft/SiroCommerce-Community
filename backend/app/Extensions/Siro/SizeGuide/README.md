# siro/size-guide

Free size-guide extension. Admins maintain one size chart per product category
for the active store/channel, and the storefront shows a "Size guide" button on
the product detail page (PDP) that opens an accessible table. Follows
`docs/contracts/EXTENSION_PACKAGING_SPEC.md`,
`docs/contracts/EXTENSION_UI_SPEC.md` and `docs/engineering/EXTENSION_SCOPING.md`.

## What it does

- Table `ext_size_guide_charts` is scoped by `store_id` + `channel_id` and keyed
  by `category_slug`. Unique key `(store_id, channel_id, category_slug)` keeps
  exactly one chart per category per scope.
- Admin routes (manifest permission `size-guide:manage`):
  - `GET /api/ext/siro/size-guide/admin/charts` lists the scope's charts.
  - `PUT /api/ext/siro/size-guide/admin/charts/{category}` upserts the chart for
    that category. Requires `Idempotency-Key` (extension loader) and returns the
    same canonical row on replay.
- Public route (anonymous, throttled):
  - `GET /api/ext/siro/size-guide/public?category={slug}` returns the active
    chart for the scope, or `data: null` when none is active.
- Storefront slot: `pdp`, id `siro/size-guide`, priority 130. The PDP passes
  `{ productId, productSlug, category }`; the component fetches only the
  category's public chart and renders nothing when the chart is missing, the
  request fails, or no category is supplied.

Every SELECT/UPDATE filters scope. Writes are merged through
`ExtensionScope::merge()`, so a caller cannot write into or read from another
store/channel.

## Chart rules

- `category` (route/query): lowercase slug, letters/digits/hyphens, max 160
  characters. No uppercase, spaces or underscores.
- `title`: 1-120 characters.
- `columns`: list of 1-8 column names, each 1-60 characters.
- `rows`: list of 1-50 rows; every row is a list whose length equals the number
  of columns. Cells are strings/numbers (max 200 characters each).
- `note`: optional, max 300 characters.
- `active`: optional boolean; defaults to `true` for a new chart and keeps the
  current value when omitted on update.
- No HTML, control characters or `javascript:` payloads are accepted. The
  storefront renders every cell and the note as text with React, never via
  `dangerouslySetInnerHTML`.

Invalid input returns `422`; a missing/ambiguous store or channel scope returns
`400`.

## Install / enable

```bash
php siro extension:check
php siro extension:pack app/Extensions/Siro/SizeGuide --out=dist/siro-size-guide-1.0.0.zip
php siro extension:verify dist/siro-size-guide-1.0.0.zip
php siro extension:qit dist/siro-size-guide-1.0.0.zip
# Admin -> Extensions -> siro/size-guide -> Install -> Enable
# (Enable runs migrations/20261124000002_create_ext_size_guide.php)
```

Disable unmounts all routes and keeps data. Uninstall with "remove data" runs
`down()` and drops `ext_size_guide_charts`; core tables are never touched.

## Storefront

Storefront plugin: `apps/storefront/src/plugins/size-guide/`.
`register.ts` registers slot `pdp` with id `siro/size-guide` and priority 130.
The component reads `props.data.category`, fetches the public chart with
`cache: "no-store"`, and renders a "Size guide" button that opens a native
`<dialog>` containing `<table>` with `<caption>`, `<thead>`, `<th scope="col">`
and row headers `<th scope="row">`, plus the optional note. The dialog can be
closed with its button or Escape. When there is no chart or the API fails, the
component renders nothing.

## Config

| Key | Default | Meaning |
| --- | --- | --- |
| `max_columns` | 8 | Documentation value; the validator enforces it. |
| `max_rows` | 50 | Documentation value; the validator enforces it. |
| `max_note_length` | 300 | Documentation value; the validator enforces it. |

No secrets, mailers or external providers. Admins maintain charts through the
extension admin panel/API.
