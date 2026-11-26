# Storefront Configuration Backend Spec

Owner: Backend developer
Depends on: `docs/contracts/STOREFRONT_THEME_VERTICAL_SPEC.md`

## 1. Scope

Implement a versioned, scoped, published storefront configuration resource.
This is additive and must not change `/api/settings`, catalog contracts, CMS
page response shapes, or purchase contracts.

## 2. Migrations

Create four migrations under `backend/database/migrations`:

### `storefront_themes`

Columns:

```text
id, key, name, version, schema_version, status,
token_schema_json, allowed_section_types_json, defaults_json,
created_at, updated_at
```

Unique: `key + version`.

### `storefront_vertical_presets`

Columns:

```text
id, key, name, version, status,
theme_defaults_json, presentation_defaults_json,
homepage_defaults_json, validation_schema_json,
created_at, updated_at
```

Unique: `key + version`.

### `storefront_configs`

Columns:

```text
id, store_id, channel_id, locale, currency,
theme_key, vertical_key, status, version,
config_json, scheduled_at, published_at,
created_by, updated_by, created_at, updated_at
```

Unique: `store_id + channel_id + locale + currency`.

Indexes:

```text
status + store_id + channel_id
store_id + channel_id + locale + currency + version
```

### `storefront_config_revisions`

Columns:

```text
id, config_id, version, snapshot_json, action,
actor_id, idempotency_key, store_id, channel_id,
locale, currency, created_at
```

Unique: `config_id + version`.

## 3. Seed data

Seed one active theme `editorial` version `1.0.0`, one active preset `general`
version `1.0.0`, and a published default config. The default config must match
the current storefront's output and use the current local demo scope.

Seed data must be idempotent and safe to run repeatedly.

## 4. Domain boundaries

Create a module under:

```text
backend/app/Modules/StorefrontConfig/
```

Recommended structure:

```text
Application/StorefrontConfigService.php
Contracts/StorefrontConfigStore.php
Domain/StorefrontConfig.php
Infrastructure/StorefrontConfigStoreAdapter.php
```

The service owns validation, fallback, version checks, idempotency, audit, and
publish lifecycle. Controllers must remain thin.

## 5. Public endpoint

Add:

```text
GET /api/storefront/config
```

Route requirements:

- FE guard.
- Explicit commerce scope.
- Published rows only.
- Public cache for 60 seconds.
- ETag from canonical response.
- `X-Commerce-Scope` and `X-Storefront-Config-Version` headers.

Response envelope follows existing API conventions. Do not return draft fields,
actor IDs, revision internals, or admin-only validation schemas.

Fallback sequence:

1. Exact published scope.
2. Store/channel default.
3. Global `general` defaults.

If all database reads fail, return a safe default response with a diagnostic
header, not an arbitrary partial JSON document.

## 6. Admin endpoints

Add under `/api/admin/storefront`:

```text
GET    /config
PUT    /config
POST   /config/preview
POST   /config/publish
POST   /config/restore/{revision}
GET    /config/revisions
GET    /config/revisions/diff
GET    /themes
GET    /vertical-presets
POST   /config/apply-preset
```

Use CMS authorization baseline:

```text
cap:cms
DemoGuardMiddleware
```

For every write:

- Verify requested scope belongs to the actor.
- Validate `expected_version`.
- Require `Idempotency-Key`.
- Return `409` on stale version.
- Create revision and audit event.
- Invalidate published cache only after successful publish/restore.

## 7. Validation

Reject:

- Unknown theme or preset keys.
- Unsupported section types.
- More than 50 homepage sections.
- Duplicate section IDs.
- Orders outside `0..10000`.
- Strings over documented limits.
- Non-HTTP or non-relative policy URLs.
- `javascript:`, `data:`, inline HTML, CSS, script, or component references.
- Colors that are not strict hex values in v1.
- Unknown top-level write fields if strict validation is enabled.

Normalize before persistence so equivalent payloads produce equivalent ETags.

## 8. Cache behavior

Key:

```text
storefront:config:{store_id}:{channel_id}:{locale}:{currency}:{version}
```

Do not cache drafts or preview responses publicly. Publish and restore must:

- Invalidate the prior version key.
- Make the new version visible immediately to uncached reads.
- Produce a new ETag.

## 9. OpenAPI and generated clients

- Add explicit request/response schemas to the OpenAPI generator.
- Regenerate `backend/docs/openapi.json` and `backend/public/openapi.json`.
- Regenerate frontend SDK only through the existing generation workflow.
- Do not hand-edit generated SDK output.
- Add contract tests for status envelopes, `409`, ETag, and scope headers.

## 10. Backend test matrix

Add unit/integration/contract coverage for:

- Exact scope read.
- Scope fallback.
- Draft invisibility.
- Invalid config rejection.
- Cross-store read/write denial.
- Cross-channel isolation.
- Locale/currency isolation.
- Stale version conflict.
- Idempotent update/publish/restore/apply-preset.
- Revision snapshot and diff.
- Cache invalidation and ETag change.
- Public endpoint does not leak admin fields.
- Existing CMS/settings/catalog tests remain unchanged and green.

Use isolated MySQL databases for integration/contract tests, matching the
existing backend test harness.

## 11. Delivery sequence

1. Domain types and migrations.
2. Seed theme/preset/default config.
3. Public read with fallback/cache/ETag.
4. OpenAPI and contract tests.
5. Admin draft/update/preview.
6. Publish/restore/revisions/idempotency.
7. Scope/menu follow-up after the primary config endpoint is stable.

## 12. Definition of done

- A frontend developer can fetch the public config with only commerce scope.
- A marketer can update draft config without affecting live storefront.
- Publish is optimistic, idempotent, audited, and cache-invalidating.
- Two stores can use different themes and presets without data leakage.
- Existing API contract and full MySQL test suites remain green.
