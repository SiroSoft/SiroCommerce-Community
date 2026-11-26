# Media pipeline

Product media is served from `apps/storefront/public/catalog/b2c/` and recorded
per product in `product_images`. The storefront gallery and the card hover frame
read `images[0]` and `images[1]` from the product API, so every active product
should expose at least a front frame and a visibly different second frame.

## Naming and conventions

- `public/catalog/b2c/<slug>-front.svg` is the primary card/product frame.
- `public/catalog/b2c/<slug>-alt.svg` is the generated alternate frame used by
  hover and gallery views.
- `-angle.svg` and `-detail.svg` are optional extra frames.
- SVG canvas is `900 x 1100` (`viewBox="0 0 900 1100"`), no embedded raster
  images, no external fonts, and no text except a small `DETAIL` label in the
  generated alternate frame.
- The alternate frame keeps the original product paths but changes the neutral
  background palette, adds a frame/shadow/vignette, shifts the composition, and
  zooms slightly. It must stay clean and recognizable as the same product.

## Raster renditions (WebP + AVIF)

The SVG originals stay in `public/catalog/b2c/`, but every frame also has a
raster rendition so the media works with Open Graph cards, CDNs, and the Next
image optimizer:

- `<slug>-<frame>.webp` — 900x1100, WebP quality 82.
- `<slug>-<frame>.avif` — 900x1100, AVIF quality 60 (written only when the
  installed `sharp` build supports AVIF).

```bash
node scripts/rasterize-images.mjs            # idempotent; skips outputs newer than the SVG
node scripts/rasterize-images.mjs --force    # rebuild every rendition
node scripts/rasterize-images.mjs --no-avif  # WebP only
```

The script uses `sharp` (already present through `next`); no extra dependency is
added. It never deletes SVG sources and prints a JSON summary
(`scanned`, `webp_created`, `webp_skipped`, `avif_created`, `avif_skipped`,
`avif_supported`, `failed`, `bytes_written`). If `sharp` is unavailable the
script exits non-zero without touching the tree.

## Responsive markup

`apps/storefront/src/lib/product-image.ts` keeps SVGs unchanged (they are
resolution independent). Local raster assets (`.webp`, `.avif`, `.jpg`, `.png`
under `/catalog/b2c/...` with no query string) are emitted as
`/_next/image?url=<encoded>&w=<width>&q=75` candidates for the widths requested
by the caller (cards use `320, 480, 640, 800`). The widths must be allow-listed
in `apps/storefront/next.config.ts` under `images.deviceSizes` /
`images.imageSizes`; a unit test keeps the two in sync.

## Generating alternate frames

```bash
node scripts/generate-alt-images.mjs
node scripts/generate-alt-images.mjs --force   # explicitly rebuild existing -alt.svg files
```

The script reads every `*-front.svg`, writes a matching `*-alt.svg`, and never
overwrites an existing alternate file unless `--force` is passed. The output is
deterministic for a given slug and source file.

## Attaching frames to the catalog

After the files exist in the storefront public tree, attach them to active
products:

```bash
php siro catalog:attach-alt-images --dry-run
php siro catalog:attach-alt-images
```

The command is idempotent. For each active product whose image URL is
`/catalog/b2c/<slug>-front.svg`, it inserts one row with URL
`/catalog/b2c/<slug>-alt.svg`, alt text `<product name> alternate view`, and
`sort = max(sort) + 1`. It only inserts when the file exists under the
storefront public directory, rejects unsafe or traversal-like URLs, and prints a
summary (`scanned`, `inserted`, `would_insert`, `existing`, `missing_file`,
`unsafe_url`). `--dry-run` performs no writes.

Re-running `commerce:seed-b2c` also seeds the alternate frame (see
`B2cCatalogSeeder::imageAngles()`), so a fresh catalog keeps the full media set.

## Repointing product images at WebP

Once the raster renditions exist, update `product_images.url` in place:

```bash
php siro catalog:attach-webp-images --dry-run
php siro catalog:attach-webp-images
```

The command rewrites only rows whose URL is a safe `/catalog/b2c/<slug>.svg`
path and whose `<slug>.webp` rendition exists under the storefront public
directory (`--force` skips the file check and trusts the strict URL pattern). It
preserves `alt`, `sort`, `type`, and `role`, sets `mime_type` to `image/webp`,
and leaves rows that already point at a raster rendition untouched. The summary
reports `scanned`, `updated`, `would_update`, `already_raster`, `missing_file`,
`unsafe_url`, and `skipped`; a second run therefore updates nothing. Take a
database backup before running it against production, as with any in-place URL
change.

## Copyright and quality

Do not add copyrighted product photography or scraped marketplace images. The
committed media is original SVG illustration; if real photography is introduced
later, it must carry a documented license and usable web renditions. Keep files
optimized, avoid embedded metadata, and do not use stock images whose license
does not cover commercial storefront use.

## Verification

```bash
node --check scripts/generate-alt-images.mjs
node --check scripts/rasterize-images.mjs
php -l backend/app/Console/Commands/AttachAltProductImagesCommand.php
php -l backend/app/Console/Commands/AttachWebpProductImagesCommand.php
php -l backend/app/Support/CatalogAltImageAttacher.php
php -l backend/app/Support/CatalogWebpImageAttacher.php
cd backend && php vendor/bin/phpunit --filter CatalogAltImageAttacherTest --no-coverage
cd backend && php vendor/bin/phpunit --filter CatalogWebpImageAttacherTest --no-coverage
```
