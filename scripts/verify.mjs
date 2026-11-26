import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';

const root = process.cwd();
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const strict = process.env.VERIFY_STRICT === '1' || process.argv.includes('--strict');
const requestedTimeoutMs = Number(process.env.VERIFY_TIMEOUT_MS || 120_000);
const timeoutMs = Number.isFinite(requestedTimeoutMs)
  ? Math.min(Math.max(requestedTimeoutMs, 1_000), 120_000)
  : 120_000;
const failures = [];
const blocked = [];
const warnings = [];
const results = [];

function record(status, label, detail = '') {
  results.push({ status, label, ...(detail ? { detail } : {}) });
  console.log(`VERIFY ${status} ${label}${detail ? `: ${detail}` : ''}`);
  if (status === 'FAIL') failures.push(`${label}${detail ? `: ${detail}` : ''}`);
  if (status === 'BLOCKED') blocked.push(`${label}${detail ? `: ${detail}` : ''}`);
}

function cleanup(pid) {
  if (!pid || process.platform !== 'win32') return true;
  try {
    execFileSync('taskkill', ['/PID', String(pid), '/T', '/F'], { stdio: 'ignore' });
  } catch {
    // The process may already have exited; the liveness check below is authoritative.
  }
  try {
    const output = execFileSync('tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'], { encoding: 'utf8' });
    return !output.trim() || output.includes('INFO: No tasks');
  } catch {
    return true;
  }
}

function run(label, command, args, cwd = root, env = process.env) {
  const result = spawnSync(command, args, {
    cwd,
    env,
    stdio: 'inherit',
    shell: process.platform === 'win32' && command === npm,
    timeout: timeoutMs,
    killSignal: 'SIGTERM',
    windowsHide: true,
  });
  if (result.error?.code === 'ETIMEDOUT' || result.signal) {
    const cleaned = cleanup(result.pid);
    record('FAIL', label, `timeout after ${timeoutMs}ms; process cleanup ${cleaned ? 'PASS' : 'FAIL'}`);
    return false;
  }
  if (result.error?.code === 'ENOENT') {
    record('BLOCKED', label, `executable not found: ${command}`);
    return false;
  }
  if (result.status !== 0) {
    record('FAIL', label, `exit ${result.status ?? 'unknown'}; process cleanup ${cleanup(result.pid) ? 'PASS' : 'FAIL'}`);
    return false;
  }
  const cleaned = cleanup(result.pid);
  record(cleaned ? 'PASS' : 'FAIL', label, `bounded ${timeoutMs}ms; process cleanup ${cleaned ? 'PASS' : 'FAIL'}`);
  return cleaned;
}

function runLocalE2E(label, workspace) {
  const cli = join(root, 'node_modules', '@playwright', 'test', 'cli.js');
  if (!existsSync(cli)) {
    record('BLOCKED', label, 'Playwright CLI is missing');
    return false;
  }
  return run(label, process.execPath, [
    cli,
    'test',
    '--config=playwright.config.ts',
    '--workers=1',
    '--grep-invert',
    'BLOCKED_EXTERNAL',
  ], join(root, workspace), { ...process.env, PLAYWRIGHT_RELEASE: '1' });
}

function sourceFiles(directory) {
  if (!existsSync(directory)) return [];
  const files = [];
  for (const entry of readdirSync(directory, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    if (entry.name === 'node_modules' || entry.name.startsWith('.next')) continue;
    const path = join(directory, entry.name);
    if (entry.isDirectory()) files.push(...sourceFiles(path));
    else if (/\.(ts|tsx)$/.test(entry.name)) files.push(path);
  }
  return files;
}

function checkLegacyManifest() {
  const path = join(root, 'scripts', 'verify-legacy-transport.json');
  if (!existsSync(path)) return record('BLOCKED', 'legacy transport manifest', 'manifest is missing');
  let manifest;
  try { manifest = JSON.parse(readFileSync(path, 'utf8')); } catch { return record('FAIL', 'legacy transport manifest', 'invalid JSON'); }
  const duplicates = manifest.filter((value, index) => manifest.indexOf(value) !== index);
  if (duplicates.length) record('FAIL', 'legacy transport manifest', `duplicate entries: ${duplicates.join(', ')}`);
  const missing = manifest.filter(value => !existsSync(join(root, value)));
  if (missing.length) record('FAIL', 'legacy transport manifest', `missing entries: ${missing.join(', ')}`);
  return manifest;
}

function checkGoldenEvidence() {
  const base = join(root, 'backend', 'storage', 'evidence', 'golden-journey');
  if (!existsSync(base)) return record('BLOCKED', 'Golden Journey evidence', 'evidence directory is missing');
  const runs = readdirSync(base, { withFileTypes: true })
    .filter(entry => entry.isDirectory() && /^\d{8}T\d{6}Z-p\d+$/.test(entry.name))
    .map(entry => entry.name).sort();
  if (!runs.length) return record('BLOCKED', 'Golden Journey evidence', 'no deterministic run directory found');
  const latest = join(base, runs[runs.length - 1], 'evidence.md');
  if (!existsSync(latest)) return record('FAIL', 'Golden Journey evidence', 'latest run has no evidence.md');
  const evidence = readFileSync(latest, 'utf8');
  if (!/- Status: \*\*PASS\*\*/.test(evidence) || !/Cleanup: SQLite database removed = `true`/.test(evidence)) {
    return record('FAIL', 'Golden Journey evidence', `latest run ${runs[runs.length - 1]} is not PASS/clean`);
  }
  record('PASS', 'Golden Journey evidence', `latest deterministic run ${runs[runs.length - 1]}`);
}

const manifest = checkLegacyManifest();
for (const workspace of ['apps/admin', 'apps/storefront', 'packages/api-client', 'packages/auth', 'packages/scope', 'packages/ui', 'packages/config']) {
  if (!existsSync(join(root, workspace))) record('BLOCKED', `workspace ${workspace}`, 'directory is missing');
}

if (strict) run('npm test (all configured workspaces)', npm, ['test']);
for (const workspace of ['apps/admin', 'apps/storefront']) {
  run(`${workspace} typecheck`, npm, ['run', 'typecheck', '--workspace', workspace]);
  if (!strict) run(`${workspace} tests`, npm, ['run', 'test', '--workspace', workspace]);
  if (strict) run(`${workspace} build`, npm, ['run', 'build', '--workspace', workspace]);
}
run('storefront i18n parity', npm, ['run', 'i18n:check', '--workspace', 'apps/storefront']);
runLocalE2E('Admin local E2E', 'apps/admin');
runLocalE2E('Storefront local E2E', 'apps/storefront');

const sdkBackend = join(root, 'backend', 'generated', 'sirocommerce.ts');
const sdkClient = join(root, 'packages', 'api-client', 'src', 'generated.ts');
if (existsSync(sdkBackend) && existsSync(sdkClient)) run('generated SDK contract parity', process.execPath, ['scripts/verify-sdk-contract.mjs']);
else record('BLOCKED', 'generated SDK contract parity', 'generated backend/client artifact is missing');

const backend = join(root, 'backend');
if (existsSync(join(backend, 'scripts', 'check-api-contract.php')) && existsSync(join(backend, 'scripts', 'check-api-handoff.php'))) {
  run('OpenAPI contract', 'php', ['scripts/check-api-contract.php'], backend);
  run('API handoff', 'php', ['scripts/check-api-handoff.php'], backend);
} else record('BLOCKED', 'OpenAPI/API handoff', 'backend gate scripts are missing');

if (strict && existsSync(join(backend, 'scripts', 'check-production-proof.php'))) {
  run('production proof contract', 'php', ['scripts/check-production-proof.php'], backend);
} else if (strict) record('BLOCKED', 'production proof contract', 'backend gate script is missing');

if (manifest) {
  const directTransport = sourceFiles(join(root, 'apps')).flatMap(path => {
    if (relative(root, path).replaceAll('\\', '/') === 'apps/admin/src/services/api.ts') return [];
    return /\baxios\b|\bfetch\s*\(/.test(readFileSync(path, 'utf8'))
      ? [relative(root, path).replaceAll('\\', '/')]
      : [];
  });
  const newLegacy = directTransport.filter(path => !manifest.includes(path));
  const removedLegacy = manifest.filter(path => !directTransport.includes(path));
  if (newLegacy.length) record('FAIL', 'no-new-legacy transport', newLegacy.join(', '));
  else record('PASS', 'no-new-legacy transport', 'manifest boundary is unchanged');
  if (directTransport.length) warnings.push(`legacy direct transport: ${directTransport.join(', ')}`);
  if (removedLegacy.length) console.log(`VERIFY INFO legacy transport removed: ${removedLegacy.join(', ')}`);
}

checkGoldenEvidence();
if (warnings.length) console.warn(`VERIFY WARN ${warnings.join('; ')}`);
const summary = { classification: failures.length ? 'FAIL' : blocked.length ? 'BLOCKED' : 'PASS', failures, blocked, warnings, results };
console.log(`\nVERIFY SUMMARY ${JSON.stringify(summary)}`);
if (failures.length || blocked.length) process.exit(1);
