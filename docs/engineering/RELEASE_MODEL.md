# Release Model: Internal Source, Community Mirror, Pro Mirror

Status: CURRENT. Decision: **model B** — the private internal monorepo is the
source of truth. Public Community and private Pro repositories are release
mirrors and must not be edited by hand.

## 1. Repositories

| Repository | Visibility | Role | Direct edits |
|---|---|---|---|
| `SiroCommerce` (internal monorepo) | PRIVATE | Source of truth for core, free and Pro packages | Yes, via PR |
| `SiroCommerce-Community` | PUBLIC | Free/core release mirror (MIT) | No; exporter only |
| `SiroCommerce-Pro` | PRIVATE | Commercial release mirror (Pro source/artifacts) | No; exporter only |

The internal repository contains both free and Pro source. The exporter removes
Pro material from Community and exports only selected Pro material to Pro.

## 2. Teams (initial)

Use four teams until the team grows beyond roughly eight developers:

| Team | Scope |
|---|---|
| Platform | backend core, auth, payment, shipping, infra, security/license |
| Experience | storefront, admin/owner console, shared UI |
| Ecosystem | plugins, themes, catalog, packaging/QIT, Pro packages |
| Quality & Release | QA, E2E, release captain, compatibility, docs |

When Experience >8 developers, split into Storefront and Admin. Add a dedicated
Security team only when it has a real owner and rotation.

CODEOWNERS in the internal repository should be updated to these teams once the
GitHub org teams exist. Until then, maintainer handles are used.

## 3. Branch and PR workflow

- Trunk-based development; short-lived branches.
- No direct push to `main`.
- Every change goes through a PR and CODEOWNERS review.
- Only the release captain merges release PRs/tags.
- GitHub Team plan is deferred. Until then, private repos cannot use technical
  branch protection: enforce with PR templates, CODEOWNERS, maintainer
  discipline and a pre-push check. Community public branch protection is active.
- Required status checks are enabled only after CI is stable. Until then, PRs
  must include local verification evidence.

## 4. Versioning

### Core / Community

- SemVer: `MAJOR.MINOR.PATCH`.
- Tag: `vMAJOR.MINOR.PATCH`.
- Source: `backend/app/CommerceVersion.php`.
- Breaking API/behavior -> major; additive -> minor; fix -> patch.
- Tags are immutable; never force-push or re-tag a release.

### Free plugins and themes

- Each package keeps its own SemVer in `manifest.json` / `theme.json`.
- Catalog `latest_version` is authoritative for updates.
- Optional tags: `plugin/<vendor>/<name>@vX.Y.Z`, `theme/<key>@vX.Y.Z`.
- Packages ship with a Community release or as catalog artifacts; no
  auto-update.

### Pro bundle and packages

- Pro bundle tag: `pro-vX.Y.Z`.
- Each Pro package keeps its own SemVer.
- Pro manifest declares `requires.community: ^X.Y`.
- Pro is tested only against the Community tag recorded in
  `release/compatibility.json`.
- Signed artifacts are built in private CI/controlled host; private keys never
  enter any repository.

## 5. Compatibility contract

`release/compatibility.json` maps the released Community version to Pro bundle
and package versions. Update it in the release PR. Do not release Pro against an
untagged Community commit.

```json
{
  "community": "1.4.0",
  "pro_bundle": "1.2.0",
  "packages": {
    "siro/affiliate": "1.2.0",
    "siro/atelier-noir": "1.0.0"
  }
}
```

## 6. Free/Community release

1. Merge the release PR into internal `main`.
2. Create RC tag `vX.Y.Z-rc.N` and run the local gate.
3. Export the Community mirror:
   - `npm run release:mirrors:dry` to inspect;
   - `npm run release:mirrors` to write.
4. The exporter:
   - copies the internal tree excluding Pro source and internal release tooling;
   - removes known Pro paths from the target;
   - applies `release/community-overrides` (README, LICENSE, free-only tests,
     generic extension admin UI, cleaned PHPStan baseline);
   - keeps Pro catalog metadata/previews only if configured.
5. Run backend/storefront/admin gates **on the Community mirror**.
6. Commit `release: vX.Y.Z` in Community, tag `vX.Y.Z`, push.
7. Regenerate catalog feed and attach release assets/checksums.
8. Deploy from the internal tag, not from the mirror.

## 7. Pro release

1. Use the same internal RC tag.
2. Export the Pro mirror with the same command.
3. Build signed zips for Pro packages in private CI.
4. Test Pro packages against the released Community `vX.Y.Z`.
5. Tag `pro-vX.Y.Z` in Pro and publish the private release.
6. Never publish Pro artifact URLs, license private keys or customer license
   files publicly.

## 8. Plugin/theme release

- New package: manifest/theme package + QIT + catalog overlay + docs.
- Free: include in Community release; update catalog and changelog.
- Pro: include in Pro release; license SKU, signed artifact, private feed.
- Update: bump package SemVer, changelog, compatibility, QIT, install/enable/
  disable/uninstall smoke.
- Deprecation: catalog `status=deprecated`; keep history; publish notice.

## 9. Public vs private docs

Default: Pro pricing, license issuance, commercial plans and Pro runbooks are
**private** (internal/Pro only). Community keeps public product docs and may
keep catalog metadata/preview images for marketing. Final public/private docs
split is still pending; do not add new Pro commercial docs to Community.

## 10. Interim quality gate (CI unavailable)

- Run backend PHPStan + PHPUnit, storefront/admin typecheck/tests/build.
- Run `npm run release:mirrors:dry` and inspect.
- Paste commands and results into the PR.
- Record `NOT_PROVEN` for anything not run; never claim CI ran.
- After self-hosted CI is live, make backend/storefront/admin checks required.

## 11. Release captain checklist

- [ ] Compatibility file updated.
- [ ] Changelogs updated; Pro docs not in Community.
- [ ] Free mirror exported and tested.
- [ ] Pro mirror exported and tested against Community tag.
- [ ] Catalog feed regenerated; drift check passes.
- [ ] Tags immutable; no secret/key files staged.
- [ ] Deploy from internal tag; health/smoke recorded.
- [ ] Release notes and evidence archived.
