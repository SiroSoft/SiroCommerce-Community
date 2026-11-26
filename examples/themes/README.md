# Example Siro theme packages

Three ready-to-import `siro.theme/1` packages for trying the SiroCommerce theme
pipeline. They are presentation-only samples: no executable code, no store
scope, no secrets, and no real brand or testimonial content.

| Theme | Path | Vertical | Edition | Style |
| --- | --- | --- | --- | --- |
| `siro/fashion` | `examples/themes/fashion/theme.json` | `fashion` | Free | Editorial serif, sharp corners, lookbook-led |
| `siro/pharmacy` | `examples/themes/pharmacy/theme.json` | `pharmacy` | Free | Clean, trust-first, soft corners |
| `siro/fnb` | `examples/themes/fnb/theme.json` | `fnb` | Free | Warm, friendly, round corners |

Pro themes (`Atelier Noir`, `Apothecary Calm`) live in the private Pro repository.

Each package extends `general`, ships full `tokens` (colors, typography, shape),
`brand`, `navigation`, `header`, `footer`, `homepage.sections`, `presentation`,
`purchase`, `policies`, and references only root-relative or local `assets/`
paths.

## Bundled assets

Every sample ships real product/lifestyle photography under `assets/`. All files
are optimized JPEG/PNG with no real brands or text.

| Theme | Assets |
| --- | --- |
| `fashion` | `logo.png`, `hero.jpg`, `banner-1.jpg`, `banner-2.jpg` |
| `pharmacy` | `logo.png`, `hero.jpg` |
| `fnb` | `logo.png`, `hero.jpg`, `banner-1.jpg`, `banner-2.jpg` |

`theme.json` points at these files with **relative** paths (`assets/logo.png`,
`assets/hero.jpg`) in `brand.logo_url`, `campaign_hero` `image`, and
`banner_grid` banner `image`. Never use storage/CDN URLs here: the archive
importer stores each file under `/storage/themes/<key>/` and rewrites every
`assets/...` reference to a public URL **before** the config is validated. The
samples therefore must be imported as a `.zip`, not as a bare `theme.json`.

## Package a theme as a zip

Run from the repository root (PowerShell):

```powershell
$theme = "fashion"   # or "pharmacy", "fnb"
Push-Location "examples/themes/$theme"
Compress-Archive -Path theme.json, assets -DestinationPath "$env:TEMP/$theme.zip" -Force
Pop-Location
```

The archive must contain `theme.json` at its root plus an `assets/` folder.
Only PNG/JPG/JPEG/WEBP/AVIF images and font files are accepted; SVG, JS, PHP and
HTML are rejected.

## Import via Admin Theme Builder

1. Sign in to the SiroCommerce Admin and open **Storefront → Theme**.
2. Choose **Import Siro theme** and select the `.zip` created above.
3. The importer stores the bundled images, rewrites `assets/...` references to
   public URLs, and loads the package as a **draft**. Review the preview, edit if
   needed, then **Save** or **Publish**. Importing never publishes automatically
   and the file's `scope` (if present) is ignored in favour of the active
   store/channel.

A bare `theme.json` without `assets/` is still accepted only if it has no local
asset references. The archive importer validates the package with the same rules
as the Theme Builder and returns `422` for invalid `schema`, `id`, `key`,
`version`, section types, token values, unsafe links, or unsupported assets.

## Validate locally

From the repository root:

```bash
# Parse and map each package into storefront config input (no DB writes)
php -r '
require "backend/vendor/autoload.php";
use App\Modules\StorefrontConfig\Domain\ThemePackage;
foreach (glob("examples/themes/*/theme.json") as $file) {
    $package = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    ThemePackage::input($package);
    echo "ok ", $file, PHP_EOL;
}
'
```

From `backend/`:

```bash
php -l tests/Unit/ExampleThemesTest.php
php vendor/bin/phpunit --no-coverage tests/Unit/ExampleThemesTest.php
```

The unit test globs `examples/themes/*/theme.json`, decodes JSON, calls
`ThemePackage::input`, asserts the id, key, vertical and homepage sections are
valid, and verifies that every `assets/...` reference exists, stays inside the
theme folder (no `..`), uses a png/jpg/jpeg/webp extension, and is a real image
under 300 KB. Each theme must reference a `logo.png` and a `campaign_hero`
`image`.

## Editing the samples

Keep the contract from
[`docs/contracts/THEME_PACKAGE_SPEC.md`](../../docs/contracts/THEME_PACKAGE_SPEC.md):
`schema` is `siro.theme/1`; `id` is `vendor/key`; `key` is 3-40 lowercase
characters; `version` is semver; at least one homepage section is required.
Section types must be one of `campaign_hero`, `collection_directory`,
`editorial_story`, `product_collection`, `service_proof`, `trust_strip`,
`category_links`, `banner_grid`, `usp_strip`, `faq`.
