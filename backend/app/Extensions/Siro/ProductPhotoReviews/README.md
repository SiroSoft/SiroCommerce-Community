# siro/product-photo-reviews

Free product photo-reviews extension. Approved review photos render in the
PDP gallery slot (`pdp.below-gallery`); admins moderate individual photos
(approve/reject) and the review itself. Follows
`docs/contracts/EXTENSION_PACKAGING_SPEC.md`,
`docs/contracts/EXTENSION_UI_SPEC.md` and
`docs/engineering/EXTENSION_SCOPING.md`.

## What it does

- The photos themselves live on core `product_reviews.images_json` (Wave 0
  §2.7 — at most 5 already-uploaded media URLs per review, never raw file
  uploads at the review endpoint). This extension owns one row per photo in
  `ext_product_review_media` (`review_id`, `product_id`, `url`, `sort`,
  `status`), the per-photo moderation record scoped by `store_id` +
  `channel_id`.
- A photo renders in the PDP gallery only when its review is approved (core
  `ReviewService::published()`) AND its media row is not `rejected`. Media
  rows are created ("ensured") from the request scope of the gallery/admin
  call that first observes the photo: photos of approved reviews start
  `approved`, photos of pending reviews start `pending`. An admin can reject
  a single photo to hide it without rejecting the whole review.
- Moderation:
  - `PUT /api/ext/siro/product-photo-reviews/admin/media/{id}` with
    `{"status": "approved"|"rejected", "moderation_note": "..."}` moderates
    one photo. Repeating the same update is idempotent.
  - `PUT /api/ext/siro/product-photo-reviews/admin/reviews/{id}` with
    `{"status": "approved"|"rejected"|"pending"}` moderates the review
    through core `ReviewService::moderate()` — approving emits core event
    `review.approved`, after which the review's photos become visible.
- Public gallery (anonymous):
  - `GET /api/ext/siro/product-photo-reviews/public?product_id=...&limit=24`
    returns approved photos for that product, newest review first.
- Admin routes (manifest permission `photo-reviews:moderate`):
  - `GET /api/ext/siro/product-photo-reviews/admin?state=pending|approved|rejected|all`
    (optional `product_id`) lists the photo moderation queue.
  - `GET /api/ext/siro/product-photo-reviews/admin/overview` returns
    `{stats: [...], table: {columns, rows}}` for the admin panels.

Every SELECT/UPDATE filters scope. Writes are merged through
`ExtensionScope::merge()`, so a caller cannot write into or read from another
store/channel.

## Why there is no event listener

Core `product_reviews` carries no store/channel, so the `review.submitted` /
`review.approved` payloads carry no scope. A listener that creates media rows
would have to write rows without a scope or query `ext_*` by `review_id`
alone — both violate `EXTENSION_SCOPING.md` §7. The events are therefore used
through their effects: `moderate()` emits `review.approved`, and the gallery
reads `published()` (approved reviews only). A test asserts the event fires
when a review is approved through this extension's admin route.

## Input rules

- `product_id`: required, max 64 characters.
- `status` (photo): `approved` or `rejected`.
- `status` (review): `approved`, `rejected` or `pending`.
- `moderation_note`: max 500 characters.
- No HTML, control characters or `javascript:` payloads are accepted. The
  storefront renders photo URLs as `<img src>` with an `alt` derived from
  the review title, never via `dangerouslySetInnerHTML`.

## Install / enable

```bash
php siro extension:check
php siro extension:pack app/Extensions/Siro/ProductPhotoReviews --out=dist/siro-product-photo-reviews-1.0.0.zip
php siro extension:verify dist/siro-product-photo-reviews-1.0.0.zip
php siro extension:qit dist/siro-product-photo-reviews-1.0.0.zip
# Admin -> Extensions -> siro/product-photo-reviews -> Install -> Enable
# (Enable runs migrations/20261126000031_create_ext_product_review_media.php)
```

Disable unmounts all routes and keeps data. Uninstall with "keep data" then
reinstall reuses the moderation rows; uninstall with "remove data" runs
`down()` and drops `ext_product_review_media`; core tables are never touched.

## Storefront

Storefront plugin: `apps/storefront/src/plugins/product-photo-reviews/`.
`register.ts` registers slot `pdp.below-gallery` with id
`siro/product-photo-reviews` and priority 100. The PDP passes
`{ productId, productSlug, category }` via the existing `Slot` data prop.
The component fetches approved photos, renders a lazy-loaded grid with a
keyboard-navigable lightbox (arrow keys, Escape, focus return), and — for
signed-in shoppers — an uploader that posts files to the existing
`POST /api/upload` endpoint, then submits the review with the returned URLs
via `POST /api/products/{id}/reviews` (max 5 photos). Empty and unavailable
states render nothing so the PDP is unchanged when a product has no photos.

## Config

| Key | Default | Meaning |
| --- | --- | --- |
| `max_images_per_review` | 5 | Documentation value; core `ReviewService` enforces it. |
| `max_gallery_items` | 24 | Max photos returned by the public gallery. |

No secrets, mailers or external providers. Admins moderate inside the
extension admin panel/API.
