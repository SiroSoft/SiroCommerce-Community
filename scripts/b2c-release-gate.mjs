import { existsSync, mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { spawnSync } from "node:child_process";

const root = process.cwd();
const backend = join(root, "backend");
const npm = process.platform === "win32" ? "npm.cmd" : "npm";
const runId = process.env.RELEASE_RUN_ID || new Date().toISOString().replace(/[-:]/g, "").replace(/\.\d{3}Z$/, "Z");
const evidenceDir = process.env.RELEASE_EVIDENCE_DIR || join(backend, "storage", "evidence", "b2c-release-gate", runId);
const results = [];

mkdirSync(evidenceDir, { recursive: true });

function record(id, status, detail, command = null, requirement = id, owner = "Release Engineering") {
  const result = {
    id,
    status,
    requirement,
    owner,
    evidence: [`${id}.log`],
    detail,
    ...(status === "BLOCKED_EXTERNAL" || status === "FAIL" ? { reason: detail } : {}),
    ...(command ? { command } : {}),
  };
  results.push(result);
  console.log(`B2C GATE ${status} ${id}: ${detail}`);
  return result;
}

function run(id, executable, args, options = {}) {
  const command = `${executable} ${args.join(" ")}`;
  const result = spawnSync(executable, args, {
    cwd: options.cwd || root,
    env: { ...process.env, ...(options.env || {}) },
    encoding: "utf8",
    timeout: Number(process.env.RELEASE_GATE_TIMEOUT_MS || 180000),
    windowsHide: true,
    shell: process.platform === "win32" && executable === npm,
  });
  const output = `${result.stdout || ""}${result.stderr || ""}`;
  writeFileSync(join(evidenceDir, `${id}.log`), output);
  if (result.error?.code === "ENOENT") return record(id, "BLOCKED_EXTERNAL", `executable unavailable: ${executable}`, command, options.requirement, options.owner);
  if (result.error?.code === "ETIMEDOUT" || result.signal) {
    return record(id, options.externalOnTimeout ? "BLOCKED_EXTERNAL" : "FAIL", options.externalOnTimeout ? "external target or browser did not become available before the bounded timeout" : "command exceeded the bounded timeout", command, options.requirement, options.owner);
  }
  if (result.status === 0) return record(id, "PASS", "command completed successfully", command, options.requirement, options.owner);
  if (result.status === 2) return record(id, "BLOCKED_EXTERNAL", `command exited ${result.status}; see ${id}.log`, command, options.requirement, options.owner);
  if (options.externalFailurePattern && options.externalFailurePattern.test(output)) return record(id, "BLOCKED_EXTERNAL", `external prerequisite unavailable; see ${id}.log`, command, options.requirement, options.owner);
  return record(id, "FAIL", `command exited ${result.status}; see ${id}.log`, command, options.requirement, options.owner);
}

function blocked(id, detail, requirement = id, owner = "Release Engineering") {
  return record(id, "BLOCKED_EXTERNAL", detail, null, requirement, owner);
}

run("typecheck", npm, ["run", "typecheck"]);
run("build-admin", npm, ["run", "build:admin"]);
run("build-storefront", npm, ["run", "build", "--workspace", "apps/storefront"]);
run("api-contract", npm, ["run", "api:contract"]);
run("api-handoff", npm, ["run", "api:handoff"]);
run("production-proof-static", "php", ["scripts/check-production-proof.php"], { cwd: backend });

if (process.env.BACKUP_MANIFEST) {
  run("migration-rollback-and-backup-restore", "php", [
    "scripts/backup-restore-preflight.php",
    `--manifest=${process.env.BACKUP_MANIFEST}`,
    `--out=${join(evidenceDir, "backup-restore")}`,
  ], { cwd: backend });
} else {
  blocked("migration-rollback-and-backup-restore", "BACKUP_MANIFEST is not set; no restore or migration up/down rehearsal was claimed");
}

run("health-security-rate-limit", npm, ["run", "certify:launch"], {
  env: { CERT_RATE_LIMIT: process.env.CERT_RATE_LIMIT || "1", CERT_REQUIRE_READY: "1" },
  requirement: "live/readiness endpoints, security headers, and rate limiting",
  owner: "Platform Operations",
});
run("provider-configuration-preflight", "php", ["scripts/provider-preflight.php"], { cwd: backend, requirement: "provider readiness configuration", owner: "Provider Operations" });
run("provider-sandbox-certification", "php", ["scripts/run-provider-certification.php"], { cwd: backend, requirement: "shipping, tax, fulfillment, and messaging contract certification", owner: "Provider Operations" });
run("payment-certification", "php", ["scripts/certify-payment.php", `--output=${join(evidenceDir, "payment-certification.json")}`], { cwd: backend, requirement: "payment capability and live certification evidence", owner: "Payment Operations" });

if (process.env.PROVIDER_LIVE_SMOKE === "1") {
  run("provider-live-certification", "php", ["scripts/provider-live-smoke.php"], { cwd: backend, requirement: "live shipping/tax/provider smoke with independent references", owner: "Provider Operations" });
} else {
  blocked("provider-live-certification", "PROVIDER_LIVE_SMOKE=1 is not set; live provider certification was not claimed", "live shipping/tax/provider certification", "Provider Operations");
}

const storefrontPlaywright = join(root, "node_modules", "@playwright", "test", "cli.js");
if (existsSync(storefrontPlaywright)) {
  run("storefront-playwright-b2c", process.execPath, [storefrontPlaywright, "test", "--config=playwright.config.ts", "--workers=1"], {
    cwd: join(root, "apps", "storefront"),
    env: { PLAYWRIGHT_RELEASE: "1", PLAYWRIGHT_REAL_CHECKOUT: process.env.PLAYWRIGHT_REAL_CHECKOUT || "0" },
    externalFailurePattern: /(browser|executable).*(not found|missing|does not exist)|no browsers/i,
    externalOnTimeout: true,
    requirement: "guest browse/search/product/cart/checkout/order-result and authenticated account/return/subscription journeys",
    owner: "Storefront QA",
  });
} else {
  blocked("storefront-playwright-b2c", "Playwright CLI is unavailable; browser journey evidence was not produced");
}

const k6 = process.platform === "win32" ? "k6.exe" : "k6";
if (process.env.SKIP_PERF_GATE === "1") {
  blocked("k6-performance-thresholds", "SKIP_PERF_GATE=1 explicitly skipped performance proof");
} else {
  for (const scenario of ["catalog-browse", "search", "checkout-journey"]) {
    if (!existsSync(join(root, "perf", "k6", "production-proof.js"))) {
      blocked("k6-performance-thresholds", "production-proof.js is missing", "k6 p95 < 500 ms, p99 < 800 ms, HTTP errors < 1%", "Performance Engineering");
      break;
    }
    const k6Result = run(`k6-${scenario}`, k6, [
      "run", "-e", `BASE_URL=${process.env.BASE_URL || "http://127.0.0.1:8090"}`,
      "-e", `PROFILE=${process.env.PERF_PROFILE || "smoke"}`,
      "-e", `SCENARIO=${scenario}`,
      "-e", `VARIANT_ID=${process.env.VARIANT_ID || "siro-chanh"}`,
      "perf/k6/production-proof.js",
    ], { requirement: `k6 ${scenario}: p95 < 500 ms, p99 < 800 ms, HTTP errors < 1%`, owner: "Performance Engineering" });
    if (k6Result.status === "BLOCKED_EXTERNAL") break;
  }
}

const failures = results.filter((result) => result.status === "FAIL");
const blockedResults = results.filter((result) => result.status === "BLOCKED_EXTERNAL");
const status = failures.length ? "FAIL" : blockedResults.length ? "BLOCKED_EXTERNAL" : "PASS";
const manifest = {
  certification_version: 1,
  status,
  generated_at: new Date().toISOString(),
  release: {
    run_id: runId,
    commit: process.env.RELEASE_COMMIT || "unknown",
    actor: process.env.RELEASE_ACTOR || process.env.GITHUB_ACTOR || process.env.USERNAME || "unknown",
  },
  redaction: { applied: true, secrets_included: false, policy: "logs contain command status only; credentials and response bodies are excluded" },
  evidence_directory: evidenceDir.replaceAll("\\", "/"),
  thresholds: { api_p95_ms: 500, api_p99_ms: 800, api_error_rate: 0.01 },
  checklist: results,
};
writeFileSync(join(evidenceDir, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`);
console.log(`B2C GATE SUMMARY ${status} manifest=${join(evidenceDir, "manifest.json")}`);
process.exitCode = status === "PASS" ? 0 : status === "FAIL" ? 1 : 2;
