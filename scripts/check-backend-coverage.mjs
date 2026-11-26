#!/usr/bin/env node
/**
 * Parse PHPUnit `--coverage-text` output and fail when the measured coverage is
 * below the configured floor. This keeps the backend gate driven by real
 * coverage rather than a hand-written number.
 *
 * Usage:
 *   php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text > coverage.txt
 *   node scripts/check-backend-coverage.mjs coverage.txt --min 80
 *   cat coverage.txt | node scripts/check-backend-coverage.mjs - --min 80
 *
 * Options:
 *   --min <number>     minimum accepted percentage (default: 80)
 *   --metric <name>    lines | methods | classes (default: lines)
 *   --allow-missing    exit 0 with a warning when no Summary block is found
 */
import { readFileSync } from "node:fs";

const argv = process.argv.slice(2);
const options = { file: "-", min: 80, metric: "lines", allowMissing: false };

for (let index = 0; index < argv.length; index += 1) {
  const arg = argv[index];
  if (arg === "--min") options.min = Number(argv[++index]);
  else if (arg === "--metric") options.metric = String(argv[++index]).toLowerCase();
  else if (arg === "--allow-missing") options.allowMissing = true;
  else if (!arg.startsWith("-") || arg === "-") options.file = arg;
  else {
    console.error(`Unknown option: ${arg}`);
    process.exit(2);
  }
}

if (!["lines", "methods", "classes"].includes(options.metric)) {
  console.error(`Unsupported metric "${options.metric}". Use lines, methods, or classes.`);
  process.exit(2);
}
if (!Number.isFinite(options.min) || options.min < 0 || options.min > 100) {
  console.error(`Invalid --min value "${options.min}".`);
  process.exit(2);
}

function readInput(file) {
  if (file && file !== "-") {
    try {
      return readFileSync(file, "utf8");
    } catch (error) {
      console.error(`Cannot read coverage output from ${file}: ${error.message}`);
      process.exit(2);
    }
  }
  try {
    return readFileSync(0, "utf8");
  } catch (error) {
    console.error(`Cannot read coverage output from stdin: ${error.message}`);
    process.exit(2);
  }
}

const raw = readInput(options.file);
const summaryIndex = raw.lastIndexOf("Summary:");
if (summaryIndex === -1) {
  const message = "No PHPUnit coverage Summary block found in the output.";
  if (options.allowMissing) {
    console.warn(`WARNING: ${message} Gate is informational for this run.`);
    process.exit(0);
  }
  console.error(`ERROR: ${message} Run PHPUnit with --coverage-text and PCOV enabled.`);
  process.exit(1);
}

const summary = raw.slice(summaryIndex);
const labels = { lines: "Lines", methods: "Methods", classes: "Classes" };
const label = labels[options.metric];
const match = new RegExp(`^\\s*${label}:\\s*([\\d.]+)%\\s*\\(\\s*(\\d+)\\s*/\\s*(\\d+)\\s*\\)`, "m").exec(summary);

if (!match) {
  const message = `Coverage Summary does not contain the ${label} metric.`;
  if (options.allowMissing) {
    console.warn(`WARNING: ${message} Gate is informational for this run.`);
    process.exit(0);
  }
  console.error(`ERROR: ${message}`);
  process.exit(1);
}

const measured = Number(match[1]);
const covered = Number(match[2]);
const total = Number(match[3]);
const status = measured >= options.min ? "PASS" : "FAIL";

console.log(`Backend ${options.metric} coverage: ${measured.toFixed(2)}% (${covered}/${total})`);
console.log(`Backend coverage gate: ${status} (minimum ${options.min.toFixed(2)}%)`);

process.exit(measured >= options.min ? 0 : 1);
