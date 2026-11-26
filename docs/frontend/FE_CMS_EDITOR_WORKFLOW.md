# FE CMS Editor Workflow

Status: `FE workflow contract`

## Screens and scope

| Screen | Role/capability | Scope |
|---|---|---|
| Page list/editor | Marketer, editor, owner/admin with CMS capability | Store, channel, locale, currency |
| Block editor | Same | Inherits page scope |
| Preview | Same | Draft scope; no publish side effect |
| Revision history/diff | Same | Exact page type/id and four scope headers |
| Publish/restore confirmation | CMS capability and authenticated staff actor | Exact page/revision scope |

The FE must send `X-Store-Id`, `X-Channel-Id`, `X-Locale`, and `X-Currency` for
CMS records/revisions. It must not allow a page or revision from a different
scope to be selected. Scope is selected centrally, not per component.

## Route mapping

Mapped operation count: **10**.

| Screen action | Route | operationId |
|---|---|---|
| Page list/create/edit | `GET /api/admin/pages`, `POST /api/admin/pages`, `PUT /api/admin/pages/{id}` | `get_api_admin_pages`, `post_api_admin_pages`, `put_api_admin_pages_id` |
| Block update/add | `PUT /api/admin/blocks/{id}`, `POST /api/admin/pages/{id}/blocks` | `put_api_admin_blocks_id`, `post_api_admin_pages_id_blocks` |
| Preview | `GET /api/admin/pages/{id}/preview` | `get_api_admin_pages_id_preview` |
| Revision history | `GET /api/admin/cms-revisions/{type}/{id}` | `get_api_admin_cms_revisions_type_id` |
| Publish/restore | `POST /api/admin/pages/{id}/publish`, `POST /api/admin/pages/{id}/restore/{revision}` | `post_api_admin_pages_id_publish`, `post_api_admin_pages_id_restore_revision` |
| Public verification | `GET /api/pages/{slug}` | `get_api_pages_slug` |

Publish and restore require authenticated staff, CMS capability, all scope
headers, and `Idempotency-Key`. The same key and equivalent request replays the
original result. Reusing a key for another page or a different payload is a
recoverable conflict. Successful mutations emit the standard audit event.

## State machine and editor behavior

FE states: `loading`, `clean`, `dirty`, `saving`, `saved`, `previewing`,
`publishing`, `published`, `restoring`, `conflict`, `forbidden`, and `failed`.
These are UI states. The backend does not publish a complete page/revision state
enum in the reviewed contract.

- Keep a local draft and dirty-field indicator; navigation requires explicit
  discard confirmation.
- Preview reads the selected scoped draft and never implies publication.
- Publish confirmation shows the selected page/scope and revision evidence. On
  timeout, read the page/revision before retrying with the same key.
- Restore creates/returns the backend result; do not overwrite local content
  silently. Refresh revision history after success.
- On `409`, retain the local draft, fetch current page/revisions, and offer a
  diff/merge decision. Never last-write-wins in the browser.
- `GET /api/pages/{slug}` is verification of public output only; it is not proof
  that a preview is publishable.

## Acceptance tests

1. A page cannot load or mutate when any required scope header is missing.
2. A revision from another store/channel/locale/currency is rejected and cannot
   be restored.
3. Double-click publish creates one mutation and replay returns the original result.
4. A changed idempotency payload renders `409` without losing the draft.
5. Preview never changes public output; public verification changes only after a
   successful publish result and re-read.
6. Concurrent editor changes produce a conflict and a usable diff path.
7. Audit/correlation information is available to authorized support users without
   exposing raw request data.

## Unresolved gaps

- The OpenAPI does not define revision payload shape, diff/merge semantics,
  page publish state, validation rules, or preview isolation guarantees.
- Capability names are not exposed for CMS operations; the FE can only react to
  backend authorization.
- Draft autosave, media upload, scheduling, rollback depth, and cache invalidation
  are not specified and must not be invented.
