## What changed

<!-- One paragraph. Link the issue/ticket. -->

## Area

- [ ] Platform (backend core, auth, payment, shipping, infra, license)
- [ ] Experience (storefront, admin, shared UI)
- [ ] Ecosystem (extension, theme, catalog, packaging/QIT)
- [ ] Quality/Release (tests, docs, release tooling)

## Risk

- [ ] Free/Community surface changed
- [ ] Pro-only surface changed
- [ ] Database migration
- [ ] API contract/OpenAPI changed
- [ ] License/entitlement changed
- [ ] Security-sensitive

## Verification

Paste exact commands and results. CI may be unavailable; local evidence is
required.

```text
backend:
storefront:
admin:
mirror dry-run:
```

## Release notes

- [ ] CHANGELOG updated
- [ ] Catalog/version compatibility updated (if package release)
- [ ] Docs updated (public vs Pro docs kept separate)
- [ ] No secrets, keys, `.env`, license documents or customer data

## Reviewer notes

CODEOWNERS review is required. Do not push directly to `main`.
