# Member Deals & Tier Visibility — Spec (P2.6)

Member-price exists; this adds **who can see/buy** (gating) and **scheduled
lifecycle deals** (tier windows, birthdays).

## Tier rank (shared)

`bronze 0 < silver 1 < gold 2 < platinum 3` (`MemberPriceService::TIERS`
order). Tier truth stays the latest `customer_loyalty_snapshot` row; guests
have no tier. `min_tier = null` means public. A viewer with tier T sees
items with `min_tier = null` or `rank(min_tier) <= rank(T)`.

## 1. Tier visibility gating (`products.min_tier`, nullable)

- Catalog list/detail filter by viewer tier (tier in cache key; guests see
  public only). Detail of a gated product for an ineligible viewer → 404
  (same as missing — no existence leak).
- Buying is enforced fail-closed at **quote and place** (not at addLine:
  carts are anonymous, membership is known later): any line whose product
  requires a higher tier → 422. Tier upgrades between quote and place are
  honored (recheck at place); downgrades fail the place.
- Admin sets `min_tier` via product update (null clears). Exposed on the
  product row for staff; storefront never leaks gated rows.

## 2. Tier-window flash sales (`flash_sales.min_tier`, nullable)

- Null = everybody (today's behavior). Set = members with rank >= min can
  see and buy the deal; others see the base price and no flash badge.
- Checkout re-validation (existing sale/cap/price checks) additionally
  rejects tier-ineligible buyers → 422. Caps and recording unchanged.

## 3. Member deals (`member_deals` + `users.birthdate`)

One table for occasion-based percent-off, auto-applied (no codes):

- `kind`: `tier_window` (any member with rank >= min_tier during
  `[starts_at, ends_at]`) or `birthday` (member whose birth month/day is
  today; `min_tier` optional extra gate; dates ignored).
- `percent_off` 1..90, `state` active/disabled. Best single deal wins (max
  percent) — no stacking.
- Tender order: member tier prices → **member deal** → coupon → gift.
  Evidence entry `{member_deal_id, name, percent_off, discount_minor}`.
- `users.birthdate` (nullable date, `YYYY-MM-DD`): set via account profile
  update; validated as real calendar date, never in the future... (any past
  date allowed, year only matters for validity, matching is month/day).
- Birthday deal needs no scheduler: evaluated live at quote/place from the
  member's birthdate. No points, no coupons issued, nothing to expire.

## Admin / storefront

- Admin: product `min_tier` field, flash `min_tier` field, deals CRUD page
  (create/update/disable), birthdate visible on account (read-only note:
  staff never edits member birthdate — self-service only).
- Storefront: member-deal discount line in quote/checkout totals; birthday
  input beside display-name edit; gated products are simply absent (list)
  or 404 (detail) — no "locked" teasers in v1.

## Limits (v1)

- No per-product tier PRICES beyond existing member_prices (gating is
  visibility only); no deal stacking; no birthday points/bonus (percent-off
  only); no deal usage caps (windows are short; the birthday surface is the
  member's own birthdate).
