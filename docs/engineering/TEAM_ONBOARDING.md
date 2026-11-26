# Team Onboarding & Development Process

Status: CURRENT. File này dùng để share cho các team trước khi bắt đầu phát triển.

## 1. Mô hình repo

| Repo | Visibility | Vai trò | Ai được sửa |
|---|---|---|---|
| `SiroCommerce` (internal) | PRIVATE | Source of truth duy nhất cho core + free + Pro | Tất cả team qua PR |
| `SiroCommerce-Community` | PUBLIC | Mirror release free/core (MIT) | Không ai sửa tay |
| `SiroCommerce-Pro` | PRIVATE | Mirror release Pro/paid | Không ai sửa tay |

Quy tắc:
- Code luôn bắt đầu từ internal repo.
- Community/Pro chỉ được cập nhật bằng `npm run release:mirrors`.
- Không copy code tay giữa các repo.
- Không đưa Pro source, license, pricing, key hoặc customer data vào Community.

## 2. Teams (4 nhóm)

| Team | Phụ trách |
|---|---|
| **Platform** | backend core, auth, payment, shipping, infra/deploy, security/license |
| **Experience** | storefront, admin/owner console, shared UI |
| **Ecosystem** | plugin, theme, catalog, packaging/QIT, Pro packages |
| **Quality & Release** | QA, E2E, release captain, compatibility, docs |

Nếu Experience lớn hơn ~8 người, tách thành Storefront và Admin.
CODEOWNERS hiện dùng maintainer handles; đổi sang team handles khi org teams được tạo.

## 3. Branch & PR

- Trunk-based, branch ngắn: `feat/<area>/<ticket>`, `fix/<area>/<ticket>`.
- Không push trực tiếp `main`.
- Mỗi PR phải:
  - có mô tả thay đổi, risk và khu vực ảnh hưởng;
  - qua CODEOWNERS review;
  - có bằng chứng test local;
  - không chứa secret/key/.env/license file/customer data.
- Chỉ **release captain** merge release PR/tag.
- Community public đã bật branch protection (PR + 1 review + code owner).
- Pro private hiện chưa bật được branch protection do GitHub plan; dùng quy trình thủ công: không direct push, maintainer merge.

## 4. CI hiện tại

- GitHub Actions đang hết quota; **CI tạm skip**.
- Không được nói "CI đã chạy" nếu chưa chạy.
- Mỗi PR phải chạy local gate và paste command + kết quả.
- Self-hosted runner template nằm ở `deploy/ci/`; chỉ dùng cho repo private/internal. Không gắn self-hosted runner vào public Community.
- Khi CI quay lại, bật required checks và merge queue.

### Local gate tối thiểu

```bash
cd backend
composer install
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
php vendor/bin/phpunit --no-coverage
cd ..

npm ci
npm run typecheck
npm test --workspace apps/storefront
npm test --workspace apps/admin
npm run build --workspace apps/storefront
npm run build --workspace apps/admin
npm run release:mirrors:dry
```

Nếu chỉ sửa docs: có thể bỏ backend/frontend, nhưng vẫn phải chạy link/format check nếu có.

## 5. Release

Chi tiết đầy đủ: [`RELEASE_MODEL.md`](RELEASE_MODEL.md).

Tóm tắt:
1. Merge vào internal `main`.
2. Tạo RC tag `vX.Y.Z-rc.N`.
3. Chạy local gate.
4. `npm run release:mirrors:dry` → kiểm tra.
5. `npm run release:mirrors` → cập nhật mirror.
6. Test trên Community/Pro mirror.
7. Tag `vX.Y.Z` (Community) và `pro-vX.Y.Z` (Pro).
8. Publish release; deploy từ internal tag.

### Versioning
- Core/Community: SemVer `vX.Y.Z`.
- Pro bundle: `pro-vX.Y.Z`.
- Plugin/theme: SemVer riêng trong manifest.
- Pro khai báo `requires.community: ^X.Y`.
- Cập nhật `release/compatibility.json` mỗi release.
- Tag bất biến; không force-push/re-tag.
- Không auto-update.

## 6. Free vs Pro vs theme/plugin

### Free/Community
- Core, storefront, admin, packages.
- 7 plugin free + 3 theme free.
- Catalog metadata, static feed, license verification foundation.
- Không chứa Pro admin UI/source/docs nội bộ.

### Pro
- Affiliate, premium themes, Pro modules.
- Source/docs/artifacts ở internal + Pro mirror.
- License-gated; signed artifact; no private key in repo.
- Pricing/license docs là **private**.
- `v0.1.0` từng public 2 tài liệu này. Từ `v0.1.1`, Community chỉ còn stub public:
  `docs/product/PRO_EDITION_PLAN.md` và
  `docs/operations/EXTENSION_LICENSE_ISSUANCE.md`; nội dung thật nằm trong
  internal/Pro repo.
- Không đưa giá, license issuance, key custody hoặc Pro runbook vào Community.

### Theme/plugin
- Mọi package có manifest/theme schema, version, README, tests.
- QIT bắt buộc.
- Free: phát hành trong Community release.
- Pro: phát hành trong Pro release, signed/license-gated.
- Scope store/channel bắt buộc cho bảng `ext_*`.

## 7. Definition of Done cho mỗi PR

- [ ] PR description + risk rõ ràng.
- [ ] CODEOWNERS review.
- [ ] Test local pass (hoặc ghi `NOT_PROVEN` nếu chưa chạy).
- [ ] Không có secret/key/.env/license/customer data.
- [ ] Migration có rollback và scope nếu là extension.
- [ ] API/OpenAPI cập nhật nếu đổi contract.
- [ ] CHANGELOG/docs cập nhật.
- [ ] Nếu là package: manifest version + QIT + catalog.
- [ ] Nếu ảnh hưởng Pro: compatibility + license đã kiểm tra.

## 8. Việc cần chốt sau

- GitHub Team plan để bật branch protection cho Pro.
- CI host để cài self-hosted runner.
- Teams/members thật để đổi CODEOWNERS.
- Release captain chính thức.
- Pro docs public/private chi tiết.
- Owner console DNS/TLS.

## 9. Liên hệ

- Release captain: cập nhật sau khi chốt.
- Security/license: Platform team.
- Catalog/theme/plugin: Ecosystem team.
- CI/release tooling: Quality & Release team.

## 10. Reading list cho team mới

Đã đối chiếu với GitHub thật. Không link file không tồn tại.

### Bắt buộc — ngày đầu (10 file)
1. `README.md`
2. `CONTRIBUTING.md`
3. `SECURITY.md`
4. `docs/README.md`
5. `docs/engineering/TEAM_ONBOARDING.md`
6. `docs/engineering/RELEASE_MODEL.md`
7. `docs/engineering/10_CODING_STANDARDS.md`
8. `docs/engineering/30_TEST_STRATEGY.md`
9. `docs/engineering/SELF_HOSTED_CI.md`
10. `AGENTS.md`

Product context thêm:
- `docs/product/SIRO_ECOMMERCE_PRODUCT_CRITERIA.md`
- `docs/product/PLATFORM_ROADMAP.md`
- `docs/product/LAUNCH_READINESS_SPEC.md`

### Platform
- `docs/foundation/BE_OVERVIEW.md`
- `docs/foundation/03_API_SPEC.md`
- `docs/contracts/COMMERCE_SCOPE.md`
- `docs/contracts/API_SDK_SECURITY_PERFORMANCE.md`
- `docs/contracts/CONFIGURATION_SPEC.md`
- `docs/engineering/SECURITY_NOTES.md`
- `docs/engineering/DEPLOY_SAFETY.md`
- `docs/engineering/RUNBOOK.md`
- `docs/engineering/INCIDENT_RUNBOOK.md`

### Experience
- `docs/admin/ADMIN_UI_IA_SPEC.md`
- `docs/admin/ADMIN_UI_DESIGN_SYSTEM.md`
- `docs/admin/ADMIN_UI_SCREEN_SPECS.md`
- `docs/admin/ADMIN_UI_WORKFLOWS.md`
- `docs/frontend/FE_IMPLEMENTATION_PLAN.md`
- `docs/frontend/FE_SCOPE_SWITCHING.md`
- `docs/frontend/FE_SECURITY_PRIVACY.md`
- `docs/frontend/FE_TEST_COVERAGE.md`
- `docs/frontend/FE_THEME_EXTENSION.md`
- `docs/frontend/FE_THEME_LAYOUT_SPEC.md`
- `docs/frontend/FE_ACCESSIBILITY_ACCEPTANCE.md`
- `docs/frontend/FE_PERFORMANCE_BUDGETS.md`

### Ecosystem
- `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`
- `docs/contracts/EXTENSION_PACKAGING_SPEC.md`
- `docs/contracts/EXTENSION_UI_SPEC.md`
- `docs/contracts/THEME_PACKAGE_SPEC.md` — status **P1**, không phải CURRENT
- `docs/engineering/EXTENSION_BUILD_GUIDE.md`
- `docs/engineering/EXTENSION_SCOPING.md`
- `docs/engineering/ECOSYSTEM_OPERATIONS.md`
- `docs/product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md` — status **ACTIVE**

### Quality & Release
- `docs/qa/TEST_RULES.md`
- `docs/qa/TEST_COVERAGE_MATRIX.md`
- `docs/qa/27_ACCEPTANCE_MATRIX.md`
- `docs/qa/28_FAILURE_INJECTION_PLAN.md`
- `docs/qa/UAT.md`
- `docs/engineering/CI_QUALITY.md`
- `docs/engineering/DEPLOY_SAFETY.md`
- `docs/engineering/RUNBOOK.md`
- `docs/engineering/INCIDENT_RUNBOOK.md`
- `docs/engineering/RELEASE_MODEL.md`
- `docs/engineering/SELF_HOSTED_CI.md`

### Pro (chỉ team có quyền private)
- `SiroCommerce-Pro/README.md`
- `SiroCommerce-Pro/admin/affiliate/README.md` — file chỉ tồn tại ở Pro repo,
  không có trong Community.
- `SiroCommerce-Pro/docs/PRO_EDITION_PLAN.md`
- `SiroCommerce-Pro/docs/EXTENSION_LICENSE_ISSUANCE.md`

Lưu ý: **không có** `docs/admin/affiliate/README.md` trong cả 3 repo. Không đưa
đường dẫn này vào list; TODO: viết tài liệu admin Affiliate riêng trong Pro repo
khi UI được wire lại.

