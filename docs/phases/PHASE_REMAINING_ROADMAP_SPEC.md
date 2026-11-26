# Remaining Roadmap — Spec v0.1 (5 tracks, no fork)

> Baseline: `README.md` + `docs/contracts/STOREFRONT_THEME_VERTICAL_SPEC.md` + `PHASE_V7_PRODUCTION_PROOF.md` + current `main` 4fc0492 (form builder + proof panel + validator allowlist).
> Nguyên tắc: presentation-only, backend là truth cho price/stock/payment, không sửa core app khi thêm vertical/theme, không HTML/CSS/JS tùy ý trong API.

## 0) Tổng quan 5 tracks

| # | Track | Mục tiêu ship | Ước lượng |
|---|-------|---------------|-----------|
| A | Proof persistence | `bank_transfer` receipt gắn vào `payments/orders/invoices evidence`, admin capture đọc proof thay vì gõ lại, audit | 1–1.5d |
| B | Coupon pro sâu | bulk CSV + pattern, server search, version diff, per-coupon analytics, retention + expire `reserved` | 2d |
| C | Theme seasonal E2E | seed `seasonal` + preset + gallery, Admin publish → `GET /api/storefront/config` → `resolveTheme` → CSS | 0.5d |
| D | CI/CD auto-deploy live | secrets + PAYMENT_MODE=production, Stripe/DHL live, health gate | 0.5d |
| E | Booking + non-goals V1 | spec `booking` product type (slots/capacity/date-aware pricing) + marketplace/POS/ERP out of V1 | 1d spec, 3d build nếu duyệt |

---

## A) Proof persistence for bank_transfer (và COD)

### Goal
Receipt không còn local/ObjectURL rời rạc. Sau khi customer submit proof, backend lưu evidence gắn với `payment_id/order_id` và hiển thị ở Admin capture + `GET /evidence`.

### Non-goals
Không thay flow `cod` (capture = xác nhận thu tiền). Không lưu PII thẻ. Không thay `/api/settings`.

### Ownership
Backend: migration + proof store + evidence endpoint. Frontend: storefront submit proof, admin capture đọc proof.

### Persistence (additive)
```sql
-- payments already has provider, state, provider_ref
ALTER TABLE payments ADD COLUMN proof_reference VARCHAR(128) NULL AFTER provider_ref;
ALTER TABLE payments ADD COLUMN proof_url VARCHAR(512) NULL AFTER proof_reference;
ALTER TABLE payments ADD COLUMN proof_uploaded_at DATETIME NULL;
CREATE INDEX idx_payments_proof ON payments(proof_uploaded_at);

-- evidence audit (append-only, like invoice_evidence)
CREATE TABLE payment_proofs (
  id VARCHAR(26) PRIMARY KEY,
  payment_id VARCHAR(26) NOT NULL INDEX,
  order_id VARCHAR(26) NOT NULL INDEX,
  reference VARCHAR(128) NOT NULL,
  file_url VARCHAR(512) NULL,
  file_hash CHAR(64) NULL,
  uploaded_by VARCHAR(26) NULL, -- customer_id or staff_id
  channel VARCHAR(16) NOT NULL DEFAULT 'storefront', -- storefront|admin
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- extends invoice_evidence projection to include bank proof when present
```
Không sửa `settings`. `proof_reference/url` sanitize `safeText/safeUrl`, max len như trên, reject `javascript:data:<script`.

### Contracts

**Storefront submit proof (customer, guest via X-Order-Token hoặc auth):**
```
POST /api/v2/payments/{id}/proof
Auth: auth OR X-Order-Token (guest của order đó)
Body: { reference: string(3-128, /^[A-Za-z0-9 _-]{3,128}$/), file_url?: string(<=512, http/https or /uploads/...), file_hash?: string(64 hex) }
Headers: Idempotency-Key: ^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$
200: { payment: { id, proof_reference, proof_url, proof_uploaded_at }, evidence: { reference, file_url } }
409: stale idempotency, 422: invalid reference/url, 403: không sở hữu order
```
Upload file trước:
```
POST /api/upload  (đã có, auth)
→ { url: "/uploads/.../receipt-....jpg" }
```
Storefront có thể gọi proof ngay cả khi guest (kèm `X-Order-Token`).

**Admin capture (đã có) — mở rộng để đọc proof:**
```
POST /api/v2/payments/{id}/capture
Body hiện: { amount_minor?, currency? }  // đã có
Thêm optional: { proof_reference?: string, proof_url?: string }
Behavior: nếu payment.provider=bank_transfer và proof chưa có, yêu cầu proof_reference (422 nếu thiếu khi capture lần đầu). Ghi audit + cập nhật payments.proof_*
GET /api/v2/payments/{id}/status  → trả thêm proof_reference, proof_url (redact file_hash)
GET /api/admin/invoices/{id}/evidence → include bank_proof block khi tồn tại
```

### Validation
- reference: 3–128, alnum + space/_/-, trim, không rỗng, không `<script`.
- file_url: nếu có phải http/https hoặc `/uploads/...` (pass `safeUrl` + length), hash 64 hex nếu gửi.
- Idempotency: `payment_proofs.idempotency_key` unique per payment+reference (tránh double upload).
- Scope: chỉ owner của order (customer_id khớp hoặc guest token hợp lệ) mới được POST proof; staff xem proof chỉ khi có `cap:finance` hoặc owner.

### Flows
```
Storefront order-result/detail (bank_transfer + authorized/pending):
  [ref input] + [file picker → POST /api/upload → url] → POST /api/v2/payments/{payment_id}/proof {reference, file_url} (Idempotency-Key: proof-{payment_id}-{hash(ref)})
  → hiển thị "Proof submitted · Ref FT123 · waiting verification"
  → order timeline thêm event proof_uploaded
Admin Finance → PaymentReconciliation:
  Load payment → thấy proof_reference/url + preview ảnh nếu file_url là image
  Capture với proof_reference (prefill từ storefront proof, có thể sửa) → POST /capture {proof_reference}
  → audit payment_proofs + payments.state=captured
```

### Tests
- Backend: guest proof với X-Order-Token 200, sai owner 403, invalid ref/url 422, idempotent replay 200 same result, admin capture thiếu proof cho bank_transfer 422, capture với proof 200 và state captured.
- Storefront: order-result proof panel điều kiện hiển thị cho bank_transfer, upload file mock → preview, submit → notice.
- Admin: finance page test load payment → capture với proof → badge captured.

### Rollout
1. Migration + PaymentProofStore + endpoint + OrderResource include proof + audit
2. Storefront PurchaseFlow proof panel gọi thật (thay local URL.createObjectURL mock)
3. Admin capture prefill proof
4. Seed 1 bank_transfer order demo + proof

---

## B) Coupon pro sâu

### Goal
Quản lý coupon như pro marketing tool: bulk, search, version, analytics, retention, auto-expire — không phải JSON thủ công.

### Non-goals
Không sửa pricing engine core, không thêm `buy_x_get_y` engine trong track này (để riêng), không marketplace coupon.

### Persistence (additive)
```sql
ALTER TABLE coupons ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER state;
ALTER TABLE coupons ADD COLUMN updated_by VARCHAR(26) NULL AFTER version;
CREATE TABLE coupon_versions (
  id VARCHAR(26) PRIMARY KEY,
  coupon_id VARCHAR(26) NOT NULL INDEX,
  version INT NOT NULL,
  code VARCHAR(64) NOT NULL,
  rules TEXT NOT NULL,
  actor_id VARCHAR(26) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE(coupon_id, version)
);
CREATE TABLE coupon_bulk_jobs (
  id VARCHAR(26) PRIMARY KEY,
  actor_id VARCHAR(26) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending/processing/done/failed
  pattern VARCHAR(64) NOT NULL, -- e.g. WELCOME{0000}
  count INT NOT NULL,
  rules TEXT NOT NULL,
  result_json TEXT NULL,
  created_at DATETIME NOT NULL
);
-- retention: decision audit đã có, thêm job xóa cũ
```
`coupons.code` unique giữ nguyên. `rules` vẫn TEXT JSON. `per_customer_limit` đã support ở PromotionService.

### Contracts (admin, auth:admin,owner,marketer, cap:promotions)

```
GET /api/admin/coupons?q=&state=&page=&per_page=&sort=created_at
→ đã có q/state/page/per_page (B track trước) → chuẩn hóa trả { data: Coupon[], meta: {total,page,per_page} }

GET /api/admin/coupons/{id}
GET /api/admin/coupons/{id}/versions
GET /api/admin/coupons/{id}/versions/{version}/diff?other=2
→ { coupon, versions: CouponVersion[], diff: { rules: { added, removed } } }

PUT /api/admin/coupons/{id}  (đã có) → tạo version mới, bump coupons.version

POST /api/admin/coupons/bulk
Body: { pattern: "WELCOME{0000}" (đúng 1 placeholder {0+} zeros), count: 1-1000, start: 0, rules: string(JSON), prefix?: string }
Headers: Idempotency-Key
→ 202 { job_id, status: pending }
GET /api/admin/coupons/bulk/{job_id}
→ polling job (như exports), khi done result_json = { created: [codes], failed: [] }

GET /api/admin/coupons/{id}/usage
→ { coupon_id, total_committed, total_reserved, total_rolled_back, per_customer: [{customer_id, count}], recent_orders: [...] }
GET /api/admin/coupons/segments?q=  → proxy PromotionSegmentMember search (server search, không load all)
GET /api/admin/coupons/products?q=   → proxy ProductRepository search for product_ids picker

POST /api/admin/coupons/{id}/disable (đã có)
POST /api/admin/coupons/{id}/enable  (new) → state active

Scheduled:
- artisan promotion:expire-reserved --ttl=900  (đánh dấu reserved > 15m chưa commit → rolled_back)
- artisan promotion:prune-audits --days=90  (giữ 90d, export trước khi xóa)
```

### Pattern `WELCOME{0000}`
- `{0+}` placeholder duy nhất, zeros quyết padding. Ví dụ `WELCOME{0000}` + start 1, count 3 → `WELCOME0001, WELCOME0002, WELCOME0003`
- Validate pattern `^[A-Za-z0-9_-]+\{0+\}[A-Za-z0-9_-]*$` và length 3–64 sau khi fill.
- Atomic: transaction + unique code check; fail 1 code không rollback các code đã tạo (trả failed list).

### Validation mở rộng
- `pattern` như trên, `count 1-1000`, `rules` vẫn qua `CouponController::validateRules` (type/value/min/max/per_customer/scope/dates/rate).
- `segment/product picker` server search có auth, không leak ngoài scope.

### Admin UI (commerce/page.tsx PromotionsTab đã có form builder)
- Giữ form hiện tại, thêm: Code pattern toggle (single ↔ bulk), bulk fields pattern/count/start, search pickers cho `product_ids` (typeahead gọi `/api/admin/coupons/products?q=`), `segment_ids` (gọi `/api/admin/coupons/segments?q=`), table Coupons có sort, hành động View versions / Diff / Usage / Disable/Enable, bulk job progress bar như `exports`.
- Audit tab + per-coupon usage panel (total + per_customer table).

### Tests
- Backend: bulk pattern 0000 → 3 codes padding đúng, duplicate pattern 422, bulk job 202→poll done, versions tạo sau PUT, diff đúng, usage counts đúng sau checkout commit/rollback, expire-reserved job rollback sau 15m, prune giữ 90d.
- Admin: form builder + bulk flow, segment/product picker search không gọi khi chưa gõ, usage analytics render.

### Rollout
1. Migration coupons.version + tables + jobs
2. Endpoints bulk/versions/usage/segments/products/enable + validators + scheduled
3. Admin UI bulk + pickers + versions diff + usage
4. Retention cron + docs

---

## C) Theme seasonal E2E (no fork)

### Goal
`seasonal` cho phép publish qua Admin như `editorial/general/noir/forest` và hiển thị thật qua `GET /api/storefront/config` → `resolveTheme` → `ThemeProvider(themeCss)` → `globals.css`.

### Đã có
- `StorefrontConfigValidator.php:22` đã allow `seasonal` + regex future themes, presentation-only checks còn.
- `storefront/src/theme/presets.ts:seasonal` (hay sẽ tạo) + `types.ts:extends`.

### Còn thiếu
- DB seed `storefront_themes` cho `seasonal` (và `noir/forest` nếu chưa), và gallery preset cho vertical mới nếu cần.

### Persistence
```sql
-- seed via migration (idempotent insert)
INSERT INTO storefront_themes (id, key, name, version, schema_version, status, token_schema_json, allowed_section_types_json, defaults_json, created_at, updated_at)
VALUES ('thm_seasonal', 'seasonal', 'Seasonal', '1.0.0', 1, 'active', '{}', '[]', '{"brand":{"name":"Seasonal"},"tokens":{"colors":{"ink":"#1A211D","muted":"#5F6D61","paper":"#F6F5EF","surface":"#FFFEFA","accent":"#7A3B2E","accent_dark":"#5E2E24"}}}', NOW(), NOW())
ON DUPLICATE KEY UPDATE status='active';
```
- `storefront_vertical_presets` không cần thêm nếu seasonal là theme, không phải vertical. Gallery: thêm preset `seasonal` trong `src/verticals/presets.ts` nếu cần khác layout (ví dụ `homepage.preferred_sections` thêm `trust_strip`).

### Contracts
- `GET /api/admin/storefront/themes` → include `seasonal`
- `PUT /api/admin/storefront/config { theme_key: seasonal, vertical_key: general, ... expected_version }` (đã có, với `Idempotency-Key`) → publish → `GET /api/storefront/config` với `X-Store-Id` trả `theme.key=seasonal`
- Storefront `sanitizeStorefrontConfig` → `resolveTheme({key:seasonal,...}, registry)` → fallback parent nếu missing.
- Không HTML/CSS tùy ý: chỉ `tokens.colors /^#[0-9A-F]{6}$/`, `radius/card` allowlist, XSS `javascript|data|<script` block đã có.

### Tests
- Backend: `PUT` seasonal với valid `expected_version` 200, publish → `GET` public trả `seasonal`, invalid `<script` 422, wrong vertical 422.
- Storefront: `theme-config.test.ts` đã có inheritance test; thêm `seasonal extends forest` case.
- Admin e2e `live-storefront-theme.spec.ts` thêm seasonal publish flow.

### Rollout
1. Migration seed `storefront_themes` (và `presets.ts` entry nếu cần)
2. Smoke: Admin publish seasonal → storefront reload thấy `--theme-accent` đổi, `background: var(--theme-paper)` không vỡ
3. Docs: `STOREFRONT_THEME_VERTICAL_SPEC.md` child-theme example đã có seasonal

---

## D) CI/CD auto-deploy live (secrets + health gate)

### Goal
Push `main` tự động release tới `<deploy-host>:22` `/opt/sirocommerce` với health gate ` /health/live, /health/ready, /health/providers` và rollback.

### Đã có
- `.github/workflows/ci.yml` (backend/storefront/admin typecheck/tests/builds)
- `.github/workflows/deploy.yml` (tarball → SCP → `deploy/release.sh`)
- `deploy/release.sh` (extract → link .env → `docker compose up -d --build` → `php siro migrate` → switch `current` → nginx `FE_SHARED_SECRET` substitute → health checks → rollback → prune)
- `docs/operations/CI_CD_RUNBOOK.md` liệt kê 4 secrets

### Còn thiếu (cần làm ngay, không code ngoài secrets)
```
GitHub → Settings → Secrets and variables → Actions:
DEPLOY_HOST=<deploy-host>
DEPLOY_PORT=22
DEPLOY_USER=<deploy-user>
DEPLOY_SSH_KEY=<private OpenSSH, public đã ở authorized_keys>
```
Trên server `/opt/sirocommerce/.env` cần:
```
APP_ENV=production
PAYMENT_MODE=production
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
PAYPAL_CLIENT_ID=..., PAYPAL_SECRET=...
DHL_API_URL=https://api.dhl..., DHL_API_KEY=..., DHL_API_SECRET=...
FE_SHARED_SECRET=<random 32+ hex, đồng bộ với nginx-ecommerce.conf>
TRANSACTIONAL_MESSAGE_MODE=production
```
Health gate mở rộng: `ProviderReadiness` đã tách `release_ready` vs `providers`; deploy chỉ fail nếu `service readiness` fail, còn `providers` missing thì `release_ready: false` nhưng không rollback (đã làm).

### Validation
- Push `main` → Actions CI xanh → Deploy job SSH thành công → server `releases/<TS>`, symlink `current`, `curl https://ecommerce.sirophp.com/health/ready` 200, `/health/providers` liệt kê missing nếu chưa cấu hình.
- Manual fallback trong runbook vẫn dùng được khi secrets chưa có.

### Rollout
1. Add 4 GitHub secrets
2. Đặt `.env` production trên server (1 lần)
3. Push `main` → verify Actions log "is live" + `curl` health
4. Giữ `deploy/release.sh --dry-run` cho rehearsal khi chưa có secrets

---

## E) Booking & non-goals V1 (spec trước build)

### Goal (spec)
Định nghĩa `booking` như product type mới, không làm vỡ catalog/cart/quote/checkout contracts hiện tại.

### Non-goals V1 (giữ nguyên)
Marketplace multi-vendor advance, POS, CRM, kế toán/ERP, multi-warehouse advance, subscription engine complex, tax engine complex, page builder/CMS (đã có), hàng trăm gateway.

### Booking product type — spec v0.1

**Catalog:**
- `product.type=booking`, `variant.type=booking`
- Field `delivery.mode=scheduled`, `booking: { duration_minutes: 30-480, capacity: 1-100, slots: [{ start: ISO8601, end: ISO8601, capacity, price_minor? }] }`
- Validation: `slots` sorted, không overlap trên 1 variant, `start < end`, `capacity ≤ variant capacity`, `price_minor` nếu có phải >=0 và cùng currency.

**Cart/quote:**
- Add line yêu cầu `slot_start` (ISO) khớp 1 slot còn capacity, `qty` 1..capacity còn lại. `CartRepository.primaryImages` giữ, `CartService.addLine` kiểm `slot capacity - reserved`.
- Quote: `slot_price_minor` từ slot hoặc variant `price_minor`, không cho coupon `item` nếu scope mismatch (giữ rule hiện tại).

**Checkout/order:**
- `PlaceOrderService` reserve `booking_slot_reservations` (bảng mới `booking_reservations: id, variant_id, slot_start, slot_end, qty, order_id, state reserved/committed/rolled_back`), commit/rollback cùng inventory flow.
- Payment: booking có thể `bank_transfer/cod` như thường; không yêu cầu gateway mới.

**Admin:**
- `GET/POST /api/admin/products/{id}/booking-slots` quản lý slots, bulk import CSV, calendar view.

**Persistence (khi duyệt build):**
```sql
CREATE TABLE booking_slots (
  id VARCHAR(26) PRIMARY KEY,
  variant_id VARCHAR(26) NOT NULL INDEX,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  capacity INT NOT NULL,
  price_minor INT NULL,
  currency CHAR(3) NULL,
  INDEX(variant_id, starts_at)
);
CREATE TABLE booking_reservations (
  id VARCHAR(26) PRIMARY KEY,
  variant_id VARCHAR(26) NOT NULL,
  slot_start DATETIME NOT NULL,
  qty INT NOT NULL,
  order_id VARCHAR(26) NOT NULL INDEX,
  state VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL
);
```

**Contracts:**
- `GET /api/products/{slug}` trả `booking` block khi type=booking
- `POST /api/carts/{id}/lines { variant_id, qty, slot_start }` cho booking, 422 nếu slot full/không tồn tại
- `GET /api/v2/products/{id}/booking-quote?slot_start=` preview giá.

**Tests (khi build):**
- Overlap slots 422, add line quá capacity 422, quote đúng slot price, concurrent reserve (2 carts cùng slot) chỉ 1 thắng, order rollback giải phóng slot.

### Rollout booking
Spec → review → migration + `ProductTypeDefinition` + Cart/Order adapters + admin slots UI + e2e `booking.spec.ts` (3 browsers).

---

## Acceptance chung

- Mỗi track có migration additive, không sửa `settings`, không HTML/CSS injection, contracts trong `docs/contracts/03_API_SPEC.md` và OpenAPI.
- Lệnh kiểm tra: `composer test --working-dir backend`, `composer check:api-contract --working-dir backend`, `npm run typecheck/test/build --workspace apps/storefront`, `npm run test:e2e --workspace apps/storefront`.
- Đã giữ 101 vars legacy → `--theme-*` bridge ở `globals.css` (không hardcode màu trong component mới).

## Thứ tự làm đề xuất

A (proof) → C (seasonal E2E, nhanh, unblocks theme gallery) → B (coupon sâu) → D (secrets live, song song) → E (booking spec → build nếu duyệt)
