# 04 — Merchant Admin UX Spec

## 1. Navigation (mặc định business)
`Home → Sell → Grow → Store → Health`. Ẩn `Developer, Extensions, API, Webhooks, Jobs, Events, Trace, System` trừ khi role/capability yêu cầu. Cấm plugin tự thêm menu top-level (chỉ được mount vào slot cho phép).

## 2. Home = Action Center (không phải widget dump)
Rank: urgent ops → important work → growth opportunities. Mỗi card link thẳng tới resolution (≤1 click tới màn xử lý).

## 3. UX targets (QA đo thật)
| Task | Target |
|---|---|
| Tạo simple product | ≤ 60s |
| Sửa giá / stock | ≤ 2 clicks từ list/detail (inline edit) |
| Tìm order/customer/product | ≤ 5s via global search |
| Xử lý/ship đơn thường | ≤ 3 meaningful actions |
| Tạo coupon cơ bản | ≤ 60s |
| Thấy revenue hôm nay / việc pending | 0 click từ Home |
| Resolve failed order recoverable | ≤ 3 meaningful actions |

## 4. Interaction rules
- Progressive disclosure: form simple trước; variants/tax/SEO/attributes mở rộng khi cần.
- Inline edit cho price/stock/status; autosave nơi an toàn; undo cho reversible.
- Destructive dialog nêu hậu quả cụ thể (không "Are you sure?").
- Empty state nêu next action. Error trả lời: chuyện gì? có ảnh hưởng khách? làm gì tiếp?
- Role-aware: ẩn hẳn chức năng không liên quan (không chỉ disable).
- Global Smart Search / Command Palette: tìm entity + setting + action.
- Mọi async có skeleton/empty/error+retry. Toast từ i18n, không hardcode.
