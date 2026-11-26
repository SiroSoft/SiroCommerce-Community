# Shipping estimate (PDP)

`POST /api/storefront/shipping-estimate` is a public, deterministic storefront
estimate. It never claims a live carrier quote unless a provider actually
answered.

## Request

```json
{
  "variant_id": "variant_123",
  "qty": 1,
  "destination": { "country": "VN", "region": "HN", "postal_code": "100000" }
}
```

- `variant_id`: required, 1..64 chars, variant must exist and be `active`.
- `qty`: optional integer 1..99 (default 1).
- `destination.country`: required ISO2 in the shared RegionalRules list.
- `destination.region`: required, max 64 chars.
- `destination.postal_code`: optional, max 16 chars.
- Commerce scope headers (`X-Store-Id`, `X-Channel-Id`, `X-Locale`, `X-Currency`)
  are required and the scope currency is the estimate currency.

## Response (200 success data)

```json
{
  "estimated": true,
  "provider": "native",
  "currency": "VND",
  "origin": { "country": "VN", "region": "HN", "postal_code": "100000" },
  "packages": [{ "weight_g": 500, "qty": 1 }],
  "free_shipping_threshold_minor": 1500000,
  "methods": [
    { "code": "standard", "label": "Standard", "amount_minor": 30000, "currency": "VND", "eta_days_min": 2, "eta_days_max": 4 },
    { "code": "express", "label": "Express", "amount_minor": 60000, "currency": "VND", "eta_days_min": 1, "eta_days_max": 2 }
  ],
  "disclaimer": "estimated",
  "fallback_reason": null
}
```

`free_shipping_threshold_minor` is display-only. The backend always returns a
real fee; it never zeroes the amount because the threshold is configured.

## Rules

- Package weight = `max(variant.weight_g, SHIPPING_DEFAULT_WEIGHT_G) * qty`;
  one package is returned.
- Origin comes from `SHIPPING_ORIGIN_COUNTRY` / `SHIPPING_ORIGIN_REGION` /
  `SHIPPING_ORIGIN_POSTAL_CODE` (default `VN` / `HN` / `100000`).
- Domestic (same country): `standard` flat rate + configurable ETA and
  `express` fixed 1..2 days.
- International: `standard` only, `flat rate * SHIPPING_INTL_MULTIPLIER`,
  ETA 5..10 days.
- When `SHIPPING_PROVIDER=dhl` and the provider can be constructed,
  `ShippingService::quote` is called with the real origin, destination,
  packages and scope. Rates become `methods`; ETA is read from
  `eta_days_min`/`eta_days_max` or `estimated_days`. Response uses
  `estimated=false` and `disclaimer="live"`.
- If the provider is unavailable or returns an unusable quote, the response
  falls back to native rules with `estimated=true`,
  `disclaimer="estimated"` and `fallback_reason="provider_unavailable"`.
  Provider/secret details are never exposed.
- Invalid variant, quantity or destination returns 422 with a safe message.
- Audit log: `shipping.estimate` with variant id, country, method count,
  provider and estimated flag. Region and postal code are not logged.

## Environment

| Variable | Default |
| --- | --- |
| `SHIPPING_PROVIDER` | `native` |
| `SHIPPING_DEFAULT_WEIGHT_G` | `300` |
| `SHIPPING_ORIGIN_COUNTRY` | `VN` |
| `SHIPPING_ORIGIN_REGION` | `HN` |
| `SHIPPING_ORIGIN_POSTAL_CODE` | `100000` |
| `SHIPPING_FLAT_RATE_MINOR` | `30000` |
| `SHIPPING_EXPRESS_RATE_MINOR` | `60000` |
| `SHIPPING_ETA_MIN_DAYS` | `2` |
| `SHIPPING_ETA_MAX_DAYS` | `4` |
| `SHIPPING_INTL_MULTIPLIER` | `3` (clamped 1..20) |
| `SHIPPING_FREE_OVER_MINOR` | `1500000` (`<= 0` means no threshold) |
