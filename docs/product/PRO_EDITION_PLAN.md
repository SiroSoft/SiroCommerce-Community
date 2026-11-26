# Kế hoạch bản Pro và Enterprise

Trạng thái: PLANNED (kế hoạch thương mại). Cập nhật: 2026-11-16.

Tài liệu này mô tả cách đóng gói SiroCommerce thành các bản thương mại mà
không phá vỡ bản Community/Core đang self-host. **Chỉ phần feature-flag
scaffolding và endpoint đọc flag là đã có trong mã nguồn.** Mọi hạng mục
license, entitlement, SSO, marketplace đóng gói, SLA và hỗ trợ trả phí dưới
đây đều là PLANNED, chưa được cam kết là đã bàn giao.

## Status update 2026-11-22

Implemented foundation: offline RSA-SHA256 license verification, extension
entitlement on `enable()`, license issuance CLI, extension packaging/QIT/slots/
scope, and private Composer discovery. Still PLANNED: commercial license
delivery/portal, marketplace billing, packaged multi-store theming, SSO/SAML,
SLA/support, managed AI quotas. Current license files are signed JSON envelopes
(RSA-SHA256), not JWT; JWT/Ed25519 remains a future option. The Pro edition is
**not GA**; do not sell it as complete.

## 1. Định vị các tier

| Tier | Đối tượng | Mô hình triển khai | Giá trị chính |
|---|---|---|---|
| Community/Core | SME, đội kỹ thuật tự vận hành, dự án nội bộ | Self-host, mã nguồn hiện tại | Toàn bộ lõi commerce đã ship: catalog, cart, checkout, order, payment, fulfillment, B2B cơ bản, B2C, CMS, báo cáo, AI self-host |
| Pro | Doanh nghiệp vừa, chuỗi 2-20 cửa hàng/chi nhánh | Self-host có license key, cập nhật định kỳ | Multi-store theming, marketplace đóng gói, B2B nâng cao, audit export, AI quota/provider managed, hỗ trợ ưu tiên |
| Enterprise | Tập đoàn, nhiều pháp nhân, yêu cầu tuân thủ | Self-host hoặc private deployment, license theo hợp đồng | SSO/SAML, white-label, SLA 99.9%, dedicated support, audit/retention nâng cao, air-gapped option |

Nguyên tắc:

- Community/Core là bản mặc định, không bị khóa tính năng đang có.
- Pro/Enterprise là **đóng gói + entitlement + hỗ trợ**, không phải fork mã nguồn.
- Không hứa tính năng chưa tồn tại. Bảng dưới đây ghi rõ HIỆN CÓ hay PLANNED.

## 2. Tính năng theo tier

Chú thích trạng thái:

- **HIỆN CÓ**: đã có mã nguồn, route/migration/test tương ứng trong repo.
- **MỘT PHẦN**: đã có nền tảng kỹ thuật nhưng chưa đủ để bán như một tính năng hoàn chỉnh.
- **PLANNED**: chưa bàn giao; chỉ là kế hoạch.

| Tính năng | Community/Core | Pro | Enterprise | Trạng thái thực tế |
|---|---|---|---|---|
| Multi-store theming | 1 theme/storefront config theo store/channel | Nhiều store, theme/token/preset theo từng store, preview/publish | Như Pro + governance nhiều thương hiệu | Nền tảng store/channel và storefront config HIỆN CÓ; đóng gói multi-store theming PLANNED |
| Marketplace / multi-vendor | Không đóng gói | Vendor, catalog visibility, commission, payout | Như Pro + chính sách tập đoàn, đối soát sâu | Một phần hạ tầng vendor/commission/payout HIỆN CÓ; gói marketplace PLANNED |
| Advanced B2B (quote/approval sâu, price lists per company) | B2B cơ bản hiện có | Quote/approval nhiều cấp, price list theo công ty, credit/contract nâng cao | Như Pro + phân quyền tập đoàn, audit liên pháp nhân | B2B core và một phần quote/approval/price list HIỆN CÓ; phần nâng cao PLANNED |
| SSO/SAML | Không | Tùy chọn add-on | SAML/OIDC, SCIM (PLANNED) | PLANNED |
| Audit exports | Audit vận hành cơ bản | Export audit có lọc, lịch sử, định kỳ | Retention dài, export theo pháp nhân, chứng từ tuân thủ | Audit/export nền tảng HIỆN CÓ; gói enterprise audit PLANNED |
| AI quotas/providers managed | AI self-host/provider tự cấu hình | Quota theo tenant, provider managed, ngân sách | Quota theo pháp nhân, chính sách provider, báo cáo chi phí | LLM gateway + budget ledger HIỆN CÓ; managed provider/quota thương mại PLANNED |
| SLA 99.9% | Không cam kết SLA | SLA theo gói hỗ trợ (nếu mua) | SLA 99.9% theo hợp đồng, credit policy | PLANNED |
| Dedicated support | Community support | Hỗ trợ ưu tiên theo giờ làm việc | Dedicated engineer/CSM, escalation 24/7 tùy hợp đồng | PLANNED |
| White-label | Giữ thương hiệu SiroCommerce | Tùy chọn add-on | White-label đầy đủ, domain/branding riêng | PLANNED |
| License key + entitlement | Không cần | Có | Có | Scaffolding flag HIỆN CÓ; license/entitlement PLANNED |
| Feature flags qua env | Có (`PRO_EDITION`, `PRO_FEATURES`) | Có | Có | HIỆN CÓ |

Lưu ý: các dòng "MỘT PHẦN" không được quảng bá là đã hoàn thành. Khi bán Pro,
phải chốt phạm vi với khách và ghi rõ phần nào dùng hạ tầng hiện có, phần nào
còn trong roadmap.

## 3. Packaging, license và giá

### 3.1 License key

- License là một JWT đã ký (khuyến nghị Ed25519/EdDSA; dự phòng RS256) chứa
  claims tối thiểu:
  - `license_id`, `customer_id`, `edition` (`pro`/`enterprise`)
  - `features` (allowlist đúng các key flag cố định)
  - `issued_at`, `not_before`, `expires_at`
  - `limits` (số store, admin user, AI quota, audit retention)
  - `kid` để xoay khóa
- Public key được nhúng trong bản phát hành; private key chỉ nằm ở hệ thống cấp
  license của nhà cung cấp.
- Chữ ký được xác minh offline, không bắt buộc gọi về server.
- Không đưa private key, license secret hay raw `PRO_FEATURES` ra endpoint,
  log, admin UI hoặc báo cáo.

### 3.2 Offline verify và air-gapped

- Chế độ online: license được tải/cập nhật định kỳ, có thể kiểm tra thu hồi.
- Chế độ offline/air-gapped: nhập file license đã ký; xác minh chữ ký + thời
  hạn bằng public key nhúng. Không phone-home.
- Grace period mặc định 30 ngày sau `expires_at` cho gia hạn chậm; hết grace
  thì các flag Pro tắt dần theo nhóm, không xóa dữ liệu và không chặn đọc dữ
  liệu cũ.
- Chống lùi đồng hồ: lưu mốc thời gian đã thấy gần nhất; nếu đồng hồ hệ thống
  lùi bất thường, áp dụng grace ngắn và ghi audit.

### 3.3 Add-on modules

Các add-on có thể bán riêng hoặc bundle:

- Multi-store theming
- Marketplace/multi-vendor
- SSO/SAML
- Enterprise audit export/retention
- AI managed quota/provider
- White-label

Mỗi add-on có flag riêng trong `PRO_FEATURES`; không bật add-on bằng cách sửa
code.

### 3.4 Giá gợi ý (chưa chốt)

Đây là dải giá tham khảo để thảo luận, không phải bảng giá cam kết:

| Gói | Giá gợi ý/năm (VND) | Giá gợi ý/năm (USD) | Phạm vi gợi ý |
|---|---|---|---|
| Pro | 24.000.000 - 60.000.000 | ~1.000 - 2.500 | 3 store, 20 admin user, 3 add-on nhẹ |
| Enterprise | 120.000.000 - 300.000.000 | ~5.000 - 12.000 | 10+ store, SSO, SLA, dedicated support |

Add-on gợi ý:

| Add-on | VND/năm | USD/năm |
|---|---|---|
| Marketplace | 12.000.000 - 30.000.000 | ~500 - 1.250 |
| SSO/SAML | 12.000.000 - 24.000.000 | ~500 - 1.000 |
| AI managed | Theo usage + phí nền 6.000.000 - 18.000.000 | ~250 - 750 + usage |
| White-label | 30.000.000 - 60.000.000 | ~1.250 - 2.500 |
| Enterprise audit | 18.000.000 - 36.000.000 | ~750 - 1.500 |

### 3.5 Trial, nâng cấp, hạ cấp

- Trial: 30 ngày, đầy đủ flag Pro nhưng giới hạn store/user/AI quota; hết hạn
  tự quay về Community, dữ liệu giữ nguyên.
- Upgrade: tính prorated theo số ngày còn lại; flag mới có hiệu lực sau khi
  license được xác minh.
- Downgrade: áp dụng ở kỳ gia hạn kế tiếp; không xóa dữ liệu, chỉ tắt quyền
  truy cập tính năng Pro. Cần cảnh báo trước về giới hạn store/user.
- Không hạ cấp tự động nếu đang trong grace period.

## 4. Kiến trúc kỹ thuật

### 4.1 Feature flags hiện có

`backend/app/Modules/Platform/Application/FeatureFlagService.php` đọc:

- `PRO_EDITION=community|pro|enterprise` (giá trị lạ quay về `community`)
- `PRO_FEATURES` danh sách key cách nhau dấu phẩy

Các key cố định:

| Key | Community | Pro/Enterprise | Ghi chú |
|---|---|---|---|
| `multi_store_theming` | false | chỉ true nếu có trong allowlist | Multi-store theming |
| `marketplace` | false | chỉ true nếu có trong allowlist | Marketplace/multi-vendor |
| `sso` | false | chỉ true nếu có trong allowlist | SSO/SAML |
| `enterprise_audit` | false | chỉ true nếu có trong allowlist | Audit export/retention nâng cao; audit cơ bản vẫn thuộc Core |
| `ai_managed` | false | chỉ true nếu có trong allowlist | AI quota/provider managed |
| `white_label` | false | chỉ true nếu có trong allowlist | White-label |

Quy tắc:

- Community **luôn** trả tất cả `false`, kể cả khi `PRO_FEATURES` có giá trị.
- Pro/Enterprise chỉ bật key vừa hợp lệ vừa nằm trong allowlist.
- Key không xác định trả `false`, không throw.
- `enabled(string $flag): bool` dùng cho backend; `all(): array` trả
  `{edition, flags}` cho admin.
- Endpoint hiện có: `GET /api/admin/features`, yêu cầu `auth:admin,owner` và
  DemoGuard, chỉ trả edition + boolean flags.

### 4.2 Entitlement service (PLANNED)

`EntitlementService` sẽ là lớp tổng hợp:

1. Đọc license đã xác minh (nếu có).
2. Hợp nhất với `FeatureFlagService`.
3. Áp dụng giới hạn số store/user/quota.
4. Trả quyết định `allowed(feature)` và lý do (`edition`, `license_expired`,
   `grace`, `quota_exceeded`, `unknown_feature`).

Không cho phép controller tự diễn giải env; mọi enforcement đi qua
entitlement để tránh lệch hành vi.

### 4.3 License verification (PLANNED)

- Verify JWT bằng public key nhúng, kiểm tra `kid`, `exp`, `nbf`, `edition`,
  `features`.
- Public key có thể xoay vòng; bản cũ vẫn đọc được license cũ trong thời hạn
  hỗ trợ.
- Sai chữ ký/hết hạn/quá grace: fail closed cho flag Pro, nhưng Core vẫn chạy.
- Ghi audit khi license được nạp, gia hạn, hết hạn hoặc bị từ chối.
- Không log toàn bộ license; chỉ log `license_id`, `customer_id`, `edition`,
  `kid`, trạng thái.

### 4.4 Tách biệt dữ liệu và khóa

- License key/private signing key không nằm trong database nghiệp vụ và không
  được backup chung với dữ liệu khách hàng.
- Dữ liệu Pro (theme nhiều store, vendor, audit export) vẫn nằm trong schema
  hiện tại nhưng có cột/khóa phân tách theo tenant/store.
- Enterprise có thể yêu cầu deployment riêng, khoá riêng, backup riêng.
- Air-gapped: license file và public key được chuyển thủ công; không phụ thuộc
  Internet.

### 4.5 Điểm enforcement (PLANNED)

- Middleware/guard cho route admin của tính năng Pro.
- Kiểm tra ở application service trước khi ghi dữ liệu.
- Background job/queue kiểm tra entitlement trước khi chạy tác vụ Pro.
- Admin UI chỉ hiển thị tính năng khi flag bật; nhưng backend vẫn là nguồn
  quyết định cuối cùng.

## 5. Roadmap 3 quý

Mỗi mốc chỉ được coi là DONE khi có đủ: test (unit/integration/feature), tài
liệu cập nhật, migration nếu có thay đổi schema, và security review cho phần
license/entitlement.

### Quý 1 - Nền tảng đóng gói

Deliverables:

- Hoàn thiện feature flag + endpoint admin (đã có scaffolding).
- `EntitlementService` đọc flag + license stub.
- License reader offline (verify chữ ký, exp, edition, features).
- Trang admin hiển thị edition/flags/trạng thái license.
- Tài liệu vận hành license, grace period, air-gapped.

Done criteria:

- Test cho community/pro/enterprise, unknown flag, license hết hạn, grace.
- `php -l`, targeted + full PHPUnit pass.
- OpenAPI/SDK cập nhật cho endpoint mới.
- Security review: không lộ key/license/raw allowlist.

### Quý 2 - Pro packaging

Deliverables:

- Multi-store theming đóng gói Pro.
- Advanced B2B nâng cao (quote/approval sâu, price list theo công ty).
- Enterprise audit export phiên bản Pro.
- AI quota/provider managed ở mức Pro.
- Quy trình cấp license, gia hạn, trial 30 ngày.

Done criteria:

- Migration + rollback an toàn; dữ liệu cũ không mất khi tắt flag.
- Test end-to-end cho upgrade/downgrade/trial.
- Tài liệu bán hàng ghi rõ tính năng HIỆN CÓ và PLANNED.
- Security review cho export/audit và phân quyền tenant/store.

### Quý 3 - Enterprise

Deliverables:

- SSO/SAML, tùy chọn SCIM.
- White-label.
- Marketplace/multi-vendor đóng gói.
- SLA 99.9% + quy trình dedicated support.
- Chế độ air-gapped hoàn chỉnh.

Done criteria:

- Test tích hợp SSO (mock IdP), white-label, marketplace.
- Diễn tập incident/SLA và escalation.
- Security review toàn bộ license/air-gapped.
- Tài liệu backward compatibility và kế hoạch rollback.

## 6. Rủi ro và chống lạm dụng

| Rủi ro | Tác động | Giảm thiểu |
|---|---|---|
| Self-host bypass bằng cách sửa code | Mất doanh thu license | Chấp nhận giới hạn của self-host; dùng license + audit + hỗ trợ/updates làm giá trị; không tuyên bố chống bypass tuyệt đối |
| Chia sẻ license key | Dùng vượt số store/user | Ràng buộc `customer_id`, `limits`, fingerprint deployment; kiểm tra định kỳ khi online; grace/audit khi offline |
| Lộ public key/private key | Giả license | Private key tách biệt, xoay `kid`, thu hồi license, ký offline trong HS M |
| Fork mã nguồn | Mất kiểm soát chất lượng | Giấy phép rõ ràng; ưu tiên đóng gói add-on và dịch vụ thay vì chỉ khóa code |
| Tắt flag làm mất dữ liệu | Sự cố khách hàng | Flag chỉ chặn truy cập, không xóa dữ liệu; downgrade ở kỳ gia hạn; backup trước migration |
| Lỗi license chặn Core | Mất doanh thu/uy tín | Fail closed chỉ cho Pro; Core luôn chạy; grace 30 ngày; kill switch nội bộ |
| Hỗ trợ quá tải | Chi phí tăng | Phân tier hỗ trợ, SLA theo hợp đồng, tài liệu self-service, giới hạn kênh |
| Backward compatibility | Khách không nâng cấp | Semver, deprecation policy, test hồi quy, không phá API v1 |

Tuyên bố thẳng thắn: với self-host, không thể chống bypass tuyệt đối. Giá trị
Pro/Enterprise phải đến từ tính năng đóng gói, cập nhật, hỗ trợ, SLA và dịch
vụ, không chỉ từ việc giấu code.

## 7. Hỗ trợ và tương thích

- Community: tài liệu + cộng đồng, không SLA.
- Pro: hỗ trợ trong giờ làm việc, phản hồi theo mức ưu tiên, cập nhật bản vá.
- Enterprise: dedicated support, escalation, SLA 99.9% theo hợp đồng.
- API v1 giữ nguyên nguyên tắc additive-only; thay đổi phá vỡ phải qua v2 và
  deprecation có thời hạn.
- Flag Pro không được làm thay đổi hành vi mặc định của Community.

## 8. Tiêu chí go/no-go

Chỉ mở bán một gói khi:

1. Tính năng trong gói đã có test và tài liệu.
2. License/entitlement fail closed đúng phạm vi, không ảnh hưởng Core.
3. Không lộ secret trong API/log/admin.
4. Có quy trình upgrade/downgrade/trial và rollback.
5. Có owner hỗ trợ và cam kết SLA tương ứng.
6. Bảng "HIỆN CÓ vs PLANNED" được rà soát trước khi gửi khách.
