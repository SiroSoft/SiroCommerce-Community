# Compliance: Cookie Consent + Privacy Spec

Free-tier GDPR/VN baseline: explicit consent, analytics gating, privacy pages.

## Consent model

Storage + API record:
- `POST /api/consent` (`{necessary: true, analytics: boolean, marketing:
  boolean, policy_version: "1"}` + optional `X-Session-Id`): stores a
  `consent_records` row (id, session_hash ≤64, choices_json, policy_version,
  ip_hash, user_agent ≤200, created_at) — no raw IP/PII; idempotent by
  session+version (update on change).
- `GET /api/consent/policy` returns `{version, updated_at, sections:
  [{id,title,body}]}` from config (shipping/returns/privacy/cookies).
- Client keeps the decision in `localStorage` (`siro-consent:{version}`) and
  re-prompts when version changes.

## Frontend

- Consent banner (bottom, accessible dialog-ish, no focus trap steal): “Accept
  all / Necessary only / Preferences” with links to privacy & cookie policy.
- Preference center: toggles for analytics and marketing; necessary always on.
- **Analytics gating**: `trackStorefrontEvent` and web-vitals send only when
  analytics consent is granted; marketing pixels (if added later) gate on
  marketing. No analytics before a decision (necessary-only = no tracking).
- Banner hidden on `/checkout`, `/cart` (reduce friction) but consent can be
  opened from footer link “Cookie settings”.

## Pages

- CMS pages `privacy`, `cookie-policy` (published by demo seed; operators can
  edit). Footer links to both.
- `robots`: no change.

## Admin

No new page in v1; Settings shows consent record count (read-only) via
`GET /api/admin/consent/summary` (admin/owner): `{records, analytics_opt_in,
marketing_opt_in, policy_version}`.

## Tests

Consent API validation/idempotency, no raw IP stored, policy endpoint shape,
banner render/preferences persistence, analytics not sent without consent,
tracking sent after accept, summary auth, footer links.

## Non-goals

CCPA sale opt-out, DSAR workflow automation (existing account anonymize stays),
consent per category beyond analytics/marketing.
