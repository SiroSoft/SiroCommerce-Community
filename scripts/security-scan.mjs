#!/usr/bin/env node
/**
 * Local security scan for committed secrets and PII-adjacent artifacts.
 *
 * Scope: the repository working tree minus dependencies, build output, test
 * artifacts and runtime storage. It does not contact any network service.
 *
 * Rules:
 *  - private-key     PEM private key blocks
 *  - provider-token  AWS access keys, Stripe live keys, GitHub PATs, Slack tokens
 *  - hardcoded-cred  password/secret/token/api-key literals (test fixtures and
 *                    placeholders are whitelisted)
 *  - tracked-env     .env files tracked by git (git ls-files)
 *  - root-login      root@<host> / ssh root@ references in docs and scripts
 *  - production-ip   non-loopback IPv4 literals in docs and scripts
 *
 * Exit code 1 when a critical/high finding is present, 0 otherwise.
 */
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, extname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const MAX_BYTES = 1024 * 1024;

const SKIP_DIRS = new Set([
  '.git',
  '.next',
  '.turbo',
  'build',
  'coverage',
  'dist',
  'node_modules',
  'playwright-report',
  'storage',
  'test-results',
  'vendor',
]);

const BINARY_EXTS = new Set([
  '.7z', '.avif', '.bmp', '.class', '.db', '.docx', '.eot', '.gif', '.gz',
  '.ico', '.jar', '.jpeg', '.jpg', '.lock', '.map', '.mp3', '.mp4', '.otf',
  '.pdf', '.png', '.sqlite', '.tar', '.tgz', '.ttf', '.webm', '.webp', '.woff',
  '.woff2', '.xlsx', '.zip',
]);

const TEST_PATH = /(^|\/)(__tests__|tests?|e2e|fixtures?|specs?)(\/|$)|\.(test|spec)\.[cm]?[jt]sx?$|fixture/i;
const LOCALE_PATH = /(^|\/)(locales?|i18n|translations?)(\/|$)/i;

const CREDENTIAL_KEY = /(SECRET|PASSWORD|PASSWD|TOKEN|API_?KEY|APIKEY|PRIVATE_?KEY|MASTER_?KEY|SIGNING_?KEY|CLIENT_?SECRET|ACCESS_?KEY|ACCESS_?TOKEN|REFRESH_?TOKEN|AUTH_?TOKEN|WEBHOOK_?SECRET|JWT_?SECRET)/i;
const NON_CREDENTIAL_ENV_SUFFIX = /_(URL|URI|NAME|FILE|PATH|DIR|BYTES|TOKENS|MODE|PROVIDER|TTL|TIMEOUT|RETRIES|COUNT|LIMIT|SECONDS|DAYS|MINOR|WEIGHT|PORT|HOST|USER|USERNAME|EMAIL)$/i;
const OBJECT_CREDENTIAL_KEY = /^(password|passwd|secret|client_secret|api_?key|apikey|access_?key|private_?key|access_?token|refresh_?token|auth_?token|webhook_?secret|stripe_?secret|jwt_?secret)$/i;
const STORAGE_KEY_VALUE = /^(?:[a-z][a-z0-9_.-]*)$/i;
const VARIABLE_VALUE = /^[$%]|[{}]/;

const PLACEHOLDER_VALUE = new RegExp(
  [
    '^$',
    '^(?:null|undefined|true|false|0|1)$',
    'change[-_ ]?me',
    'changeme',
    'placeholder',
    'example',
    'sample',
    'dummy',
    'fake',
    '^test(?:[-_].*)?$',
    '^secret$',
    '^token$',
    '^password$',
    '^whsec$',
    '^xxx+$',
    '^\\*+$',
    '^\\.{3,}$',
    '^<.*>$',
    '^\\$\\{.*\\}$',
    '^\\$\\(.*\\)$',
    '^%\\(.*\\)$',
    'ephemeral',
    '\\.test$',
    '@example\\.',
  ].join('|'),
  'i',
);

const findings = [];
const warnings = [];
const scanned = { files: 0, skipped: 0 };

function toPosix(value) {
  return value.split(sep).join('/');
}

function isTestPath(relPath) {
  return TEST_PATH.test(relPath);
}

function add(severity, rule, relPath, line, message, excerpt) {
  findings.push({ severity, rule, file: relPath, line, message, excerpt });
}

function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name)) continue;
      walk(full);
      continue;
    }
    if (!entry.isFile()) continue;
    const relPath = toPosix(relative(ROOT, full));
    if (BINARY_EXTS.has(extname(entry.name).toLowerCase())) {
      scanned.skipped++;
      continue;
    }
    let size = 0;
    try {
      size = statSync(full).size;
    } catch {
      scanned.skipped++;
      continue;
    }
    if (size === 0 || size > MAX_BYTES) {
      scanned.skipped++;
      continue;
    }
    let text = '';
    try {
      text = readFileSync(full, 'utf8');
    } catch {
      scanned.skipped++;
      continue;
    }
    if (text.includes('\u0000')) {
      scanned.skipped++;
      continue;
    }
    scanned.files++;
    scanText(relPath, text);
  }
}

function placeholder(value) {
  const normalized = value.trim().replace(/^(['"`])(.*)\1$/s, '$2');
  if (/^(?:<.*>|\$\{.*\}|\$\(.*\)|%\(.*\))$/s.test(normalized)) return true;
  return PLACEHOLDER_VALUE.test(normalized) || /(?:change[-_]?me|placeholder|example|dummy|fake|\.{3,})/i.test(normalized);
}

function looksLikeSecret(value) {
  const normalized = value.trim().replace(/^(['"`])(.*)\1$/s, '$2');
  if (normalized.length < 8 || placeholder(normalized)) return false;
  if (/\s/.test(normalized) || VARIABLE_VALUE.test(normalized)) return false;
  if (/^(?:sk_live_|whsec_|xox[baprs]-|ghp_|AKIA)/.test(normalized)) return true;
  if (!/\d/.test(normalized)) return false;
  return /[A-Za-z]/.test(normalized) || /[^A-Za-z0-9]/.test(normalized);
}

function scanText(relPath, text) {
  const lines = text.split(/\r?\n/);
  const isDocsOrScripts = /^(docs|scripts|deploy)\//.test(relPath);

  for (let index = 0; index < lines.length; index++) {
    const line = lines[index];
    const lineNo = index + 1;

    if (/-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----/.test(line)) {
      add('critical', 'private-key', relPath, lineNo, 'PEM private key block', line.trim());
      continue;
    }

    const providerPatterns = [
      [/\bAKIA[0-9A-Z]{16}\b/g, 'AWS access key id'],
      [/\bsk_live_[0-9A-Za-z]{16,}\b/g, 'Stripe live secret key'],
      [/\bghp_[A-Za-z0-9]{20,}\b/g, 'GitHub personal access token'],
      [/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/g, 'Slack token'],
    ];
    let providerHit = false;
    for (const [pattern, label] of providerPatterns) {
      for (const match of line.matchAll(pattern)) {
        if (placeholder(match[0])) continue;
        add('critical', 'provider-token', relPath, lineNo, `${label} pattern`, line.trim());
        providerHit = true;
      }
    }
    if (providerHit) continue;

    if (
      !isTestPath(relPath) &&
      !LOCALE_PATH.test(relPath) &&
      !/process\.env|getenv\(|Env::get|os\.environ|import\.meta\.env/.test(line)
    ) {
      const envStyle = /^([A-Z][A-Z0-9_]*)\s*=\s*(.+)$/.exec(line.trim());
      if (
        envStyle &&
        CREDENTIAL_KEY.test(envStyle[1]) &&
        !NON_CREDENTIAL_ENV_SUFFIX.test(envStyle[1])
      ) {
        const value = envStyle[2].trim().replace(/^(['"])(.*)\1$/s, '$2');
        if (looksLikeSecret(value)) {
          add('high', 'hardcoded-cred', relPath, lineNo, `Hardcoded credential in ${envStyle[1]}`, line.trim());
          continue;
        }
      }

      const objectStyle = /(?<![A-Za-z0-9_-])([A-Za-z_][A-Za-z0-9_-]*)\s*[:=]\s*(["'`])([^"'`\n]+)\2/g;
      for (const match of line.matchAll(objectStyle)) {
        const key = match[1];
        const value = match[3];
        if (!OBJECT_CREDENTIAL_KEY.test(key)) continue;
        if (STORAGE_KEY_VALUE.test(value) && /^(?:siro_|.*_token$|.*_key$)/i.test(value)) continue;
        if (looksLikeSecret(value)) {
          add('high', 'hardcoded-cred', relPath, lineNo, `Hardcoded credential for ${key}`, line.trim());
          break;
        }
      }
    }

    if (isDocsOrScripts) {
      const rootLogin = /\broot@((?:\d{1,3}\.){3}\d{1,3})\b/.exec(line) || (/\b(?:ssh|scp|rsync)\b[^\n]*\broot@([A-Za-z0-9_.:-]+)/i.exec(line) || [])[1];
      if (rootLogin) {
        const host = typeof rootLogin === 'string' ? rootLogin : rootLogin[1];
        if (!['127.0.0.1', 'localhost', '::1'].includes(host) && !/^[<x]/.test(host)) {
          add('high', 'root-login', relPath, lineNo, `Root SSH login reference to ${host}`, line.trim());
        }
      }

      for (const match of line.matchAll(/\b((?:\d{1,3}\.){3}\d{1,3})\b/g)) {
        const ip = match[1];
        if (isAllowedIp(ip)) continue;
        add('medium', 'production-ip', relPath, lineNo, `Non-loopback IPv4 literal ${ip}`, line.trim());
      }
    }
  }
}

function isAllowedIp(ip) {
  const parts = ip.split('.').map(Number);
  if (parts.some((part) => part < 0 || part > 255)) return true;
  if (parts[0] === 127) return true;
  if (ip === '0.0.0.0' || ip === '255.255.255.255') return true;
  if (parts[0] === 192 && parts[1] === 0 && parts[2] === 2) return true;
  if (parts[0] === 198 && parts[1] === 51 && parts[2] === 100) return true;
  if (parts[0] === 203 && parts[1] === 0 && parts[2] === 113) return true;
  return false;
}

function scanTrackedEnv() {
  let tracked = '';
  try {
    tracked = execFileSync('git', ['ls-files', '-z'], { cwd: ROOT, encoding: 'utf8' });
  } catch {
    warnings.push('git ls-files unavailable; skipped tracked-.env check');
    return;
  }
  for (const file of tracked.split('\u0000').filter(Boolean)) {
    const base = file.split('/').pop() ?? '';
    if (!/^\.env(?:\.|$)/.test(base)) continue;
    if (/\.(?:example|sample|template|dist)$/i.test(base)) continue;
    if (base === '.env' || /^\.env\.(?!example|sample|template|dist)/i.test(base)) {
      add('critical', 'tracked-env', toPosix(file), 0, 'Environment file is tracked by git', file);
    }
  }
}

function print() {
  const order = { critical: 0, high: 1, medium: 2, low: 3 };
  findings.sort((a, b) => (order[a.severity] ?? 9) - (order[b.severity] ?? 9) || a.file.localeCompare(b.file) || a.line - b.line);
  const counts = findings.reduce((acc, finding) => {
    acc[finding.severity] = (acc[finding.severity] ?? 0) + 1;
    return acc;
  }, {});

  console.log(`security-scan: ${scanned.files} files scanned, ${scanned.skipped} skipped`);
  for (const warning of warnings) console.log(`WARN ${warning}`);

  if (findings.length === 0) {
    console.log('PASS no committed secrets, production root logins or production IP literals found');
    return;
  }

  for (const finding of findings) {
    const location = finding.line > 0 ? `${finding.file}:${finding.line}` : finding.file;
    console.log(`\n[${finding.severity.toUpperCase()}] ${finding.rule} ${location}`);
    console.log(`  ${finding.message}`);
    if (finding.excerpt) console.log(`  > ${finding.excerpt.slice(0, 200)}`);
  }

  console.log(
    `\nFAIL ${counts.critical ?? 0} critical, ${counts.high ?? 0} high, ${counts.medium ?? 0} medium, ${counts.low ?? 0} low`,
  );
}

walk(ROOT);
scanTrackedEnv();
print();

const failing = findings.some((finding) => finding.severity === 'critical' || finding.severity === 'high');
process.exit(failing ? 1 : 0);
