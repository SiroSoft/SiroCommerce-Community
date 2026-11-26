#!/usr/bin/env node
/**
 * Load smoke harness for read-only storefront traffic.
 *
 * Uses the built-in fetch (Node 20+), sends GET requests only, and reports
 * p50/p95/p99 latency, RPS and error rate. Exit code is 1 when the error rate
 * exceeds 2% or p95 exceeds 1500ms, so CI/ops can gate on real numbers.
 * Long soaks can pass --abort-error-rate / --abort-p95 to stop early instead
 * of burning the full duration after the target is already missed.
 *
 * Pass --ramp to start at 5 in-flight requests and increase linearly to the
 * --concurrency value across the run duration, which is a more realistic soak
 * than jumping straight to peak concurrency.
 *
 * Usage:
 *   node scripts/load-smoke.mjs --base https://ecommerce.sirophp.com \
 *     --duration 15 --concurrency 10
 *
 * Defaults: base = LOAD_BASE_URL or https://ecommerce.sirophp.com,
 * duration = 20s, concurrency = 20, four read-only storefront paths.
 */

import { pathToFileURL } from 'node:url';

const DEFAULT_BASE = process.env.LOAD_BASE_URL || 'https://ecommerce.sirophp.com';
const DEFAULT_DURATION = 20;
const DEFAULT_CONCURRENCY = 20;
const MIN_RAMP_CONCURRENCY = 5;
const DEFAULT_PATHS = [
  '/health/live',
  '/api/search/suggest?q=lin',
  '/api/storefront/config',
  '/product/linen-camp-shirt',
];
const DEFAULT_HEADERS = {
  'X-Store-Id': 'default',
  'X-Channel-Id': 'web',
  'X-Locale': 'en',
  'X-Currency': 'VND',
};
const REQUEST_TIMEOUT_MS = 15000;
const MAX_ERROR_RATE = 0.02;
const MAX_P95_MS = 1500;

function usage() {
  return [
    'Usage: node scripts/load-smoke.mjs [options]',
    '',
    'Read-only load smoke (GET only, never sends write requests).',
    '',
    'Options:',
    `  --base <url>          Target base URL (default: $LOAD_BASE_URL or ${DEFAULT_BASE})`,
    `  --duration <seconds>  Test duration in seconds (default: ${DEFAULT_DURATION})`,
    `  --concurrency <n>     Concurrent in-flight requests (default: ${DEFAULT_CONCURRENCY})`,
    '  --ramp                Ramp linearly from 5 to --concurrency across the duration',
    '  --path <path>         Request path; repeatable (default: 4 storefront reads)',
    '  --header <K: V>       Extra header; repeatable',
    '  --abort-error-rate <%> Stop early when the error rate exceeds this percentage (0 = off)',
    '  --abort-p95 <ms>      Stop early when p95 exceeds this many ms (0 = off)',
    '  --help, -h            Show this help and exit',
    '',
    'Default paths:',
    ...DEFAULT_PATHS.map((path) => `  ${path}`),
    '',
    'Exit code: 1 when error rate > 2%, p95 > 1500ms, or an early-abort target is hit.',
  ].join('\n');
}

function parseArgs(argv) {
  const options = {
    base: DEFAULT_BASE,
    duration: DEFAULT_DURATION,
    concurrency: DEFAULT_CONCURRENCY,
    ramp: false,
    paths: [],
    headers: { ...DEFAULT_HEADERS },
    abortErrorRate: 0,
    abortP95Ms: 0,
    help: false,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    const next = () => {
      const value = argv[index + 1];
      if (value === undefined) {
        throw new Error(`Missing value for ${argument}`);
      }
      index += 1;
      return value;
    };

    switch (argument) {
      case '--help':
      case '-h':
        options.help = true;
        break;
      case '--base':
        options.base = next();
        break;
      case '--duration':
        options.duration = Number(next());
        break;
      case '--concurrency':
        options.concurrency = Number(next());
        break;
      case '--ramp':
        options.ramp = true;
        break;
      case '--path':
        options.paths.push(next());
        break;
      case '--abort-error-rate':
        options.abortErrorRate = Number(next());
        break;
      case '--abort-p95':
        options.abortP95Ms = Number(next());
        break;
      case '--header': {
        const raw = next();
        const split = raw.indexOf(':');
        if (split <= 0) {
          throw new Error(`Invalid --header "${raw}", expected "Key: Value"`);
        }
        options.headers[raw.slice(0, split).trim()] = raw.slice(split + 1).trim();
        break;
      }
      default:
        throw new Error(`Unknown argument: ${argument}`);
    }
  }

  if (!options.help && options.paths.length === 0) {
    options.paths = [...DEFAULT_PATHS];
  }

  return options;
}

function percentile(sorted, fraction) {
  if (sorted.length === 0) {
    return 0;
  }
  const index = Math.min(sorted.length - 1, Math.max(0, Math.ceil(fraction * sorted.length) - 1));
  return sorted[index];
}

function round(value, digits = 2) {
  const factor = 10 ** digits;
  return Math.round(value * factor) / factor;
}

/**
 * Linear ramp target for the given elapsed time. At t=0 the target is the
 * smaller of the start value and the peak, and at t>=duration it equals peak.
 */
export function computeRampConcurrency(elapsedMs, durationMs, peak, start = MIN_RAMP_CONCURRENCY) {
  const target = Math.max(1, Math.floor(peak));
  const initial = Math.max(1, Math.min(start, target));
  if (!(durationMs > 0)) return target;
  const progress = Math.min(1, Math.max(0, elapsedMs / durationMs));
  return Math.round(initial + (target - initial) * progress);
}

function summarize(durations, requests, errors, elapsedSeconds) {
  const sorted = [...durations].sort((a, b) => a - b);
  const total = requests;
  const errorRate = total === 0 ? 0 : errors / total;
  const sum = sorted.reduce((carry, value) => carry + value, 0);

  return {
    requests: total,
    errors,
    errorRate: round(errorRate * 100, 2),
    rps: round(elapsedSeconds > 0 ? total / elapsedSeconds : 0, 2),
    latencyMs: {
      min: round(sorted.length ? sorted[0] : 0),
      avg: round(sorted.length ? sum / sorted.length : 0),
      p50: round(percentile(sorted, 0.5)),
      p95: round(percentile(sorted, 0.95)),
      p99: round(percentile(sorted, 0.99)),
      max: round(sorted.length ? sorted[sorted.length - 1] : 0),
    },
    errorRateRatio: errorRate,
  };
}

async function main() {
  let options;
  try {
    options = parseArgs(process.argv.slice(2));
  } catch (error) {
    console.error(`Error: ${error.message}`);
    console.error('');
    console.error(usage());
    process.exit(2);
  }

  if (options.help) {
    console.log(usage());
    return;
  }

  if (!(options.duration > 0) || !Number.isFinite(options.duration)) {
    console.error('Error: --duration must be a positive number of seconds');
    process.exit(2);
  }
  if (!(options.concurrency > 0) || !Number.isInteger(options.concurrency)) {
    console.error('Error: --concurrency must be a positive integer');
    process.exit(2);
  }
  if (!(options.abortErrorRate >= 0) || !Number.isFinite(options.abortErrorRate)) {
    console.error('Error: --abort-error-rate must be zero or a positive percentage');
    process.exit(2);
  }
  if (!(options.abortP95Ms >= 0) || !Number.isFinite(options.abortP95Ms)) {
    console.error('Error: --abort-p95 must be zero or a positive number of milliseconds');
    process.exit(2);
  }

  let base;
  try {
    base = new URL(options.base);
  } catch {
    console.error(`Error: invalid --base URL: ${options.base}`);
    process.exit(2);
  }

  const baseHref = base.toString().endsWith('/') ? base.toString() : `${base.toString()}/`;
  const targets = options.paths.map((path) => {
    try {
      return { path, url: new URL(path, baseHref).toString() };
    } catch {
      console.error(`Error: invalid path: ${path}`);
      process.exit(2);
    }
  });

  const startedAt = new Date();
  const deadline = Date.now() + options.duration * 1000;
  const durations = [];
  const perPath = new Map(targets.map((target) => [target.path, { durations: [], requests: 0, errors: 0 }]));
  let cursor = 0;
  let requests = 0;
  let errors = 0;
  let abortedEarly = false;
  let abortReason = null;
  let lastAbortCheck = 0;

  function checkAbort() {
    if (abortedEarly) return;
    const now = Date.now();
    if (now - lastAbortCheck < 500) return;
    lastAbortCheck = now;
    if (options.abortErrorRate > 0 && requests >= 20 && (errors / requests) * 100 > options.abortErrorRate) {
      abortedEarly = true;
      abortReason = `error rate ${round((errors / requests) * 100, 2)}% > ${options.abortErrorRate}%`;
      return;
    }
    if (options.abortP95Ms > 0 && requests >= 50) {
      const sorted = [...durations].sort((a, b) => a - b);
      const p95 = round(percentile(sorted, 0.95));
      if (p95 > options.abortP95Ms) {
        abortedEarly = true;
        abortReason = `p95 ${p95}ms > ${options.abortP95Ms}ms`;
      }
    }
  }

  async function worker() {
    while (Date.now() < deadline && !abortedEarly) {
      const target = targets[cursor % targets.length];
      cursor += 1;
      const started = performance.now();
      let failed = false;

      try {
        const response = await fetch(target.url, {
          method: 'GET',
          headers: options.headers,
          redirect: 'follow',
          signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
        });
        await response.arrayBuffer().catch(() => undefined);
        failed = response.status >= 400;
      } catch {
        failed = true;
      }

      const elapsed = performance.now() - started;
      const bucket = perPath.get(target.path);
      durations.push(elapsed);
      bucket.durations.push(elapsed);
      requests += 1;
      bucket.requests += 1;
      if (failed) {
        errors += 1;
        bucket.errors += 1;
      }
      checkAbort();
    }
  }

  const workers = [];
  let rampTimer = null;
  if (options.ramp) {
    const initial = Math.min(MIN_RAMP_CONCURRENCY, options.concurrency);
    const spawnUpTo = (target) => {
      while (workers.length < target) workers.push(worker());
    };
    spawnUpTo(initial);
    rampTimer = setInterval(() => {
      const elapsedMs = Date.now() - startedAt.getTime();
      const target = computeRampConcurrency(elapsedMs, options.duration * 1000, options.concurrency);
      spawnUpTo(target);
      if (target >= options.concurrency || Date.now() >= deadline || abortedEarly) {
        if (rampTimer !== null) clearInterval(rampTimer);
      }
    }, 250);
  } else {
    for (let index = 0; index < options.concurrency; index += 1) workers.push(worker());
  }

  await Promise.all(workers);
  if (rampTimer !== null) clearInterval(rampTimer);
  const elapsedSeconds = (Date.now() - startedAt.getTime()) / 1000;
  const overall = summarize(durations, requests, errors, elapsedSeconds);
  const paths = targets.map((target) => {
    const bucket = perPath.get(target.path);
    const summary = summarize(bucket.durations, bucket.requests, bucket.errors, elapsedSeconds);
    return {
      path: target.path,
      requests: summary.requests,
      errors: summary.errors,
      errorRate: summary.errorRate,
      p50: summary.latencyMs.p50,
      p95: summary.latencyMs.p95,
      p99: summary.latencyMs.p99,
    };
  });

  const report = {
    base: base.toString(),
    startedAt: startedAt.toISOString(),
    durationSeconds: options.duration,
    concurrency: options.concurrency,
    ramp: options.ramp,
    abortedEarly,
    abortReason,
    headers: options.headers,
    totals: {
      requests: overall.requests,
      errors: overall.errors,
      errorRate: overall.errorRate,
      rps: overall.rps,
      latencyMs: {
        min: overall.latencyMs.min,
        avg: overall.latencyMs.avg,
        p50: overall.latencyMs.p50,
        p95: overall.latencyMs.p95,
        p99: overall.latencyMs.p99,
        max: overall.latencyMs.max,
      },
    },
    paths,
  };

  console.log(JSON.stringify(report, null, 2));
  console.log('');

  const pad = (value, width, right = false) => {
    const text = String(value);
    return right ? text.padStart(width) : text.padEnd(width);
  };

  console.log('=== Load smoke ===');
  console.log(`base         ${report.base}`);
  console.log(`duration     ${options.duration}s  concurrency ${options.concurrency}${options.ramp ? ' (ramp 5→' + options.concurrency + ')' : ''}`);
  console.log(`requests     ${overall.requests}  errors ${overall.errors}  error rate ${overall.errorRate}%  rps ${overall.rps}`);
  console.log(`latency ms   p50 ${overall.latencyMs.p50}  p95 ${overall.latencyMs.p95}  p99 ${overall.latencyMs.p99}  min ${overall.latencyMs.min}  max ${overall.latencyMs.max}`);
  if (abortedEarly) console.log(`aborted      ${abortReason}`);
  console.log('');
  console.log(`${pad('path', 38)} ${pad('req', 6, true)} ${pad('err', 5, true)} ${pad('err%', 7, true)} ${pad('p50', 8, true)} ${pad('p95', 8, true)} ${pad('p99', 8, true)}`);
  for (const entry of paths) {
    console.log(
      `${pad(entry.path, 38)} ${pad(entry.requests, 6, true)} ${pad(entry.errors, 5, true)}`
      + ` ${pad(entry.errorRate, 7, true)} ${pad(entry.p50, 8, true)} ${pad(entry.p95, 8, true)} ${pad(entry.p99, 8, true)}`,
    );
  }

  if (abortedEarly) {
    console.error(`\nABORTED: ${abortReason}`);
    process.exit(1);
  }
  const breached = overall.errorRateRatio > MAX_ERROR_RATE || overall.latencyMs.p95 > MAX_P95_MS;
  if (overall.requests === 0) {
    console.error('\nFAIL: no requests completed');
    process.exit(1);
  }
  if (breached) {
    const reasons = [];
    if (overall.errorRateRatio > MAX_ERROR_RATE) {
      reasons.push(`error rate ${overall.errorRate}% > ${MAX_ERROR_RATE * 100}%`);
    }
    if (overall.latencyMs.p95 > MAX_P95_MS) {
      reasons.push(`p95 ${overall.latencyMs.p95}ms > ${MAX_P95_MS}ms`);
    }
    console.error(`\nFAIL: ${reasons.join(', ')}`);
    process.exit(1);
  }

  console.log('\nPASS: error rate and p95 within budget');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    console.error(`Load smoke crashed: ${error?.stack || error}`);
    process.exit(1);
  });
}
