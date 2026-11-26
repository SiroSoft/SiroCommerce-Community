# P0-7b — Failure-Injection Plan (10 drills bắt buộc, theo spec §14 + docs/11§2)

> Nguyên tắc spec: failure là first-class; mọi drill phải có observability + idempotency + recovery. Merchant UI chỉ action business-safe; trace cho dev.

| # | Drill (spec) | Cách bắn | Kỳ vọng (bám spec) | Verify |
|---|---|---|---|---|
| F01 | Payment timeout | Mock gateway delay > timeout ở `authorize/capture` | Order → `failed`, không trừ kho thừa, retry an toàn (guard already-captured) | Flight Recorder đủ 9 chặng; Doctor `--payment order:{id}` chỉ đúng lỗi |
| F02 | Duplicate webhook | Gửi cùng event_id 2 lần | Lần 2 → `200 already_processed`, totals/reservations không đổi | payments UNIQUE key chặn; log event_id |
| F03 | Shipping timeout | Mock provider timeout `createShipment` | Order giữ `processing/ready_to_ship`, không tạo 2 shipments | guard duplicate shipment; retry dry-run trước |
| F04 | Queue/worker outage | Dừng worker rồi place order | Request vẫn 200/pending, job tồn trong queue, recover khi worker lên | queue depth metric; replay an toàn |
| F05 | Cache outage | Tắt cache layer | Hệ thống chạy degradated (chậm hơn nhưng đúng), không 500 hàng loạt | budgets có thể vượt nhưng functional pass |
| F06 | SMTP outage | Chặn mail driver | Order vẫn place được; noti retry queue, không block checkout | outbox + retry log |
| F07 | DB tx failure | Ép deadlock ở reserve+create | Rollback sạch: không order nửa vời, reservations released | ledger khớp; transitions không ghi dở |
| F08 | Duplicate checkout | Double-click place cùng Idempotency-Key | 1 order duy nhất, response thứ 2 = response đầu | UNIQUE idempotency_key |
| F09 | Inventory race | Nversion cùng mua vượt tồn | Không oversell; bên thua báo hết hàng đúng | race-test song song, AC-D03 |
| F10 | Extension failure | Extension payment/ship throw | Core flow không vỡ (fallback/disable ext), lỗi cô lập + log | compat test ext; core E2E vẫn xanh |
| F11 | Upgrade/rollback | Migrate lên + rollback | Dữ liệu không mất; down-migration an toàn, backup trước | backup DB+uploads; rollback artifact sẵn |

Mỗi drill ghi: actor, reason, before/after state, verification result (theo 08§3).
