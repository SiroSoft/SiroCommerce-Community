# Media Derivatives Spec (free tier)

Server has GD + WebP (verified). Goal: operators upload real photos once and
the platform serves responsive WebP without an external CDN.

## Upload pipeline

When a product/media image is stored (`product_images` or media library upload):
- Accept jpg/png/webp (existing upload whitelist unchanged).
- Generate derivatives with GD: widths **320, 640, 960** (height auto, max
  2000px), WebP q82, plus a 32px LQIP WebP (used as blur placeholder).
- Store derivative paths in `product_images.variants_json` (array of
  `{w, url, format}`) and `placeholder` (data URI or path). File names
  `<sha>_<w>.webp` beside the original under storage.
- Idempotent: re-uploading the same file hash reuses derivatives.
- Failure to process never loses the original upload; `variants_json` stays
  null and the storefront falls back to the original URL.

## API / contract

`ProductResource` images keep `url` (original) and add `variants` + `placeholder`
when present. Existing clients ignore unknown fields.

## Storefront

`srcSetFor` prefers `variants` when provided (no `/_next/image` needed), with
`sizes` unchanged; SVG/raster without variants keeps current behaviour. LQIP is
used as `background-image` + low-quality placeholder while loading (no CLS).

## Admin

Media library and product gallery show “N derivatives” and a preview of the
320px variant; no re-process button required in v1 (re-upload replaces).

## Tests

GD derivative sizes/formats (fixture PNG), idempotent by hash, invalid image
keeps original, resource shape, storefront srcset from variants, fallback when
variants missing.

## Non-goals

AVIF generation, background removal, external CDN/object storage.
