const baseUrl = (process.env.BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '');
const rateLimitCheck = process.env.CERT_RATE_LIMIT === '1';
const requireReady = process.env.CERT_REQUIRE_READY === '1';
const failures = [];
const blocked = [];

function fail(message) { failures.push(message); }

async function request(path, init = {}) {
  try {
    const response = await fetch(`${baseUrl}${path}`, { ...init, signal: AbortSignal.timeout(10_000) });
    return { response, headers: Object.fromEntries([...response.headers].map(([key, value]) => [key.toLowerCase(), value])) };
  } catch (error) {
    blocked.push(`${path}: ${error instanceof Error ? error.message : String(error)}`);
    return null;
  }
}

function assertHeaders(path, headers) {
  const expected = {
    'x-content-type-options': 'nosniff',
    'x-frame-options': 'DENY',
    'referrer-policy': 'no-referrer',
    'content-security-policy': "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
  };
  for (const [name, value] of Object.entries(expected)) if (headers[name] !== value) fail(`${path}: ${name} is ${headers[name] || 'missing'}`);
}

const live = await request('/health/live');
if (live) {
  assertHeaders('/health/live', live.headers);
  if (live.response.status !== 200) fail(`/health/live: expected 200, got ${live.response.status}`);
  const body = await live.response.json().catch(() => ({}));
  if (body.data?.status !== 'live') fail('/health/live: payload is not live');
}

const ready = await request('/health/ready');
if (ready) {
  assertHeaders('/health/ready', ready.headers);
  if (![200, 503].includes(ready.response.status)) fail(`/health/ready: expected 200 or 503, got ${ready.response.status}`);
  if (requireReady && ready.response.status !== 200) fail(`/health/ready: launch requires 200, got ${ready.response.status}`);
  const body = await ready.response.json().catch(() => ({}));
  if (body.data?.status !== (ready.response.status === 200 ? 'ready' : 'not_ready')) fail(`/health/ready: status does not match HTTP ${ready.response.status}`);
}

const catalog = await request('/api/products?page=1&per_page=20');
if (catalog) {
  assertHeaders('/api/products', catalog.headers);
  if (catalog.response.status !== 200) fail(`/api/products: expected 200, got ${catalog.response.status}`);
}

if (rateLimitCheck) {
  let limited = false;
  let reachable = false;
  for (let attempt = 0; attempt < 65; attempt += 1) {
    const result = await request('/health/live');
    if (!result) break;
    reachable = true;
    if (result?.response.status === 429) {
      limited = true;
      if (!result.headers['retry-after']) fail('/health/live: 429 has no Retry-After');
      break;
    }
  }
  if (reachable && !limited) fail('rate limit: 65 health requests did not produce 429');
}

if (failures.length) {
  console.error(`LAUNCH CERTIFICATION FAIL\n${failures.map((failure) => `- ${failure}`).join('\n')}`);
  process.exitCode = 1;
} else if (blocked.length) {
  console.error(`LAUNCH CERTIFICATION BLOCKED\n${blocked.map((item) => `- ${item}`).join('\n')}`);
  process.exitCode = 2;
} else {
  console.log(`LAUNCH CERTIFICATION PASS (${baseUrl}; ready=${requireReady ? 'required' : 'diagnostic'}; rate-limit=${rateLimitCheck ? 'checked' : 'not requested'})`);
}
