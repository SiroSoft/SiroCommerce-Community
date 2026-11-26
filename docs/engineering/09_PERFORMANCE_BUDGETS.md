# 09 — Performance Budgets & Production Proof

## 1. Budgets (đo trên dataset proof, MySQL 8)
| Surface | p95 | Query | Ghi chú |
|---|---|---|---|
| Home / Category / Product | ≤ 400ms server | ≤ 15 queries, 0 N+1 | cache page/fragment khi được |
| Search | ≤ 500ms | ≤ 20 queries | index fulltext/prefix, giới hạn facet |
| Cart / Checkout quote | ≤ 300ms | ≤ 12 queries | pricing snapshot, không N+1 promo |
| Place order (không gồm gateway) | ≤ 800ms | transaction gọn | reserve+create trong 1 tx |
| Admin list 20 rows | ≤ 500ms | paginate + index | sort/filter có index |
| API p95 chung | ≤ 300ms | — | rate-limit + ETag nơi đọc nhiều |

## 2. Production proof dataset (tối thiểu)
10,000 products (+variants), 50,000 customers, 100,000 historical orders (+promos, refunds, callbacks, shipment events, failed workflows).
Benchmarks: Home/Category/Product/Search/Cart/Checkout/API — gồm query counts, N+1 detection, cache hit, memory.

## 3. Gates perf
Vượt budget → block release. Mọi regression perf phải kèm EXPLAIN + fix index/query, không "tối ưu sau".
