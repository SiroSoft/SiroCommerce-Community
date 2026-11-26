// Bounded API proof. Set SCENARIO to one name, or leave unset for the public
// catalog/headless reads. Authenticated and stateful scenarios require the
// matching environment variables documented in perf/README.md.
import http from "k6/http";
import { check } from "k6";

const BASE = __ENV.BASE_URL || "http://localhost:8090";
const SCENARIO = __ENV.SCENARIO || "catalog-browse";
const TOKEN = __ENV.ADMIN_TOKEN || "";
const PROFILE = __ENV.PROFILE || "standard";
const PROFILES = { smoke: { vus: 1, iterations: 5 }, standard: { vus: 5, iterations: 20 }, soak: { vus: 10, iterations: 100 } };
if (!PROFILES[PROFILE]) throw new Error(`Unknown PROFILE=${PROFILE}; use smoke, standard, or soak`);
if (!/^https?:\/\/[^\s/@]+(?:\/[^\s]*)?$/.test(BASE)) throw new Error("BASE_URL must be an http(s) URL without embedded credentials");
const VUS = Math.min(Math.max(Number(__ENV.VUS || PROFILES[PROFILE].vus), 1), PROFILES[PROFILE].vus);
const ITERATIONS = Math.min(Math.max(Number(__ENV.ITERATIONS || PROFILES[PROFILE].iterations), 1), PROFILES[PROFILE].iterations);
const EXPECTED_DATASET = __ENV.DATASET || "";
if (EXPECTED_DATASET && !["10000", "50000", "100000"].includes(EXPECTED_DATASET)) throw new Error("DATASET must be 10000, 50000, or 100000");
const REQUIRE_QUERY_EVIDENCE = __ENV.REQUIRE_QUERY_EVIDENCE === "1";
const headers = TOKEN ? { Authorization: `Bearer ${TOKEN}` } : {};

export const options = {
  vus: VUS,
  iterations: ITERATIONS,
  thresholds: { http_req_duration: ["p(95)<500", "p(99)<800"], http_req_failed: ["rate<0.01"] },
};

function request() {
  if (SCENARIO === "health-live") return http.get(`${BASE}/health/live`, { headers });
  if (SCENARIO === "health-ready") return http.get(`${BASE}/health/ready`, { headers });
  if (SCENARIO === "catalog-browse") return http.get(`${BASE}/api/products?page=1&per_page=20`, { headers });
  if (SCENARIO === "search") return http.get(`${BASE}/api/products?q=${encodeURIComponent(__ENV.SEARCH_TERM || "linen")}&page=1&per_page=20`, { headers });
  if (SCENARIO === "product-detail") return http.get(`${BASE}/api/products/${encodeURIComponent(__ENV.PRODUCT_SLUG || "linen-camp-shirt")}`, { headers });
  if (SCENARIO === "cart") return http.post(`${BASE}/api/carts`, JSON.stringify({ currency: "VND" }), { headers: { ...headers, "Content-Type": "application/json" } });
  if (SCENARIO === "checkout-journey") return b2cJourney();
  if (SCENARIO === "admin-orders") return http.get(`${BASE}/api/admin/orders?page=1&per_page=20`, { headers });
  if (SCENARIO === "reporting-failures") return http.get(`${BASE}/api/v2/admin/reports/failures?page=1&per_page=20`, { headers });
  if (SCENARIO === "headless-products") return http.post(`${BASE}/api/headless/graphql`, JSON.stringify({ query: "{ products(page: 1, perPage: 20) { nodes { id name slug } page perPage } }" }), { headers: { ...headers, "Content-Type": "application/json" } });
  if (SCENARIO === "quote") return http.post(`${BASE}/api/v2/checkout/quote`, JSON.stringify({ cart_id: __ENV.CART_ID || "" }), { headers: { ...headers, "Content-Type": "application/json" } });
  if (SCENARIO === "webhook-burst") return http.post(`${BASE}/api/webhooks/${__ENV.WEBHOOK_PROVIDER || "proof"}`, __ENV.WEBHOOK_BODY || "{}", { headers: { ...headers, "Content-Type": "application/json", "X-Webhook-Signature": __ENV.WEBHOOK_SIGNATURE || "" } });
  throw new Error(`Unknown SCENARIO=${SCENARIO}`);
}

function json(response) {
  try { return response.json(); } catch { return {}; }
}

function b2cJourney() {
  const requestHeaders = { ...headers, "Content-Type": "application/json" };
  const cartResponse = http.post(`${BASE}/api/carts`, JSON.stringify({ currency: "VND" }), { headers: requestHeaders, tags: { step: "cart-create" } });
  const cartBody = json(cartResponse);
  const cart = cartBody.data || cartBody;
  const cartId = cart.id || "";
  const cartToken = cart.token || cart.cart_token || "";
  if (!cartId || !cartToken) return cartResponse;
  const ownedHeaders = { ...requestHeaders, "X-Cart-Token": cartToken };
  const line = http.post(`${BASE}/api/carts/${encodeURIComponent(cartId)}/lines`, JSON.stringify({ variant_id: __ENV.VARIANT_ID || "siro-chanh", qty: 1 }), { headers: ownedHeaders, tags: { step: "cart-line" } });
  check(line, { "cart line accepted": (r) => r.status === 201 });
  const quote = http.get(`${BASE}/api/carts/${encodeURIComponent(cartId)}/quote`, { headers: ownedHeaders, tags: { step: "cart-quote" } });
  check(quote, { "cart quote returned": (r) => r.status === 200 });
  return http.post(`${BASE}/api/checkout/place`, JSON.stringify({ cart_id: cartId, guest_email: `k6-${__VU}-${__ITER}@example.test` }), { headers: { ...ownedHeaders, "Idempotency-Key": `k6-${__VU}-${__ITER}-${Date.now()}` }, tags: { step: "checkout-place" } });
}

export default function () {
  const response = request();
  check(response, {
    "bounded response": (r) => r.status >= 200 && r.status < 500,
    "not unexpectedly rate limited": (r) => r.status !== 429 || SCENARIO === "webhook-burst",
    "response <= 1 MiB": (r) => r.body.length <= 1024 * 1024,
    "security headers declared": (r) => r.headers["X-Content-Type-Options"] === "nosniff" && r.headers["X-Frame-Options"] === "DENY",
    "health payload is coherent": (r) => !SCENARIO.startsWith("health-") || ["live", "ready", "not_ready"].includes(json(r).data?.status),
    "declared dataset matches": (r) => !EXPECTED_DATASET || r.headers["X-Proof-Dataset"] === EXPECTED_DATASET,
    "query evidence declared": (r) => !REQUIRE_QUERY_EVIDENCE || Boolean(r.headers["X-Query-Count"]),
    "N+1 evidence declared": (r) => !REQUIRE_QUERY_EVIDENCE || Boolean(r.headers["X-N-Plus-One"]),
  });
}
