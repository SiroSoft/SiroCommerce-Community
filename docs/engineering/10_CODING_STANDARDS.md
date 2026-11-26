# 10 — Coding Standards (BẮT BUỘC, audit theo checklist)

Kế thừa: `siro-core/.cursorrules` + `siro-sales-inventory/backend|frontend/AGENTS.md`. Dưới đây là bản rút gọn áp cho SiroCommerce — vi phạm = sửa ngay.

## A. PHP Backend
1. `declare(strict_types=1)` mọi file; class `final` (trừ abstract); typed params/returns; `===`, `match()`, `str_contains/starts_with/ends_with`; readonly DI: `__construct(private readonly X $x)`.
2. Kiến trúc **Controller → Service → Repository → Model**:
   - Controller: HTTP only (validate → gọi Service → Response). Cấm DB/logic. Cấm inject Repository (`grep use App\Repositories app/Controllers` = 0).
   - Service: nghiệp vụ + transaction + event. Chỉ nói chuyện với Repository qua constructor DI. Cấm SQL trong Service. Constructor cấm default `= new Xxx()`.
   - Repository: chỉ dùng Model statics (`Order::query()`); cấm `DB::table` ngoài Models; raw SQL duy nhất cho atomic update/self-join via `Database::execute()` + binding, driver-aware.
   - Model: nơi DUY NHẤT chạm bảng; `$fillable` đầy đủ; money INT minor.
3. State chuyển qua transition class, cấm `update(['state'])` lẻ.
4. Validation: mọi write có `->validate()`; service update có allowlist `array_intersect_key`; relation keys extract trước intersect; sort qua allowlist; LIKE binding.
5. Migration là schema thật duy nhất; sửa cột → migration mới; đối chiếu tên cột với migration (bài học `lot_no` vs `lot_number`).
6. Response envelope chuẩn Siro; webhook giữ raw body để verify HMAC.
7. Commit: `feat|fix|refactor|perf|test|docs|chore|security(scope): mô tả EN`.

## B. Frontend (Next.js 16.3.5)
1. TS `strict`, cấm `any` (dùng `unknown` + guard); ưu tiên `interface`; `import type`; `as const` query keys; `satisfies` thay `as`.
2. Cấu trúc: `app/ → components/ → hooks/services (TanStack Query) → api-client`; client state Zustand; form rhf+zod; URL state cho page/filter.
3. Component ≤300 dòng, page ≤400 dòng, function ≤60 dòng (lý tưởng 10–30). `"use client"` chỉ khi cần hooks/browser. Default export chỉ cho page/layout.
4. Query key factory 1/domain; mutation invalidate đúng key; unwrap envelope `unwrapData/unwrapList`; interceptor auto-refresh 401 + retry queue.
5. Form: schema Zod ở `lib/schemas`, `z.infer` type, `z.coerce.number()`, map field-error server qua `setError`, không chỉ toast.
6. i18n 4 locales (en/vi/ja/de): key `module.section.key`, cấm hardcode; `npm run i18n:check` phải xanh.
7. Styling: `cn()` merge class, shadcn primitives, mobile-first, dark-mode không flash, a11y (aria-label icon-button, focus trap modal).
8. Money hiển thị qua `formatCurrency` (VND 0 decimals); tiền tính toán ở BE bằng minor units.

## C. Static gates (chạy trước mọi PR)
BE: `php -l`, PHPStan max `No errors`, `composer audit` 0 advisory, PSR-12.
FE: `tsc`, `npm run lint`, `npm test` (vitest), `npm run build`, `i18n:check`.
