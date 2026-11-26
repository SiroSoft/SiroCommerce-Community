#!/usr/bin/env bash
set -euo pipefail
BASE="${HEALTH_URL:-https://ecommerce.sirophp.com}"
echo "Smoke $BASE"
check() { url="$1"; code=$(curl -s -o /tmp/smoke.json -w "%{http_code}" "$url"); echo "$url -> $code"; if [ "$code" != "200" ]; then echo "FAIL $url"; cat /tmp/smoke.json; return 1; fi; }
check "$BASE/health/live"
check "$BASE/health/ready"
check "$BASE/health/providers" || echo "providers missing (expected when secrets empty)"
check "$BASE/api/storefront/config"
check "$BASE/api/v2/catalog/product-types"
echo "SMOKE PASS"
