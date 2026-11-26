> LEGACY (historical upstream issue log; verify against current `sirosoft/core` before reuse). See [docs/README.md](../README.md).

# Báo framework (sirosoft/core)

2026-10-10: core đã lên v1.3.1 — issues 1–4 và 6 bên dưới đã được upstream fix
(đối chiếu CHANGELOG v1.1.0), issue 5 fix một phần. Issues 7–9 là phát hiện mới
trong đợt upgrade 1.0.14 → 1.3.1, đã file trực tiếp:
#97 (identity-map ghost), #98 (Env precedence), #99 (VERSION stale).

Mỗi issue gồm: hiện tượng (repro), tác động, đề xuất fix. Gửi nguyên văn được.

---

## Issue 1 — `Event::off($event)` gỡ toàn bộ listener, kể cả của core

**Hiện tượng:** `Event::off('payment.captured')` gọi `removeListeners()` unset hết
`$listeners[$event]`. Module nào `shutdown()` kiểu này sẽ gỡ luôn listener
analytics/marketing-automation của core (`CommerceEventListeners`).

**Repro:**
```php
Event::on('payment.captured', $coreListener);
Event::on('payment.captured', $moduleListener);
Event::off('payment.captured'); // cả 2 đều mất
```

**Tác động:** High. Mọi module có lifecycle đều có thể vô tình tắt core.

**Đề xuất:** thêm `Event::off($event, $callback = null)` — có callback thì chỉ gỡ
đúng listener đó (so sánh `===`), không callback giữ hành vi cũ nhưng ghi log
deprecated. Hoặc trả handle từ `on()` để `off()` theo handle.

> ✅ FIXED upstream v1.1.0: `Event::on()/once()` trả int handle, `off($event,
> $handle|$callable)` gỡ đúng listener. Không cần gửi nữa.

---

## Issue 2 — `php://input` read-once + static body cache dễ bể dưới runtime dài

**Hiện tượng:** `Request::fromGlobals()` đọc `php://input` 1 lần rồi cache vào
static `$rawBodyCache`. Dưới `php -S`/worker dài, static sống qua requests;
code nào đọc input trước `fromGlobals()` (debug/log) là request mất body mà
không báo lỗi — chỉ thấy validate "field is required".

**Tác động:** Medium-High. Lỗi âm thầm, khó debug (đã mất nửa buổi mới loại trừ).

**Đề xuất:** core cung cấp request-body accessor duy nhất (đọc 1 lần, cache có
reset theo request lifecycle) + document "không đọc `php://input` trực tiếp".
Thêm `Request::resetCache()` và gọi trong front controller sau mỗi request.

> ✅ FIXED upstream v1.1.0: `Request::rawBody()` read-once-safe +
> `Request::resetCache()`, `App::run()` reset per request (FrankenPHP/FPM
> safe). Không cần gửi nữa.

---

## Issue 3 — Middleware params variadic, không typed

**Hiện tượng:** `'auth:admin,owner'`, `'throttle:20,1'` parse thành string rồi
spread vào `handle($request, $next, ...$params)` — sai type chỉ vỡ lúc runtime,
không bắt được bằng static analysis.

**Tác động:** Medium. Route mới dễ typo (`throttle:abc,1` → behavior lạ).

**Đề xuất:** cho middleware khai báo signature params (vd attribute hoặc
`static function params(): array`) để Router validate lúc register routes;
sai thì throw ngay khi boot thay vì lúc request chạy.

> ✅ FIXED upstream v1.1.0: Router validate middleware string params qua
> reflection (`throttle:abc,1` giờ throw rõ ràng). Không cần gửi nữa.

---

## Issue 4 — Thiếu Mail contract

**Hiện tượng:** core không có khái niệm gửi mail. App phải tự viết SMTP thô
(đã làm ở Siro EC: STARTTLS/AUTH, header-injection guard, fail-safe).

**Tác động:** Medium. Mỗi app viết lại 1 mailer na ná nhau, chất lượng khác nhau.

**Đề xuất:** thêm `MailProvider` interface (`send(to, subject, text, html?) :
bool`, never-throw) + `NullMailProvider` + 1 SMTP impl chuẩn trong core hoặc
package riêng. App chỉ cấu hình env.

> ✅ FIXED upstream v1.1.0: `Siro\Core\Mail\MailProvider` + `SmtpMailProvider`
> + `NullMailProvider`, `Mail::setProvider()`, `MAIL_PROVIDER=smtp|null`,
> never-throw `Mail::trySend()`. SiroCommerce đang adopt (Mailer thành facade
> qua core, đang làm). Không cần gửi nữa.

---

## Issue 5 — Static registries thiếu `reset()` đồng bộ cho test/long-run

**Hiện tượng:** 1 số registry đã có `reset()` (tốt), 1 số chưa — Router aliases,
middleware priority, Env cache. Test/process dài bị leak state qua cases.

**Tác động:** Medium. Gây flake test kiểu "chạy lẻ xanh, chạy full đỏ".

**Đề xuất:** rà soát mọi static trong core, bổ sung `reset()` còn thiếu +
document trong testing guide: test nào chạm global phải reset ở tearDown.

> 🟡 PARTLY FIXED v1.1.0 (Router/Route/Validator/Metrics/VersionMiddleware
> resets) + Gate/Mail resets. Chưa gửi — còn thiếu thì báo tiếp theo dạng
> issue 8 (Env precedence).

---

## Issue 6 — Error message khó đoán ở vài điểm

**Hiện tượng (gặp thực tế):**
- Body JSON invalid → validate báo "Email is required" (không hề nói body hỏng).
- Throttle không Redis → 429 "Rate limiter backend unavailable" (đúng nhưng
  dev tưởng bị rate-limit thật).
- Version mismatch extension → message tốt (giữ nguyên làm mẫu).

**Tác động:** Low-Medium (DX, thời gian debug).

**Đề xuất:** body parse fail → 400 với `malformed_body` thay vì để validate báo
thiếu field; throttle fallback message phân biệt "backend unavailable (fail
closed)" vs "limit exceeded".

> ✅ FIXED upstream v1.1.0: `MalformedBodyException` (400 `malformed_body`),
> throttle fail-closed 503 `throttle_backend_unavailable`. Không cần gửi nữa.

---

## Issue 7 — Identity map giữ instance chưa commit sau rollback (đã file #97)

https://github.com/SiroSoft/siro-core/issues/97

**Hiện tượng:** từ v1.1, `Model::save()` đăng ký instance vào identity map;
transaction rollback sau đó không evict → `find()` trả về ghost row thay vì
null. Kèm theo: instance trong map là bản partial (thiếu key → đọc null trong
khi hydrated read ra DB default 0), làm guard so sánh strict (immutability
của Invoice) báo diff ảo `minor_unit: null vs 0`.

**Tác động:** High. Phát hiện khi upgrade SiroCommerce lên 1.3.1 (5 test đỏ,
2 case root-cause tại đây).

**Đề xuất:** evict/refresh entries của transaction bị rollback; document
staleness contract. Workaround downstream: `clearIdentityMap()` trong catch.

---

## Issue 8 — `Env::get()` ưu tiên `$_ENV`, dễ bị stale shadow (đã file #98)

https://github.com/SiroSoft/siro-core/issues/98

**Hiện tượng:** từ v1.3, `Env::get()` check `$_ENV` trước `getenv()`.
`putenv('APP_ENV=production')` bị `$_ENV['APP_ENV']='testing'` còn sót shadow
— lặng lẽ, gây failures phụ thuộc thứ tự chạy ("lẻ xanh, full đỏ").

**Tác động:** Medium-High. Trên FrankenPHP/FPM `$_ENV` và real env có thể
lệch — đổi precedence mà changelog không nhấn mạnh.

**Đề xuất:** document precedence trong upgrade notes; helper swap hermetic
(set + restore CẢ 2 kênh) trong `docs/TESTING.md`; cân nhắc warn khi 2 kênh
lệch nhau.

---

## Issue 9 — `Console::VERSION` stale ở tag v1.3.1 (đã file #99)

https://github.com/SiroSoft/siro-core/issues/99

**Hiện tượng:** tag v1.3.1 nhưng `Console::VERSION` vẫn `'1.3.0'` — đúng lỗi
từng fix ở v1.0.6 (PR #86, "sync version strings with tag"). Live
`/api/version` báo core 1.3.0 trong khi chạy code 1.3.1.

**Tác động:** Low-Medium. Version surface không đáng tin khi debug.

**Đề xuất:** CI gate `Console::VERSION === git tag` trên tag builds, hoặc
derive constant từ tag lúc build.
