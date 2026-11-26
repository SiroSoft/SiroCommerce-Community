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
- Pricing/license docs mặc định **private**.

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
