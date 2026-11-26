# Launch Readiness Spec (free tier)

Goal: a new operator can go from fresh install to a sellable store in < 30
minutes without asking us for steps.

## API

`GET /api/admin/launch/status` (admin/owner)
```json
{ "ready": false, "score": 62, "checks": [
  { "key": "store_config", "state": "ok|warn|fail", "hint": "Publish the storefront theme", "href": "/storefront/theme" },
  { "key": "catalog", "state": "fail", "hint": "Add at least one active product" },
  { "key": "payments", "state": "warn", "hint": "Set STRIPE_SECRET_KEY" },
  { "key": "shipping", "state": "warn", "hint": "Configure DHL/GHN or native rates" },
  { "key": "mail", "state": "warn", "hint": "Set MAIL_* for transactional email" },
  { "key": "search", "state": "ok", "hint": "Meilisearch indexed" },
  { "key": "legal_pages", "state": "warn", "hint": "Publish shipping/returns/contact pages" },
  { "key": "domain_tls", "state": "ok", "hint": "HTTPS is live" }
] }
```
- Never returns secrets; only "configured / missing".
- `score` = weighted ok=1, warn=0.5, fail=0 over the checks.

`POST /api/admin/launch/seed-demo` (admin/owner, Idempotency-Key)
- Idempotent sample data: 3 categories, 6 products (with variants/inventory),
  3 CMS pages (`shipping`, `returns`, `contact`), general theme preset, and
  the `primary` menu when empty.
- Returns `{created: {...}, skipped: {...}}`; re-run creates nothing.
- Refuses when catalog already has > 50 products (protects real stores).

## CLI

`php siro launch:check` — same checks as the endpoint, table output, exit 1 if
any `fail`.
`php siro demo:seed` — same as seed-demo (idempotent, prints summary).

## Admin UI

`/launch` page (capability `products`): progress ring + checklist cards, each
with state badge, hint, and a link to the relevant screen. Buttons: “Seed demo
data” (ConfirmDialog) and “Re-run checks”. Shown in sidebar as “Launch
checklist”.

## Provider status

`GET /api/admin/providers/status` returns configured booleans + provider names
for payment, shipping, mail, search, AI. Used by the launch page; no secrets.

## Non-goals

No multi-step wizard persistence, no automatic key purchasing, no DNS changes.
