#!/usr/bin/env node

/**
 * Rasterize catalog SVG illustrations into WebP (and AVIF when sharp supports
 * it). The SVG originals are kept; the raster renditions exist so the same
 * media can be used by Open Graph cards, CDNs, and the Next image optimizer.
 *
 * Sources: apps/storefront/public/catalog/b2c/*.svg
 * Outputs: <name>.webp (quality 82) and <name>.avif (quality 60)
 *
 * The script is idempotent: an output newer than its SVG is skipped unless
 * `--force` is passed.
 */

import { existsSync, readdirSync, statSync, unlinkSync } from "node:fs";
import { basename, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(fileURLToPath(new URL("..", import.meta.url)));
const defaultDir = join(repoRoot, "apps", "storefront", "public", "catalog", "b2c");

const WIDTH = 900;
const HEIGHT = 1100;
const WEBP_QUALITY = 82;
const AVIF_QUALITY = 60;

function parseArgs(argv) {
  const options = { dir: defaultDir, force: false, avif: true };
  for (const arg of argv) {
    if (arg === "--force") options.force = true;
    else if (arg === "--no-avif") options.avif = false;
    else if (arg.startsWith("--dir=")) options.dir = resolve(arg.slice("--dir=".length));
  }
  return options;
}

async function loadSharp() {
  try {
    const module = await import("sharp");
    return module.default ?? module;
  } catch {
    return null;
  }
}

function avifSupported(sharp) {
  const heif = sharp.format?.heif;
  const alias = heif?.output?.alias ?? [];
  return Boolean(heif?.output?.file) && alias.includes("avif");
}

function mtimeMs(path) {
  try {
    return statSync(path).mtimeMs;
  } catch {
    return null;
  }
}

function removeIfPresent(path) {
  try {
    unlinkSync(path);
  } catch {
    // A partial output is harmless to leave behind only if it cannot be read;
    // sharp writes atomically enough that this is best-effort cleanup.
  }
}

async function render(sharp, svgPath, outputPath, format) {
  const pipeline = sharp(svgPath).resize(WIDTH, HEIGHT, {
    fit: "contain",
    // The SVG supplies its own background; transparent padding keeps any
    // non-900x1100 source from acquiring a black matte.
    background: { r: 0, g: 0, b: 0, alpha: 0 },
  });
  if (format === "webp") {
    await pipeline.webp({ quality: WEBP_QUALITY }).toFile(outputPath);
  } else {
    await pipeline.avif({ quality: AVIF_QUALITY }).toFile(outputPath);
  }
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  if (!existsSync(options.dir)) {
    console.error(`Image directory not found: ${options.dir}`);
    process.exitCode = 1;
    return;
  }

  const sharp = await loadSharp();
  if (!sharp) {
    console.error("sharp is not installed; rasterization skipped. Install sharp or run this step elsewhere.");
    process.exitCode = 1;
    return;
  }

  const svgFiles = readdirSync(options.dir)
    .filter((name) => name.toLowerCase().endsWith(".svg"))
    .sort();

  const summary = {
    directory: options.dir,
    scanned: svgFiles.length,
    webp_created: 0,
    webp_skipped: 0,
    avif_created: 0,
    avif_skipped: 0,
    avif_supported: options.avif && avifSupported(sharp),
    failed: 0,
    bytes_written: 0,
  };

  for (const file of svgFiles) {
    const svgPath = join(options.dir, file);
    const base = basename(file, file.slice(file.lastIndexOf(".")));
    const sourceMtime = mtimeMs(svgPath) ?? 0;

    const targets = [
      { format: "webp", suffix: ".webp", created: "webp_created", skipped: "webp_skipped" },
    ];
    if (options.avif) {
      targets.push({ format: "avif", suffix: ".avif", created: "avif_created", skipped: "avif_skipped" });
    }

    for (const target of targets) {
      if (target.format === "avif" && !summary.avif_supported) {
        summary.avif_skipped += 1;
        continue;
      }
      const outputPath = join(options.dir, base + target.suffix);
      const outputMtime = mtimeMs(outputPath);
      if (!options.force && outputMtime !== null && outputMtime >= sourceMtime) {
        summary[target.skipped] += 1;
        continue;
      }

      try {
        await render(sharp, svgPath, outputPath, target.format);
        summary[target.created] += 1;
        summary.bytes_written += statSync(outputPath).size;
      } catch (error) {
        removeIfPresent(outputPath);
        if (target.format === "avif") {
          summary.avif_supported = false;
          summary.avif_skipped += 1;
        } else {
          summary.failed += 1;
          console.error(`Failed ${file} -> ${target.suffix}: ${error instanceof Error ? error.message : String(error)}`);
        }
      }
    }
  }

  console.log(JSON.stringify(summary, null, 2));
  if (summary.scanned === 0 || summary.failed > 0) process.exitCode = 1;
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack ?? error.message : String(error));
  process.exitCode = 1;
});
