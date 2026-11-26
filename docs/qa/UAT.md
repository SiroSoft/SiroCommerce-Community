# UAT Skeleton — SiroCommerce (map 1:1 e2e, bám spec)

> Pass = 100% mới release (theo docs/11§1, docs/12). Creds demo qua env, không hardcode.

| UAT | Flow (spec ref) | Tiêu chí pass |
|---|---|---|
| UAT-01 | Guest browse → product → add cart → checkout → COD place (02,03) | Đơn tạo, snapshot đúng, reservation→commit, AC-D01/D02 |
| UAT-02 | Duplicate checkout (03§2, F08) | 1 đơn duy nhất |
| UAT-03 | Admin tạo product ≤60s; sửa giá/stock ≤2 clicks (04) | AC-U01/U02, đúng giờ |
| UAT-04 | Ship đơn ≤3 actions; transitions ghi log (02§2) | AC-U04, order_transitions đủ |
| UAT-05 | Coupon ≤60s + áp đúng totals (03,04) | AC-U05 |
| UAT-06 | Home Action Center 0-click revenue/pending (04) | AC-U06 |
| UAT-07 | Resolve failed order ≤3 actions (04,06 Order Rescue) | AC-U07, audit đủ 7 bước |
| UAT-08 | SEO: detector → fix 1 lỗi missing-desc/ALT (05) | Detect→Explain→Fix chạy |
| UAT-09 | Smart Search tìm entity + chạy command (06) | ≤5s, đúng target |
| UAT-10 | Demo user write → 403 readonly (08) | GET ok, POST/PUT/DELETE chặn |
| UAT-11 | Perf cảm quan + mobile + dark-mode (04,09) | Không lỗi trắng/console error |
| UAT-12 | Failure drill demo: duplicate webhook + inventory race (F02,F09) | Không double-charge/oversell |

Ký xác nhận: Tester ___ Ngày ___ Build ___ (block release nếu 1 UAT fail).
