# Docs Audit — 2026-11-22

Scope: all repository Markdown except `vendor/`, `node_modules/`, build output and
storage. Audit covers links, stale release/CI claims, Free/Pro status, API
inventory, license format and cross-document contradictions.

Status: COMPLETE for this cycle. Remaining items are explicitly listed as
follow-ups; no document is marked CURRENT while contradicting code without a
caveat.

## 1. Method

1. Inventory: 183 Markdown files across `docs/`, root docs, app READMEs.
2. Link checker: relative Markdown links resolved against the file system,
   anchors/external URLs excluded.
3. Claim scan: route/OpenAPI counts, test/coverage numbers, CI/deploy statements,
   Pro/license status, Bagisto/Woo scope, release IDs.
4. Source comparison: `backend/routes/api.php`, `backend/docs/openapi.json`,
   `backend/docs/api-handoff/latest-result.json`, extension/theme/license code.
5. Targeted fixes; re-run link checker and stale-phrase scan.

## 2. Results

### Links

- Before: 2 broken links, both in `docs/operations/EXTENSION_UPDATES.md`
  pointing to `../contracts/EXTENSION_LICENSE_ISSUANCE.md` (file lives under
  `docs/operations/`).
- Fixed: links and status row now point to
  `../operations/EXTENSION_LICENSE_ISSUANCE.md`.
- After: **0 broken relative links / 183 files**.

### Corrected claims

| Severity | File | Problem | Fix |
|---|---|---|---|
| High | `AGENTS.md` | Said PR/push CI gates and production releases by CI | Added Actions-disabled banner; manual `deploy/release.sh` is current path |
| High | `docs/operations/CI_CD_RUNBOOK.md` | “Manual deploys are retired; every push runs CI” | Status banner: Actions disabled; manual releases used |
| High | `docs/engineering/CI_QUALITY.md` | Implied gates run automatically | Banner: workflows exist but Actions disabled; coverage numbers are pre-Nov-2026 |
| High | `docs/README.md` | Licensing “still planned”; 415 routes/362 paths | Updated to license foundation shipped; **457 routes / 457 operations / 395 paths** |
| High | `docs/product/PRO_EDITION_PLAN.md` | JWT/Ed25519 license implied; only flags shipped | Status update: RSA-SHA256 signed JSON implemented; JWT future; packaging/marketplace/SLA still planned |
| High | `docs/product/PLATFORM_ROADMAP.md` | “Only feature-flag scaffolding shipped” | Foundation shipped list; Pro GA still not delivered |
| High | `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md` | Title/status implied Bagisto-compatible active scope; license JWT | Siro-native title; P0/P1 implemented; P2 planned; signed JSON/RSA current |
| Medium | `docs/foundation/BE_OVERVIEW.md` | 415 routes / 362 paths | 457 / 395 (verified 2026-11-22) |
| Medium | `docs/admin/ADMIN_UI_SCREEN_SPECS.md` | Current contract 415/362; bearer/FE counts unqualified | Current inventory 457/395; derived snapshot counts labelled historical |
| Medium | `docs/admin/ADMIN_UI_PARALLEL_WORKSTREAMS.md` | 415 routes, 318 handoff records | 457 routes; handoff snapshot 448, `NOT_READY_BLOCKED` |
| Medium | `docs/admin/ADMIN_UI_ROLE_DATA_QA.md` | 415 operations | 457 operations |
| Medium | `docs/frontend/FE_CAPABILITY_MATRIX.md` | 415 operations; verified 2026-11-17 | 457 operations; verified 2026-11-22; 186/457 mapping called historical |
| Medium | `docs/frontend/FE_SCOPE_SWITCHING.md` | 62 `x-scope` operations | 63 operations (2026-11-22) |
| Medium | `docs/frontend/FE_SECURITY_PRIVACY.md` | 62 `x-scope` operations (2026-11-17) | 63 operations (2026-11-22) |
| Medium | `docs/operations/EXTENSION_UPDATES.md` | License issuance PLANNED; broken link | Issuance CLI shipped; vendor portal planned; link fixed |

### Canonical inventory (2026-11-22)

- Route declarations: **457** (`backend/routes/api.php`).
- OpenAPI operations: **457** across **395 paths**.
- Security-bearing operations: **406**.
- `x-scope` operations: **63**.
- Checked-in handoff snapshot: **448 routes**, readiness `NOT_READY_BLOCKED`
  (stale vs current 457; regenerate with `npm run api:handoff`).

## 3. Free edition documentation verdict

Free/core docs are now consistent enough for development:

- Theme package, extension packaging, QIT, scoping, slots, license foundation,
  payout reconciliation, quote mode and 18 presets are documented with source
  evidence.
- Pro is explicitly **not GA**: packaging/marketplace billing, vendor portal,
  SSO/SAML, SLA/support and managed AI quotas remain PLANNED.
- Affiliate is explicitly Pro and not part of free readiness.
- CI/deploy documentation no longer claims automation that is currently off.

## 4. Residual findings (follow-ups, not blockers)

| Severity | Area | Finding | Owner/action |
|---|---|---|---|
| Medium | Coverage evidence | Backend/storefront/admin coverage numbers in `CI_QUALITY.md` predate Nov 2026 changes; local run lacks PCOV/Xdebug | Re-run in Docker/CI when Actions restored |
| Medium | API handoff | Snapshot 448 vs current 457; FE mapping 186/457 historical | Run `npm run api:handoff`; update matrices from generated data |
| Medium | CI | GitHub Actions disabled; `extension-qit.yml` present but inactive | Re-enable Actions or use a self-hosted runner |
| Low | Audit/QA docs | Some matrices are dated point-in-time and describe historical slices | Keep LEGACY labels; do not quote as current evidence |
| Low | Provider docs | Provider certification still requires real credentials/evidence | Follow `B2C_PROVIDER_CERTIFICATION.md`; do not claim live certification |
| Low | Marketing | Site is built and pushed to its own repo but domain/Cloudflare pending | Set `NEXT_PUBLIC_SITE_URL`, deploy, then update evidence |

## 5. Commands used

```bash
node /tmp/link-check.mjs "$PWD" /tmp/links.json
node -e "const o=require('./backend/docs/openapi.json'); ..."
rg -n -i "every push|gates every|415 route|362 paths|licensing/entitlements..." docs README.md AGENTS.md
php vendor/bin/phpunit --no-coverage
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

## 6. Conclusion

- Docs audit: **PASS with residual follow-ups**.
- Free docs suitable for onboarding theme/plugin developers now.
- Pro docs correctly describe a **foundation, not GA**.
- Generated API matrix and coverage evidence must be regenerated before a GA or
  sales claim.
