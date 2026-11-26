# Subscription Plans Admin + Product Link Spec

The recurring engine already exists (`SubscriptionService`: start, transitions,
renewal jobs, payment operations, account API). Missing: operator plan CRUD and
a product → plan path so shoppers can subscribe from the PDP.

## Admin plan API (admin/owner/finance, cap:subscriptions)

- `GET /api/v2/admin/subscription-plans` (`?state=`)
- `POST /api/v2/admin/subscription-plans` (`name`, `amount_minor` > 0,
  `currency` ISO3, `interval` weekly|monthly|yearly, `interval_count` 1..12,
  `trial_period` nullable, `trial_period_count` 0..90, `state` active|disabled,
  optional `provider`)
- `PUT /api/v2/admin/subscription-plans/{id}`
- `DELETE /api/v2/admin/subscription-plans/{id}` (409 while subscriptions
  reference it)

## Product link

`product_subscription_plans`: `product_id`, `variant_id` nullable, `plan_id`,
`sort`. Admin product update accepts `subscription_plan_ids: string[]`
(replace-all); ProductResource exposes
`subscription_plans: [{ id, name, amount_minor, currency, interval, interval_count }]`.
Unknown/disabled plan ids → 422.

## Storefront

- PDP shows a “Subscribe & save” block when plans exist: interval label, price,
  optional trial, button “Subscribe”.
- Button requires sign-in; calls existing `POST /api/account/subscriptions`
  (`plan_id`, `Idempotency-Key`) and redirects to `/account` subscriptions.
- Account subscriptions page lists plans (existing API) with pause/resume/cancel
  (existing transitions).

## Admin UI

Subscriptions page gains a “Plans” tab: table + create/edit dialog + delete
ConfirmDialog. Product form gains a plans multi-select.

## Tests

Plan CRUD validation (interval, amount, currency, trial), delete blocked while
in use, product link replace-all + unknown plan 422, resource shape, PDP block
renders, subscribe CTA calls account API with idempotency and redirects,
account pause/resume still works.
