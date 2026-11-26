# Test Rules R1-R9

## R1 Contract
- POST/PUT/DELETE must send Idempotency-Key ^[A-Za-z0-9._:-]{1,128}$, GET must not. 422 returns fieldErrors.

## R2 Validate
- FE validate = BE validate: coupon ^[A-Za-z0-9_-]{3,64}$, slot capacity 1-1000, request_path ^/[a-z0-9/_\-]{1,200}$, XSS block.

## R3 Scope
- Every api.* sends X-Store-Id/Channel/Locale/Currency via scopeCoordinator.

## R4 DB truth
- POST /cart/add only variant_id/qty/slot_start, price resolved server.

## R5 A11y
- h1 + landmark + aria-label + role=status/alert, no p>div hydration.

## R6 UX
- Empty No … found + ApiState retry + window.confirm + Skeleton.

## R7 Perf
- throttle 3000/min reads, health never throttled, cacheTtl.

## R8 Security
- DemoGuard 107, HMAC hash_equals, FE_SHARED_SECRET env.

## R9 UI tokens — padding/button/table/form
- Button: h-9 (36px) / min-h-11 (44px), px-3 py-2, active:scale-95
- Input: h-9 px-3 py-1 text-sm, border-input
- Card: p-6 pt-0 space-y-4, header p-6, content p-6
- Table: p-3/p-4, overflow-x-auto, border-b, text-sm
- Form: gap-2 / gap-4, space-y-3 / space-y-4, grid gap-2 md:grid-cols-3/4
- All measured via className contains token or getComputedStyle(padding) >= 12px
