# Ops: Provider Verify + Payout Export + Scale Plan

Closes operator gaps that do not need new secrets to ship code.

## Provider verification

`php siro providers:verify` (+ `GET /api/admin/providers/verify`, admin/owner):
- For each configured provider, make a **read-only** probe with a 5s timeout and
  report `{name,type,configured,reachable,detail}`; never prints secrets.
- Payment: Stripe `GET /v1/balance`, VNPay/MoMo only config check (no read API).
- Shipping: GHN `GET /shiip/public-api/v2/shop/all`, ViettelPost config check,
  DHL config check.
- Mail: `Mailer::isConfigured()`; Search: Meili `/health` + index stats.
- Overall `certified` = all configured probes reachable; missing credentials are
  `configured:false` (not a failure).

## Payout export

`GET /api/admin/vendor-payouts/{id}/export.csv` (admin/owner):
- CSV bank-bulk columns: `payout_id,vendor,amount_minor,currency,period_start,
  period_end,status` (UTF-8 BOM); `X-Export-Rows`.
- Admin payout dialog: “Export bank CSV” button (same auth header pattern as
  product export). No bank API call.

## Scale plan (docs + scripts)

- `docs/engineering/SCALE_PLAN.md`: stateless app notes (tokens not sessions,
  uploads on shared volume, Redis for cache/throttle), Postgres/MySQL sizing,
  read replica strategy, Meili HA, CDN, horizontal nginx, backup/restore drill
  reference, load targets (p95 800ms, 200 RPS at c=50).
- `scripts/load-smoke.mjs`: support `--concurrency 50` already; add
  `--ramp` (linear ramp 5→N over duration) for more realistic soak.
- Run a 300s, c=30 soak locally against live, update `SLO.md`; note
  single-node caveat.

## Tests

Provider verify with fake transports (reachable/unreachable/missing), no secret
leakage, endpoint auth; payout export CSV shape/BOM/empty batch; ramp loader
unit (scheduler math) if feasible.
