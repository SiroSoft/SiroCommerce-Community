#!/usr/bin/env node

import { readFileSync, readdirSync, writeFileSync, existsSync } from "node:fs";
import { basename, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(fileURLToPath(new URL("..", import.meta.url)));
const defaultDir = join(repoRoot, "apps", "storefront", "public", "catalog", "b2c");

const PALETTES = [
  { bg1: "#f4f1eb", bg2: "#e7e1d6", frame: "#b8ae9d", label: "#6b6255", shadow: "#3d372e" },
  { bg1: "#eef1f2", bg2: "#dde3e5", frame: "#9aa7ab", label: "#566266", shadow: "#2f393d" },
  { bg1: "#f3efe9", bg2: "#e3dbd0", frame: "#b0977f", label: "#6f5945", shadow: "#403226" },
  { bg1: "#eef0ea", bg2: "#dfe4da", frame: "#9ba795", label: "#55604c", shadow: "#313a2b" },
  { bg1: "#f2eeee", bg2: "#e4dcdc", frame: "#b09a9a", label: "#665354", shadow: "#3d2f30" },
];

function parseArgs(argv) {
  const options = { dir: defaultDir, force: false };
  for (const arg of argv) {
    if (arg === "--force") options.force = true;
    else if (arg.startsWith("--dir=")) options.dir = resolve(arg.slice("--dir=".length));
  }
  return options;
}

function escapeXml(value) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function paletteFor(slug) {
  let hash = 0;
  for (const character of slug) hash = (hash * 31 + character.codePointAt(0)) >>> 0;
  return PALETTES[hash % PALETTES.length];
}

function readViewBox(attrs) {
  const match = attrs.match(/\bviewBox="([^"]+)"/i);
  if (!match) return { x: 0, y: 0, width: 900, height: 1100 };
  const parts = match[1].trim().split(/[\s,]+/).map(Number);
  if (parts.length !== 4 || parts.some((value) => !Number.isFinite(value))) {
    return { x: 0, y: 0, width: 900, height: 1100 };
  }
  return { x: parts[0], y: parts[1], width: parts[2], height: parts[3] };
}

function stripFullCanvasBackground(inner, viewBox) {
  return inner.replace(/<rect\b[^>]*\/?>/gi, (element) => {
    const width = Number((element.match(/\bwidth="([\d.]+)"/i) || [])[1]);
    const height = Number((element.match(/\bheight="([\d.]+)"/i) || [])[1]);
    const x = Number((element.match(/\bx="([\d.]+)"/i) || [])[1] ?? "NaN");
    const y = Number((element.match(/\by="([\d.]+)"/i) || [])[1] ?? "NaN");
    const coversCanvas =
      Number.isFinite(width) && Number.isFinite(height) &&
      width >= viewBox.width * 0.95 && height >= viewBox.height * 0.95 &&
      (!Number.isFinite(x) || x <= viewBox.x) && (!Number.isFinite(y) || y <= viewBox.y);
    return coversCanvas ? "" : element;
  });
}

function buildAltSvg(source, slug) {
  const match = source.match(/<svg\b([^>]*)>([\s\S]*)<\/svg>/i);
  if (!match) throw new Error(`${slug}-front.svg is not a valid SVG document`);
  const viewBox = readViewBox(match[1]);
  if (viewBox.width <= 0 || viewBox.height <= 0) throw new Error(`${slug}-front.svg has an invalid viewBox`);

  const palette = paletteFor(slug);
  const content = stripFullCanvasBackground(match[2], viewBox).trim();
  if (content === "") throw new Error(`${slug}-front.svg has no drawable content`);
  const centerX = viewBox.x + viewBox.width / 2;
  const centerY = viewBox.y + viewBox.height / 2;
  const frameInset = Math.round(viewBox.width * 0.06);
  const labelY = viewBox.y + viewBox.height - Math.round(viewBox.height * 0.06);
  const label = escapeXml("DETAIL");
  const aria = escapeXml(`${slug} alternate view`);

  return [
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox.x} ${viewBox.y} ${viewBox.width} ${viewBox.height}" width="${viewBox.width}" height="${viewBox.height}" role="img" aria-label="${aria}">`,
    "<defs>",
    `<linearGradient id="alt-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${palette.bg1}"/><stop offset="1" stop-color="${palette.bg2}"/></linearGradient>`,
    `<radialGradient id="alt-vignette" cx="50%" cy="42%" r="72%"><stop offset=".62" stop-color="${palette.shadow}" stop-opacity="0"/><stop offset="1" stop-color="${palette.shadow}" stop-opacity=".16"/></radialGradient>`,
    `<filter id="alt-shadow" x="-25%" y="-25%" width="150%" height="150%"><feDropShadow dx="0" dy="${Math.round(viewBox.height * 0.016)}" stdDeviation="${Math.round(viewBox.width * 0.026)}" flood-color="${palette.shadow}" flood-opacity=".24"/></filter>`,
    "</defs>",
    `<rect x="${viewBox.x}" y="${viewBox.y}" width="${viewBox.width}" height="${viewBox.height}" fill="url(#alt-bg)"/>`,
    `<rect x="${viewBox.x + frameInset}" y="${viewBox.y + frameInset}" width="${viewBox.width - frameInset * 2}" height="${viewBox.height - frameInset * 2}" rx="42" fill="none" stroke="${palette.frame}" stroke-width="2" opacity=".55"/>`,
    `<ellipse cx="${centerX}" cy="${viewBox.y + viewBox.height * 0.82}" rx="${viewBox.width * 0.32}" ry="${viewBox.height * 0.032}" fill="${palette.shadow}" opacity=".1"/>`,
    `<g filter="url(#alt-shadow)" transform="translate(${centerX} ${centerY}) rotate(-2.5) scale(1.12) translate(${-centerX} ${-centerY})">`,
    content,
    "</g>",
    `<rect x="${viewBox.x}" y="${viewBox.y}" width="${viewBox.width}" height="${viewBox.height}" fill="url(#alt-vignette)"/>`,
    `<text x="${centerX}" y="${labelY}" text-anchor="middle" font-family="ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif" font-size="18" letter-spacing="6" fill="${palette.label}" opacity=".72">${label}</text>`,
    "</svg>",
    "",
  ].join("\n");
}

function main() {
  const options = parseArgs(process.argv.slice(2));
  if (!existsSync(options.dir)) {
    console.error(`Image directory not found: ${options.dir}`);
    process.exitCode = 1;
    return;
  }

  const fronts = readdirSync(options.dir)
    .filter((name) => name.endsWith("-front.svg"))
    .sort();
  const counts = { created: 0, skipped_existing: 0, skipped_invalid: 0, failed: 0 };

  for (const file of fronts) {
    const slug = basename(file, "-front.svg");
    const output = join(options.dir, `${slug}-alt.svg`);
    if (existsSync(output) && !options.force) {
      counts.skipped_existing += 1;
      continue;
    }
    try {
      const source = readFileSync(join(options.dir, file), "utf8");
      writeFileSync(output, buildAltSvg(source, slug), "utf8");
      counts.created += 1;
    } catch (error) {
      counts.skipped_invalid += 1;
      console.error(`Skipped ${file}: ${error instanceof Error ? error.message : String(error)}`);
    }
  }

  console.log(JSON.stringify({
    directory: options.dir,
    scanned: fronts.length,
    ...counts,
    failed: counts.failed,
  }, null, 2));
  if (counts.created === 0 && counts.skipped_existing === 0) process.exitCode = 1;
}

main();
