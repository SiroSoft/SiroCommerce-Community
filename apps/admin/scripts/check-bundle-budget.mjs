// Bundle budget gate (no extra deps): totals client JS gzip in .next/static
// and fails when the total or the largest chunk exceeds budget.
// Run after `next build`. Usage: node scripts/check-bundle-budget.mjs
// Env: BUNDLE_TOTAL_KB (default 950), BUNDLE_CHUNK_KB (default 150).
import { readdirSync, readFileSync, existsSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { gzipSync } from "node:zlib";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const chunksDir = join(root, ".next", "static", "chunks");
const argv = process.argv.slice(2);
const argOf = (name, fallback) => {
  const i = argv.indexOf(name);
  const v = i >= 0 ? Number(argv[i + 1]) : NaN;
  return Number.isFinite(v) && v > 0 ? v : fallback;
};
const totalBudgetKb = argOf("--total-kb", Number(process.env.BUNDLE_TOTAL_KB || 950));
const chunkBudgetKb = argOf("--chunk-kb", Number(process.env.BUNDLE_CHUNK_KB || 150));

if (!existsSync(chunksDir)) {
  console.error("No .next/static/chunks — run `next build` first.");
  process.exit(2);
}

let total = 0;
let count = 0;
let max = 0;
let maxName = "";
for (const file of readdirSync(chunksDir)) {
  if (!file.endsWith(".js")) continue;
  const gz = gzipSync(readFileSync(join(chunksDir, file))).length;
  total += gz;
  count++;
  if (gz > max) {
    max = gz;
    maxName = file;
  }
}

const totalKb = total / 1024;
const maxKb = max / 1024;
console.log(`chunks: ${count}, total gzip: ${totalKb.toFixed(1)}KB (budget ${totalBudgetKb}KB), max chunk: ${maxKb.toFixed(1)}KB ${maxName} (budget ${chunkBudgetKb}KB)`);
let failed = false;
if (totalKb > totalBudgetKb) {
  console.error(`FAIL total client JS exceeds budget`);
  failed = true;
}
if (maxKb > chunkBudgetKb) {
  console.error(`FAIL largest chunk exceeds budget`);
  failed = true;
}
console.log(failed ? "BUNDLE BUDGET: FAIL" : "BUNDLE BUDGET: PASS");
process.exit(failed ? 1 : 0);
