# Changelog — siro-admin-next

## [1.1.0] - 2026-11-22

### Added
- Storefront theme builder: quote-mode select, native theme package JSON/zip
  import (with local assets), JSON/zip export, and validation feedback.
- Extensions workspace: package verify, install, **Run QIT** with per-check
  results, license status card, and gated install on QIT failure.
- Vendor payouts: bank CSV reconciliation and failed/returned states.
- Admin OpenAPI/SDK regenerated for the new storefront and extension endpoints.

### Tests
- 574 Vitest tests passing; `tsc` and `next build` green.

## [1.0.1] - 2026-09-13

### Fixed (via backend)
- File uploads (`upload.service.ts`, multipart/form-data) were rejected
  with 415 by backends on sirosoft/core ≤1.0.6. Requires backend
  `sirosoft/core ^1.0.7` — no template code change needed (verified:
  vitest 56 green).

## [1.0.0] - 2026-06-15

### Added
- Initial release of Siro Admin Next.js
- Enterprise dashboard with stats cards and charts
- JWT auth with access + refresh token rotation
- 6 full CRUD modules: Users, Orders, Products, Posts, Categories, Tags
- Rich text editor (Tiptap), image upload, dark mode
- TanStack Query + Table, Zustand state management
- Auto-generated OpenAPI types
- E2E tests with Playwright, unit tests with Vitest
- Docker support
