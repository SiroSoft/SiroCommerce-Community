# P0-7a — Acceptance Matrix (bám sát spec v0.2)

> Mỗi AC map 1:1 tới spec gốc. Không tự đẻ tiêu chí ngoài spec.

## AC theo docs/02 Domain & Integrity
| ID | Spec ref | Tiêu chí | Gate |
|---|---|---|---|
| AC-D01 | 02§3.1 | Đổi giá catalog không viết lại totals đơn cũ (so snapshot order_lines vs variants hiện tại) | PHPUnit integration |
| AC-D02 | 02§3.2 | Mọi amount là INT minor; case 0.1+0.2 + rounding per-line khai báo, cấm float | unit + review |
| AC-D03 | 02§3.3 | Concurrent purchase cùng variant không oversell (ledger khớp on_hand/reserved) | race-test |
| AC-D04 | 02§3.4 | Duplicate webhook/checkout key → `already_processed`, không charge lần 2 | integration |
| AC-D05 | 02§2 | Mọi đổi state qua named transitions + ghi order_transitions; grep `update(['state'` ngoài transition = 0 | static grep + test |

## AC theo docs/03 API
| ID | Spec ref | Tiêu chí |
|---|---|---|
| AC-A01 | 03§1 | Envelope `{success,message,data,meta}` + errors `{field:[msg]}` mọi endpoint |
| AC-A02 | 03§1 | Version header vnd.siro.v1; pagination meta đủ 4 keys |
| AC-A03 | 03§2 | `POST /checkout/place` thiếu Idempotency-Key → 422; trùng key → trả response cũ |
| AC-A04 | 03§3 | Webhook sai HMAC → 4xx + không queue; đúng → 200 nhanh + job async |
| AC-A05 | 03§4 | openapi.json regenerate + sync public/; FE types gen từ openapi (cấm sync tay) |

## AC theo docs/04 Admin UX (đo thật)
| ID | Target spec |
|---|---|
| AC-U01 | Tạo simple product ≤ 60s |
| AC-U02 | Sửa giá/stock ≤ 2 clicks (inline edit) |
| AC-U03 | Search ≤ 5s |
| AC-U04 | Ship đơn thường ≤ 3 actions |
| AC-U05 | Coupon cơ bản ≤ 60s |
| AC-U06 | Revenue/pending việc 0 click từ Home |
| AC-U07 | Resolve failed order recoverable ≤ 3 actions |

## AC theo docs/05 SEO + 09 Perf (trích)
- AC-S01..S05: metadata/canonical/robots/OG đủ; schema Product/Breadcrumb/Org; sitemap+redirects; facet matrix đúng (page>1/sort/filter → canonical clean + noindex); detector chạy (broken/orphan/duplicate/missing ALT) theo workflow Detect→Explain→Fix.
- AC-P01..P06: budgets 09 (Home/Cat/Product ≤400ms p95 ≤15q; search ≤500ms; quote ≤300ms; place ≤800ms; admin list ≤500ms; API ≤300ms) trên dataset 10k/50k/100k, 0 N+1.

## AC theo docs/08 Security
AC-SEC01: RBAC capability ở Service (không chỉ ẩn nút). AC-SEC02: write nào cũng validate + allowlist 2 lớp. AC-SEC03: log redact secrets. AC-SEC04: recovery dry-run + guard double-capture/duplicate shipment + ghi actor/reason/before-after.
