# 06 — Smart Commerce Spec (không cần LLM)

## Capabilities
| Capability | Behavior |
|---|---|
| Action Center | Rank urgent/importance/opportunity, link resolution |
| Order Rescue | Detect → Diagnose → SafetyCheck → Recommend → Recover → Verify → Audit |
| SEO Assistant | Tìm lỗi SEO, giải thích impact, offer safe fix |
| Smart Inventory | Stockout risk từ statistical signals minh bạch (chưa cần ML) |
| Product Setup | Dẫn setup dở dang, gợi ý category/attribute/SEO |
| Conversion Watch | Flag funnel anomaly + link evidence ops |
| Smart Search | Tìm products/orders/customers/settings + chạy command thường |
| Optional AI | Gen/explain content/trace/analytics qua provider abstraction; core vẫn dùng được khi tắt AI |

## Design rule
Intelligence nằm trong workflow user. Cấm module "AI" riêng bắt merchant học surface mới.
Mọi recommendation phải có: lý do (evidence link) + action 1-click + undo/safety note.
