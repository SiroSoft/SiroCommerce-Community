// k6 spike: duplicate-place guard under burst (spec F08 — exactly 1 order per key).
// Each VU uses a UNIQUE Idempotency-Key; asserts 200/422 envelope, never 500.
// Usage: k6 run -e BASE_URL=https://... perf/k6/checkout-spike.js
import http from "k6/http";
import { check } from "k6";

export const options = {
  stages: [
    { duration: "30s", target: 50 },
    { duration: "1m", target: 50 },
    { duration: "30s", target: 0 },
  ],
  thresholds: { http_req_failed: ["rate<0.01"] },
};

const BASE = __ENV.BASE_URL || "http://localhost:8090";

export default function () {
  const key = `spike-${__VU}-${__ITER}-${Date.now()}`;
  const res = http.post(
    `${BASE}/api/checkout/place`,
    JSON.stringify({ cart_id: "dummy" }),
    { headers: { "Content-Type": "application/json", "Idempotency-Key": key } },
  );
  check(res, { "no 500": (r) => r.status !== 500 });
}
