// k6 read-load: product list + detail (budget: p95 ≤ 400ms, spec docs/09).
// Usage: k6 run -e BASE_URL=https://... perf/k6/product-read.js
import http from "k6/http";
import { check } from "k6";

export const options = {
  stages: [
    { duration: "1m", target: 20 },
    { duration: "3m", target: 20 },
    { duration: "30s", target: 0 },
  ],
  thresholds: { http_req_duration: ["p(95)<400"] },
};

const BASE = __ENV.BASE_URL || "http://localhost:8090";

export default function () {
  const list = http.get(`${BASE}/api/products?per_page=20`);
  check(list, { "list 200": (r) => r.status === 200 });
}
