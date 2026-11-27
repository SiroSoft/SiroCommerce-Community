# Changelog — siro/email-capture-popup

All notable changes to this extension are documented here. Dates are UTC.

## [1.0.0] — 2026-11-26

### Added
- Email capture popup in the storefront `footer` slot (time-delay, exit-intent
  and 50%-scroll triggers, once per session; temporary minimal dialog marked
  `TODO-UX-PRIMITIVE` until the shared modal/focus-trap primitives land).
- `POST /api/ext/siro/email-capture-popup/subscribe` (public, throttled,
  Idempotency-Key): stores subscribers in `ext_email_capture_subscribers`,
  scoped by `store_id` + `channel_id` (`UNIQUE (store_id, channel_id, email)`).
- Welcome mail via `MailTemplateRegistry` (`email_capture.subscribed` →
  `welcome`), sent only when `ConsentService::hasMarketingConsent()` is true
  (customer preference, else session ledger).
- Welcome coupon (`WELCOME-XXXXXXXX`, 10% off) created via
  `CouponService::create()` on `POST /api/ext/siro/email-capture-popup/coupons`
  (`cap:coupon:create`).
- Admin panels: subscriber stats (`GET admin/stats`) and subscriber table
  (`GET admin/subscribers`, paginated), subscriber delete
  (`DELETE admin/subscribers/{id}`, audit-logged).
- Re-subscribing the same email in the same scope returns the existing row
  and never sends a second welcome mail.
