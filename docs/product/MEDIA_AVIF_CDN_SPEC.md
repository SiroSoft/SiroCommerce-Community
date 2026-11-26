# Media AVIF + CDN Spec

Build on the GD derivative pipeline (WebP 320/640/960 + LQIP). GD reports AVIF
support (verified).

## Derivatives

- Add AVIF 320/640/960 (q60) beside WebP; `variants_json` entries gain
  `format: avif|webp`. Keep WebP as universal fallback.
- Optional `MEDIA_CDN_URL` (https): when set, derivative/original URLs are
  rewritten to `{CDN}/storage/...` (path only, no secrets). Unset = current
  same-origin URLs.
- Derivatives are immutable by content hash → responses may use
  `Cache-Control: public, max-age=31536000, immutable` when served through the
  app; Nginx gets `expires 1y` for `/catalog` and `/storage` static paths.

## Storefront

- ProductCard/ProductGallery render `<picture>`: `<source type="image/avif"
  srcset=...>` + `<source type="image/webp" srcset=...>` + `<img>` fallback to
  the original. LQIP stays as background.
- `srcSetFor` returns `{sources, src, sizes}`; no `/_next/image` when variants
  exist.

## Admin

Media library shows formats (WebP/AVIF) and sizes per derivative.

## Tests

AVIF files produced (when IMG_AVIF), variants list has both formats, srcset
groups by format, picture renders source order, CDN rewrite (set/unset), cache
headers helper, fallback for old images.

## Non-goals

Image background removal, on-the-fly transforms, object-storage migration.
