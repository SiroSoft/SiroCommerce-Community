# VN Shipping + Payment Providers Spec

Existing: payment providers (Stripe/PayPal/Adyen/Authorize.Net/Square) via
`PaymentProviderFactory`; shipping only DHL. This adds VN carriers and VN
payment methods behind the same contracts, plus provider status.

## Shipping carriers

Implement `App\Modules\Shipping\Infrastructure\GhnCarrier` and
`ViettelPostCarrier` implementing `Carrier` + `RateProvider` using a
`ShippingTransport` closure (same pattern as DHL) so tests never hit the network.

Env:
- `SHIPPING_PROVIDER=dhl|ghn|viettelpost` (default dhl)
- GHN: `GHN_API_URL`, `GHN_TOKEN`, `GHN_SHOP_ID`, `GHN_ENV` (sandbox|production)
- ViettelPost: `VIETTELPOST_API_URL`, `VIETTELPOST_USERNAME`,
  `VIETTELPOST_PASSWORD`

Behaviour:
- `rates(context)`: map from/to district/province + weight to carrier API;
  return normalized `{code,name,amount_minor,currency,eta_days_min,eta_days_max}`.
- `create/cancel/track`: provider id + status mapping; idempotency key passed
  where the API supports it; errors surface as RuntimeException (service maps
  to 422).
- Registry: `ConfiguredShippingProviderRegistry` accepts all three providers when
  configured; unknown/misconfigured → 422 (unchanged contract).
- `/api/storefront/shipping-estimate` uses the configured provider and falls
  back to native with `estimated=true` when the carrier errors (unchanged).

## Payment providers

Add to `PaymentProviderFactory`:
- `vnpay`: sandbox/production URLs, `VNPAY_TMN_CODE`, `VNPAY_HASH_SECRET`;
  execute creates a signed payment URL (HMAC-SHA512 over sorted params);
  verifyWebhook validates `vnp_SecureHash`.
- `momo`: `MOMO_PARTNER_CODE`, `MOMO_ACCESS_KEY`, `MOMO_SECRET_KEY`,
  `MOMO_API_URL`; execute creates a payment request signed HMAC-SHA256;
  verifyWebhook validates signature.

Both implement `PaymentProvider` and return normalized `{provider_ref,status,
redirect_url}`; no credential leakage in errors.

## Provider status

`ProviderStatusService` lists configured names for payment
(stripe/paypal/adyen/authorize_net/square/vnpay/momo) and shipping
(dhl/ghn/viettelpost) using booleans only; launch checklist unchanged.

## Tests

Fake transport: GHN/Viettel rate+create mapping, cancel/track mapping, missing
config error; VNPay/MoMo signed URL/params, webhook accept/reject (tampered
signature), missing credentials. No real network.

## Non-goals

No VietQR reconciliation UI, no carrier webhook signature (carrier webhooks keep
the existing shared-secret guard), no automatic label printing.

## Implementation status

Shipped behind the existing contracts:

- Shipping carriers: `backend/app/Modules/Shipping/Infrastructure/GhnCarrier.php`,
  `ViettelPostCarrier.php`; HTTP transports in the same folder; injectable
  `ShippingTransport` contract for fake transports.
- Registry: `ConfiguredShippingProviderRegistry` resolves `dhl|ghn|viettelpost`
  and rejects unknown names. `shipping-estimate` uses `SHIPPING_PROVIDER` and
  keeps the native `estimated=true` fallback.
- Payment: `VnPayPaymentProvider` (signed redirect URL, HMAC-SHA512 webhook) and
  `MomoPaymentProvider` (signed create request, HMAC-SHA256 webhook) registered
  in `PaymentProviderFactory` and `PaymentProviderRegistry`.
- Status: `ProviderStatusService` reports `vnpay|momo` payment and
  `ghn|viettelpost` shipping readiness as names/booleans only.

Environment:

| Variable | Purpose |
| --- | --- |
| `SHIPPING_PROVIDER` | `native|dhl|ghn|viettelpost` |
| `GHN_ENV` | `sandbox|production` gateway selection |
| `GHN_API_URL` | Optional HTTPS gateway override |
| `GHN_TOKEN`, `GHN_SHOP_ID` | GHN credentials |
| `VIETTELPOST_API_URL` | Partner API host (sandbox or production) |
| `VIETTELPOST_USERNAME`, `VIETTELPOST_PASSWORD` | ViettelPost login |
| `VNPAY_TMN_CODE`, `VNPAY_HASH_SECRET` | VNPay merchant credentials |
| `MOMO_PARTNER_CODE`, `MOMO_ACCESS_KEY`, `MOMO_SECRET_KEY` | MoMo credentials |
| `MOMO_API_URL` | Optional MoMo HTTPS override |

Limitations:

- Carrier webhooks still use the existing shared-secret guard; GHN/ViettelPost
  payload signatures are not verified.
- VNPay/MoMo `execute()` returns a redirect/pending result (`status`,
  `redirect_url`); checkout must follow the redirect. Refund/void query APIs are
  not implemented in this slice.
- `createReturnLabel` maps provider return orders for GHN/ViettelPost but no
  automatic label printing.

