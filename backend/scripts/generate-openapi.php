<?php

declare(strict_types=1);

/** Generate a deterministic route evidence document without loading the app. */
$base = dirname(__DIR__);
$routesFile = $base . '/routes/api.php';
$output = $base . '/docs/openapi.json';
$publicOutput = $base . '/public/openapi.json';
$adminOutput = dirname($base) . '/apps/admin/docs/openapi.json';
$source = (string) file_get_contents($routesFile);
$commerceVersion = '0.1.0';
$versionSource = (string) @file_get_contents($base . '/app/CommerceVersion.php');
if (preg_match("~VERSION\\s*=\\s*'([^']+)'~", $versionSource, $versionMatch) === 1) {
    $commerceVersion = $versionMatch[1];
}

preg_match_all('~\$app->router->(get|post|put|patch|delete)\(\'([^\']+)\'[^\n]*~i', $source, $matches, PREG_SET_ORDER);
$paths = [];
$requestSchemas = [];
$requestDefinitions = [
    'put:/api/admin/storefront/config' => ['required' => ['expected_version', 'theme_key', 'vertical_key'], 'properties' => ['expected_version' => ['type' => 'integer', 'minimum' => 1], 'theme_key' => ['type' => 'string'], 'vertical_key' => ['type' => 'string'], 'brand' => ['type' => 'object'], 'tokens' => ['type' => 'object'], 'navigation' => ['type' => 'object'], 'homepage' => ['type' => 'object'], 'presentation' => ['type' => 'object'], 'purchase' => ['type' => 'object'], 'policies' => ['type' => 'object']]],
    'post:/api/admin/storefront/config/preview' => ['required' => ['expected_version', 'theme_key', 'vertical_key'], 'properties' => ['expected_version' => ['type' => 'integer'], 'theme_key' => ['type' => 'string'], 'vertical_key' => ['type' => 'string'], 'brand' => ['type' => 'object'], 'tokens' => ['type' => 'object'], 'navigation' => ['type' => 'object'], 'homepage' => ['type' => 'object'], 'presentation' => ['type' => 'object'], 'purchase' => ['type' => 'object'], 'policies' => ['type' => 'object']]],
    'post:/api/admin/storefront/config/publish' => ['required' => ['expected_version'], 'properties' => ['expected_version' => ['type' => 'integer', 'minimum' => 1]]],
    'post:/api/admin/storefront/config/restore/{revision}' => ['required' => ['expected_version'], 'properties' => ['expected_version' => ['type' => 'integer', 'minimum' => 1]]],
    'post:/api/admin/storefront/config/apply-preset' => ['required' => ['expected_version', 'preset_key'], 'properties' => ['expected_version' => ['type' => 'integer', 'minimum' => 1], 'preset_key' => ['type' => 'string']]],
    'post:/api/auth/forgot-password' => ['required' => ['email'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email']]],
    'post:/api/auth/register' => ['required' => ['name', 'email', 'password'], 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'], 'password' => ['type' => 'string', 'format' => 'password'], 'role' => ['type' => 'string']]],
    'post:/api/auth/login' => ['required' => ['email', 'password'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email'], 'password' => ['type' => 'string', 'format' => 'password']]],
    'post:/api/auth/refresh' => ['required' => ['refresh'], 'properties' => ['refresh' => ['type' => 'string']]],
    'post:/api/wishlist' => ['required' => ['product_id'], 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string']]],
    'post:/api/carts' => ['required' => [], 'properties' => ['currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3]]],
    'post:/api/carts/{id}/lines' => ['required' => ['variant_id', 'qty'], 'properties' => ['variant_id' => ['type' => 'string'], 'qty' => ['type' => 'integer', 'minimum' => 1]]],
    'post:/api/checkout/place' => ['required' => ['cart_id'], 'properties' => ['cart_id' => ['type' => 'string'], 'guest_email' => ['type' => 'string', 'format' => 'email'], 'coupon_code' => ['type' => 'string'], 'gift_code' => ['type' => 'string'], 'gift_codes' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5], 'fulfillment_method' => ['type' => 'string'], 'pickup_point_code' => ['type' => 'string']]],
    'post:/api/admin/promotion-rules' => ['required' => ['name', 'kind', 'value'], 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'value' => ['type' => 'integer'], 'min_subtotal_minor' => ['type' => 'integer'], 'category_slugs' => ['type' => 'array', 'items' => ['type' => 'string']], 'category_slugs_json' => ['type' => 'string'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'max_uses' => ['type' => 'integer'], 'state' => ['type' => 'string']]],
    'put:/api/admin/promotion-rules/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'value' => ['type' => 'integer'], 'min_subtotal_minor' => ['type' => 'integer'], 'category_slugs' => ['type' => 'array', 'items' => ['type' => 'string']], 'category_slugs_json' => ['type' => 'string'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'max_uses' => ['type' => 'integer'], 'state' => ['type' => 'string']]],
    'post:/api/admin/tax-rules' => ['required' => ['code', 'rate_basis_points'], 'properties' => ['code' => ['type' => 'string', 'maxLength' => 64], 'destination_country' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[A-Za-z]{2}$'], 'destination_region' => ['type' => 'string', 'nullable' => true, 'maxLength' => 64], 'destination_postal_code' => ['type' => 'string', 'nullable' => true, 'maxLength' => 32], 'tax_class' => ['type' => 'string', 'maxLength' => 32, 'default' => 'standard'], 'rate_basis_points' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000], 'priority' => ['type' => 'integer', 'default' => 0], 'state' => ['type' => 'string', 'enum' => ['active', 'disabled'], 'default' => 'active'], 'compound' => ['type' => 'boolean', 'default' => false], 'effective_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true], 'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true]]],
    'put:/api/admin/tax-rules/{id}' => ['required' => [], 'properties' => ['code' => ['type' => 'string', 'maxLength' => 64], 'destination_country' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[A-Za-z]{2}$'], 'destination_region' => ['type' => 'string', 'nullable' => true, 'maxLength' => 64], 'destination_postal_code' => ['type' => 'string', 'nullable' => true, 'maxLength' => 32], 'tax_class' => ['type' => 'string', 'maxLength' => 32], 'rate_basis_points' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000], 'priority' => ['type' => 'integer'], 'state' => ['type' => 'string', 'enum' => ['active', 'disabled']], 'compound' => ['type' => 'boolean'], 'effective_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true], 'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true]]],
    'post:/api/admin/vendors' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string'], 'commission_bps' => ['type' => 'integer'], 'contact_email' => ['type' => 'string'], 'description' => ['type' => 'string'], 'owner_customer_id' => ['type' => 'string', 'nullable' => true]]],
    'put:/api/admin/vendors/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string'], 'commission_bps' => ['type' => 'integer'], 'contact_email' => ['type' => 'string'], 'description' => ['type' => 'string'], 'owner_customer_id' => ['type' => 'string', 'nullable' => true]]],
    'post:/api/admin/vendors/{id}/payouts' => ['required' => ['period_start', 'period_end'], 'properties' => ['period_start' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Inclusive window start; YYYY-MM-DD means 00:00:00.'], 'period_end' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Inclusive window end; YYYY-MM-DD means 23:59:59.']]],
    'post:/api/admin/pickup-points' => ['required' => ['code', 'name'], 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'store_id' => ['type' => 'string'], 'source_id' => ['type' => 'string'], 'address_line' => ['type' => 'string'], 'city' => ['type' => 'string'], 'sla_hours' => ['type' => 'integer']]],
    'put:/api/admin/pickup-points/{code}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'store_id' => ['type' => 'string'], 'source_id' => ['type' => 'string'], 'address_line' => ['type' => 'string'], 'city' => ['type' => 'string'], 'sla_hours' => ['type' => 'integer'], 'state' => ['type' => 'string']]],
    'post:/api/admin/fulfillment/{id}/ready' => ['required' => [], 'properties' => ['pickup_point_code' => ['type' => 'string']]],
    'post:/api/admin/fulfillment/{id}/collect' => ['required' => [], 'properties' => []],
    'post:/api/admin/member-deals' => ['required' => ['name', 'percent_off'], 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'min_tier' => ['type' => 'string'], 'percent_off' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string']]],
    'put:/api/admin/member-deals/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'min_tier' => ['type' => 'string'], 'percent_off' => ['type' => 'integer'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'state' => ['type' => 'string']]],
    'post:/api/orders/recover' => ['required' => ['order_number', 'email'], 'properties' => ['order_number' => ['type' => 'string', 'maxLength' => 32], 'email' => ['type' => 'string', 'format' => 'email']]],
    'post:/api/v2/checkout/quote' => ['required' => ['cart_id'], 'properties' => ['cart_id' => ['type' => 'string'], 'coupon' => ['type' => 'string']]],
    'post:/api/v2/b2b/companies/{id}/purchase-orders' => ['required' => ['po_number', 'total_minor'], 'properties' => ['po_number' => ['type' => 'string'], 'currency' => ['type' => 'string'], 'total_minor' => ['type' => 'integer', 'minimum' => 0], 'contract_id' => ['type' => 'string']]],
    'post:/api/v2/b2b/purchase-orders/{id}/{action}' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/storefront/shipping-estimate' => [
        'required' => ['variant_id', 'destination'],
        'properties' => [
            'variant_id' => ['type' => 'string', 'maxLength' => 64],
            'qty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1],
            'destination' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['country', 'region'], 'properties' => [
                'country' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$', 'example' => 'VN'],
                'region' => ['type' => 'string', 'maxLength' => 64, 'example' => 'HN'],
                'postal_code' => ['type' => 'string', 'maxLength' => 16, 'example' => '100000'],
            ]],
        ],
        'example' => ['variant_id' => 'variant_123', 'qty' => 1, 'destination' => ['country' => 'VN', 'region' => 'HN', 'postal_code' => '100000']],
    ],
    'post:/api/v2/shipping/rates' => ['required' => ['origin', 'destination', 'packages'], 'properties' => ['origin' => ['type' => 'object', 'additionalProperties' => true], 'destination' => ['type' => 'object', 'additionalProperties' => true], 'packages' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], 'provider' => ['type' => 'string']]],
    'post:/api/v2/shipments' => ['required' => ['shipment'], 'properties' => ['shipment' => ['type' => 'object', 'additionalProperties' => true]]],
    'post:/api/v2/payments/{id}/capture' => ['required' => ['amount_minor'], 'properties' => ['amount_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string']]],
    'post:/api/v2/payments/{id}/authorize' => ['required' => ['amount_minor'], 'properties' => ['amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => ['type' => 'string']]],
    'post:/api/v2/payments/{id}/refund' => ['required' => ['amount_minor'], 'properties' => ['amount_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string']]],
    'post:/api/v2/payments/{id}/void' => ['required' => [], 'properties' => ['amount_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string']]],
    'post:/api/admin/fulfillment/allocate' => [
        'required' => ['order_id', 'lines'],
        'properties' => [
            'order_id' => ['type' => 'string'],
            'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['variant_id', 'qty'], 'properties' => ['variant_id' => ['type' => 'string'], 'qty' => ['type' => 'integer', 'minimum' => 1]]]],
        ],
    ],
    'put:/api/admin/inventory/{variant}' => ['required' => ['on_hand'], 'properties' => ['on_hand' => ['type' => 'integer', 'minimum' => 0], 'source_id' => ['type' => 'string', 'default' => 'default']]],
    'post:/api/admin/inventory/{variant}/transfer' => ['required' => ['from_source_id', 'to_source_id', 'qty'], 'properties' => ['from_source_id' => ['type' => 'string'], 'to_source_id' => ['type' => 'string'], 'qty' => ['type' => 'integer', 'minimum' => 1], 'reason' => ['type' => 'string']]],
    'post:/api/v2/inventory/{variant}/atp' => ['required' => ['qty', 'cart_id'], 'properties' => ['qty' => ['type' => 'integer', 'minimum' => 1], 'cart_id' => ['type' => 'string'], 'ttl_seconds' => ['type' => 'integer', 'minimum' => 1], 'allow_split' => ['type' => 'boolean'], 'backorder_policy' => ['type' => 'string'], 'allow_backorder' => ['type' => 'boolean'], 'allow_preorder' => ['type' => 'boolean'], 'allowed_source_ids' => ['type' => 'array', 'items' => ['type' => 'string']], 'excluded_source_ids' => ['type' => 'array', 'items' => ['type' => 'string']], 'store_id' => ['type' => 'string'], 'company_id' => ['type' => 'string']]],
     'post:/api/admin/returns' => ['required' => ['order_id', 'fulfillment_id', 'lines'], 'properties' => ['order_id' => ['type' => 'string'], 'fulfillment_id' => ['type' => 'string'], 'lines' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/components/schemas/ReturnLineRequest']], 'reason' => ['type' => 'string']]],
     'post:/api/account/orders/{id}/returns' => ['required' => ['fulfillment_id', 'lines'], 'properties' => ['fulfillment_id' => ['type' => 'string'], 'lines' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/components/schemas/ReturnLineRequest']], 'reason' => ['type' => 'string']]],
    'post:/api/admin/returns/{id}/inspect' => ['required' => ['disposition'], 'properties' => ['disposition' => ['type' => 'string', 'enum' => ['restock', 'damaged']], 'note' => ['type' => 'string']]],
    'post:/api/account/consents' => ['required' => ['purpose', 'version', 'source', 'granted'], 'properties' => ['purpose' => ['type' => 'string'], 'version' => ['type' => 'string'], 'source' => ['type' => 'string'], 'granted' => ['type' => 'boolean']]],
    'post:/api/consent' => ['required' => ['necessary', 'analytics', 'marketing', 'policy_version'], 'properties' => ['necessary' => ['type' => 'boolean'], 'analytics' => ['type' => 'boolean'], 'marketing' => ['type' => 'boolean'], 'policy_version' => ['type' => 'string', 'maxLength' => 32], 'session_id' => ['type' => 'string', 'maxLength' => 128]]],
    'post:/api/account/loyalty/expire' => ['required' => ['before'], 'properties' => ['before' => ['type' => 'string', 'format' => 'date']]],
    'post:/api/account/loyalty/redeem' => ['required' => ['points'], 'properties' => ['points' => ['type' => 'integer', 'minimum' => 1]]],
    'post:/api/account/loyalty/{order}/earn' => ['required' => [], 'properties' => []],
     'put:/api/account/notification-preferences' => ['required' => [], 'properties' => ['transactional_email' => ['type' => 'boolean'], 'marketing_email' => ['type' => 'boolean']]],
     'post:/api/account/addresses' => ['required' => ['line1', 'city', 'phone'], 'properties' => ['label' => ['type' => 'string'], 'line1' => ['type' => 'string'], 'city' => ['type' => 'string'], 'phone' => ['type' => 'string']]],
     'put:/api/v2/admin/campaign-automation' => ['required' => [], 'properties' => ['enabled' => ['type' => 'boolean'], 'max_messages_per_day' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000], 'quiet_hours_start' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 23], 'quiet_hours_end' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 23], 'allowed_channels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['email', 'sms']]]]],
     'post:/api/v2/admin/operations/{id}/retry' => ['required' => [], 'properties' => []],
     'post:/api/v2/admin/operations/{id}/replay-notification' => ['required' => [], 'properties' => []],
     'post:/api/v2/admin/subscriptions/{id}/retry' => ['required' => [], 'properties' => []],
    'post:/api/account/privacy/anonymize' => ['required' => [], 'properties' => []],
    'post:/api/account/subscriptions' => ['required' => ['plan_id'], 'properties' => ['plan_id' => ['type' => 'string']]],
    'post:/api/account/subscriptions/{id}/renew' => ['required' => [], 'properties' => []],
    'post:/api/account/subscriptions/{id}/transition' => ['required' => ['action'], 'properties' => ['action' => ['type' => 'string']]],
    'post:/api/v2/admin/subscription-plans' => ['required' => ['name', 'amount_minor', 'currency', 'interval'], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 128], 'amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3], 'interval' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'yearly']], 'interval_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12], 'trial_period' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'yearly'], 'nullable' => true], 'trial_period_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 90], 'state' => ['type' => 'string', 'enum' => ['active', 'disabled']], 'provider' => ['type' => 'string', 'maxLength' => 64]]],
    'put:/api/v2/admin/subscription-plans/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 128], 'amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3], 'interval' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'yearly']], 'interval_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12], 'trial_period' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'yearly'], 'nullable' => true], 'trial_period_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 90], 'state' => ['type' => 'string', 'enum' => ['active', 'disabled']], 'provider' => ['type' => 'string', 'maxLength' => 64]]],
    'post:/api/admin/b2b/companies' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'status' => ['type' => 'string']]],
    'post:/api/admin/b2b/companies/{id}/approval-scopes' => ['required' => ['scope'], 'properties' => ['scope' => ['type' => 'string'], 'channel_id' => ['type' => 'string'], 'approval_limit_minor' => ['type' => 'integer']]],
    'post:/api/admin/b2b/companies/{id}/catalogs' => ['required' => ['code', 'name'], 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'currency' => ['type' => 'string'], 'scope' => ['type' => 'string']]],
    'post:/api/admin/b2b/companies/{id}/catalogs/{catalog}/items' => ['required' => ['variant_id'], 'properties' => ['variant_id' => ['type' => 'string'], 'minimum_qty' => ['type' => 'integer']]],
    'post:/api/admin/b2b/companies/{id}/contracts' => ['required' => ['contract_number'], 'properties' => ['contract_number' => ['type' => 'string'], 'credit_limit_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string']]],
    'post:/api/admin/b2b/companies/{id}/credit-account' => ['required' => ['limit_minor'], 'properties' => ['limit_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'payment_terms_days' => ['type' => 'integer']]],
    'post:/api/admin/b2b/companies/{id}/members' => ['required' => ['user_id', 'role'], 'properties' => ['user_id' => ['type' => 'integer'], 'role' => ['type' => 'string']]],
     'post:/api/admin/b2b/companies/{id}/negotiated-prices' => ['required' => ['variant_id', 'unit_price_minor'], 'properties' => ['variant_id' => ['type' => 'string'], 'currency' => ['type' => 'string'], 'unit_price_minor' => ['type' => 'integer'], 'minimum_qty' => ['type' => 'integer']]],
     'post:/api/admin/b2b/companies/{id}/groups' => ['required' => ['code', 'name'], 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string']]],
     'post:/api/admin/b2b/companies/{id}/roles/{role}/policy' => ['required' => [], 'properties' => ['policy' => ['type' => 'object'], 'approval_limit_minor' => ['type' => 'integer']]],
     'post:/api/admin/b2b/companies/{id}/price-lists' => ['required' => ['code'], 'properties' => ['code' => ['type' => 'string'], 'currency' => ['type' => 'string']]],
     'post:/api/admin/b2b/price-lists/{id}/tiers' => ['required' => ['variant_id', 'minimum_qty', 'unit_price_minor'], 'properties' => ['variant_id' => ['type' => 'string'], 'minimum_qty' => ['type' => 'integer'], 'unit_price_minor' => ['type' => 'integer']]],
     'put:/api/admin/b2b/companies/{id}/terms' => ['required' => [], 'properties' => ['minimum_order_minor' => ['type' => 'integer'], 'minimum_order_qty' => ['type' => 'integer'], 'credit_limit_minor' => ['type' => 'integer'], 'payment_terms_days' => ['type' => 'integer'], 'currency' => ['type' => 'string']]],
    'put:/api/admin/blocks/{id}' => ['required' => [], 'properties' => ['type' => ['type' => 'string'], 'content' => ['type' => 'string'], 'sort' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['draft', 'published']]]],
    'post:/api/admin/catalog/import' => ['required' => ['rows'], 'properties' => ['rows' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'type' => ['type' => 'string'], 'price_minor' => ['type' => 'integer']]]]]],
    'post:/api/admin/products/import' => ['required' => [], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary', 'description' => 'CSV upload, max 2MB and 500 data rows.'], 'csv' => ['type' => 'string', 'maxLength' => 2097152, 'description' => 'Inline CSV alternative to multipart upload.']]],
    'post:/api/admin/channels' => ['required' => ['store_id', 'code', 'name'], 'properties' => ['store_id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'status' => ['type' => 'string']]],
    'post:/api/admin/coupons' => ['required' => ['code'], 'properties' => ['code' => ['type' => 'string'], 'rules' => ['type' => 'string']]],
    'post:/api/admin/promotion-rules' => ['required' => ['name', 'kind', 'value'], 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['cart_percent', 'cart_fixed', 'catalog_percent']], 'value' => ['type' => 'integer'], 'min_subtotal_minor' => ['type' => 'integer', 'minimum' => 0], 'category_slugs' => ['type' => 'array', 'items' => ['type' => 'string']], 'category_slugs_json' => ['type' => 'string'], 'starts_at' => ['type' => 'string', 'format' => 'date-time'], 'ends_at' => ['type' => 'string', 'format' => 'date-time'], 'max_uses' => ['type' => 'integer', 'minimum' => 1], 'state' => ['type' => 'string', 'enum' => ['active', 'disabled']]]],
    'put:/api/admin/promotion-rules/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['cart_percent', 'cart_fixed', 'catalog_percent']], 'value' => ['type' => 'integer'], 'min_subtotal_minor' => ['type' => 'integer', 'minimum' => 0], 'category_slugs' => ['type' => 'array', 'items' => ['type' => 'string']], 'category_slugs_json' => ['type' => 'string'], 'starts_at' => ['type' => 'string', 'format' => 'date-time'], 'ends_at' => ['type' => 'string', 'format' => 'date-time'], 'max_uses' => ['type' => 'integer', 'minimum' => 1], 'state' => ['type' => 'string', 'enum' => ['active', 'disabled']]]],
    'put:/api/admin/configuration/{group}/{section}' => ['required' => ['values'], 'properties' => ['values' => ['type' => 'object', 'additionalProperties' => true], 'expected_version' => ['type' => 'integer']]],
    'post:/api/admin/coupons/bulk' => ['required' => ['pattern', 'count'], 'properties' => ['pattern' => ['type' => 'string'], 'count' => ['type' => 'integer', 'minimum' => 1], 'start' => ['type' => 'integer'], 'rules' => ['type' => 'string']]],
    'put:/api/admin/coupons/{id}' => ['required' => [], 'properties' => ['code' => ['type' => 'string'], 'rules' => ['type' => 'string'], 'state' => ['type' => 'string']]],
    'post:/api/admin/coupons/{id}/enable' => ['required' => [], 'properties' => []],
    'post:/api/admin/extensions/install' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
    'post:/api/admin/extensions/enable' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
    'post:/api/admin/extensions/disable' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
    'post:/api/admin/extensions/uninstall' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string'], 'remove_data' => ['type' => 'boolean']]],
    'post:/api/admin/extensions/health' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
    'post:/api/admin/extensions/upgrade' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
    'post:/api/admin/flash-sales' => ['required' => ['name', 'starts_at', 'ends_at'], 'properties' => ['name' => ['type' => 'string'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'state' => ['type' => 'string'], 'priority' => ['type' => 'integer']]],
    'put:/api/admin/flash-sales/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'state' => ['type' => 'string'], 'priority' => ['type' => 'integer']]],
    'post:/api/admin/flash-sales/{id}/items' => ['required' => ['variant_id', 'sale_price_minor'], 'properties' => ['variant_id' => ['type' => 'string'], 'sale_price_minor' => ['type' => 'integer'], 'cap_qty' => ['type' => 'integer']]],
    'post:/api/admin/media-library' => ['required' => [], 'properties' => ['title' => ['type' => 'string'], 'alt' => ['type' => 'string']]],
    'post:/api/admin/member-prices' => ['required' => ['variant_id', 'tier', 'price_minor'], 'properties' => ['variant_id' => ['type' => 'string'], 'tier' => ['type' => 'string'], 'price_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string']]],
    'post:/api/admin/ops/scheduled/run' => ['required' => [], 'properties' => []],
    'post:/api/admin/products/{id}/booking-slots' => ['required' => ['starts_at', 'ends_at', 'capacity'], 'properties' => ['starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'capacity' => ['type' => 'integer'], 'price_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string']]],
    'put:/api/admin/search-seo/sitemaps' => ['required' => [], 'properties' => ['products' => ['type' => 'array'], 'categories' => ['type' => 'array'], 'cms_pages' => ['type' => 'array'], 'brands' => ['type' => 'array'], 'images_enabled' => ['type' => 'boolean']]],
    'post:/api/admin/search-seo/sitemaps/generate' => ['required' => [], 'properties' => []],
    'post:/api/admin/search-seo/synonyms' => ['required' => ['term', 'synonyms'], 'properties' => ['term' => ['type' => 'string'], 'synonyms' => ['type' => 'array', 'items' => ['type' => 'string']], 'store_id' => ['type' => 'string'], 'channel_id' => ['type' => 'string']]],
    'put:/api/admin/search-seo/synonyms/{id}' => ['required' => [], 'properties' => ['term' => ['type' => 'string'], 'synonyms' => ['type' => 'array', 'items' => ['type' => 'string']]]],
    'post:/api/admin/search-seo/url-rewrites' => ['required' => ['request_path', 'target_path'], 'properties' => ['request_path' => ['type' => 'string'], 'target_path' => ['type' => 'string'], 'redirect_type' => ['type' => 'integer'], 'store_id' => ['type' => 'string'], 'channel_id' => ['type' => 'string']]],
    'put:/api/admin/search-seo/url-rewrites/{id}' => ['required' => [], 'properties' => ['request_path' => ['type' => 'string'], 'target_path' => ['type' => 'string'], 'redirect_type' => ['type' => 'integer']]],
    'patch:/api/carts/{id}/lines/{line}' => ['required' => ['qty'], 'properties' => ['qty' => ['type' => 'integer', 'minimum' => 1]]],
    'post:/api/v2/admin/erp/loyalty-evaluate' => ['required' => ['customer_id', 'order_id', 'order_total_minor'], 'properties' => ['customer_id' => ['type' => 'string'], 'order_id' => ['type' => 'string'], 'order_total_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string']]],
    'post:/api/v2/admin/erp/subscriptions/{id}/renew' => ['required' => [], 'properties' => ['period' => ['type' => 'string'], 'amount_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string']]],
    'post:/api/v2/payments/{id}/proof' => ['required' => ['reference'], 'properties' => ['reference' => ['type' => 'string'], 'file_url' => ['type' => 'string'], 'file_hash' => ['type' => 'string']]],
    'post:/api/admin/customers/{id}/addresses' => ['required' => ['line1', 'city', 'phone'], 'properties' => ['label' => ['type' => 'string'], 'line1' => ['type' => 'string'], 'city' => ['type' => 'string'], 'phone' => ['type' => 'string']]],
    'post:/api/admin/customers/{id}/privacy/legal-hold' => ['required' => ['held'], 'properties' => ['held' => ['type' => 'boolean']]],
    'post:/api/admin/dev/recover' => ['required' => ['action'], 'properties' => ['action' => ['type' => 'string', 'enum' => ['release-expired', 'rescue-order']], 'order_id' => ['type' => 'string'], 'dry_run' => ['type' => 'integer']]],
    'post:/api/admin/digital-assets' => ['required' => ['variant_id', 'name', 'url', 'access_key'], 'properties' => ['variant_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'url' => ['type' => 'string'], 'access_key' => ['type' => 'string'], 'download_limit' => ['type' => 'integer'], 'expires_at' => ['type' => 'string']]],
    'post:/api/admin/fulfillment/{id}/ship' => ['required' => [], 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object']]]],
    'put:/api/admin/inventory/{variant}/priorities' => ['required' => ['source_id', 'priority'], 'properties' => ['source_id' => ['type' => 'string'], 'priority' => ['type' => 'integer']]],
    'post:/api/admin/invoices' => ['required' => ['order_id', 'payment_id'], 'properties' => ['order_id' => ['type' => 'string'], 'payment_id' => ['type' => 'string']]],
    'post:/api/admin/invoices/{id}/credit-memos' => ['required' => ['amount_minor'], 'properties' => ['amount_minor' => ['type' => 'integer'], 'reason' => ['type' => 'string']]],
    'post:/api/admin/orders/{id}/transition' => ['required' => ['to'], 'properties' => ['to' => ['type' => 'string'], 'reason' => ['type' => 'string']]],
    'post:/api/admin/orders/{id}/refund' => ['required' => ['amount_minor'], 'properties' => ['amount_minor' => ['type' => 'integer'], 'reason' => ['type' => 'string']]],
    'post:/api/admin/orders/{id}/refund-remaining' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/admin/gift-cards' => ['required' => ['amount_minor'], 'properties' => ['amount_minor' => ['type' => 'integer', 'minimum' => 1], 'currency' => ['type' => 'string'], 'owner_customer_id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'type' => ['type' => 'string'], 'expires_at' => ['type' => 'string']]],
    'post:/api/admin/gift-cards/{id}/void' => ['required' => [], 'properties' => []],
    'post:/api/admin/orders/{id}/refund-to-credit' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/gift/preview' => ['required' => ['code'], 'properties' => ['code' => ['type' => 'string'], 'subtotal_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string']]],
    'post:/api/admin/storefront/config/import' => ['required' => ['config'], 'properties' => ['config' => ['type' => 'object'], 'expected_version' => ['type' => 'integer']]],
    'post:/api/admin/storefront/config/import-theme' => ['required' => ['theme'], 'properties' => ['theme' => ['type' => 'object'], 'expected_version' => ['type' => 'integer']]],
    'post:/api/products/{id}/stock-alerts' => ['required' => [], 'properties' => ['variant_id' => ['type' => 'string'], 'email' => ['type' => 'string'], 'type' => ['type' => 'string'], 'target_price_minor' => ['type' => 'integer', 'minimum' => 1]]],
    'post:/api/products/stock-alerts/unsubscribe' => ['required' => ['token'], 'properties' => ['token' => ['type' => 'string']]],
    'get:/api/products/{id}/reviews/summary' => ['required' => [], 'properties' => []],
    'post:/api/admin/orders/{id}/cancel' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/admin/payments/reconcile' => ['required' => [], 'properties' => ['apply' => ['type' => 'boolean', 'default' => false]]],
    'post:/api/admin/vendor-payouts/reconcile' => ['required' => ['csv'], 'properties' => ['csv' => ['type' => 'string'], 'dry_run' => ['type' => 'boolean']]],
    'post:/api/admin/extensions/package/verify' => ['required' => ['package'], 'properties' => ['package' => ['type' => 'string', 'format' => 'binary']]],
    'post:/api/admin/extensions/package/qit' => ['required' => ['package'], 'properties' => ['package' => ['type' => 'string', 'format' => 'binary']]],
    'post:/api/admin/extensions/package/install' => ['required' => ['package'], 'properties' => ['package' => ['type' => 'string', 'format' => 'binary'], 'force' => ['type' => 'boolean']]],
    'post:/api/admin/storefront/config/import-theme-archive' => ['required' => ['package'], 'properties' => ['package' => ['type' => 'string', 'format' => 'binary'], 'expected_version' => ['type' => 'integer']]],
    'post:/api/admin/orders/{id}/promotion-allocations/reconcile' => ['required' => ['allocations'], 'properties' => ['allocations' => ['type' => 'array', 'items' => ['type' => 'object']]]],
    'post:/api/admin/pages' => ['required' => ['slug', 'title'], 'properties' => ['slug' => ['type' => 'string'], 'title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'locale' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'scheduled']]]],
    'put:/api/admin/pages/{id}' => ['required' => [], 'properties' => ['slug' => ['type' => 'string'], 'title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'locale' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'scheduled']]]],
     'post:/api/admin/pages/{id}/blocks' => ['required' => ['type', 'content'], 'properties' => ['type' => ['type' => 'string'], 'content' => ['type' => 'string'], 'sort' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['draft', 'published']]]],
     'post:/api/admin/pages/{id}/autosave' => ['required' => [], 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'published', 'archived']], 'scheduled_at' => ['type' => 'string', 'format' => 'date-time'], 'media_json' => ['type' => 'array'], 'expected_version' => ['type' => 'integer']]],
     'post:/api/admin/pages/{id}/merge/{revision}' => ['required' => [], 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'published', 'archived']], 'scheduled_at' => ['type' => 'string', 'format' => 'date-time'], 'media_json' => ['type' => 'array'], 'expected_version' => ['type' => 'integer']]],
    'post:/api/admin/product-components' => ['required' => ['product_id', 'component_variant_id', 'qty'], 'properties' => ['product_id' => ['type' => 'string'], 'component_variant_id' => ['type' => 'string'], 'qty' => ['type' => 'integer', 'minimum' => 1]]],
     'post:/api/admin/products' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string'], 'price_minor' => ['type' => 'integer', 'minimum' => 0], 'compare_price_minor' => ['type' => 'integer', 'minimum' => 0], 'stock' => ['type' => 'integer', 'minimum' => 0], 'sku' => ['type' => 'string'], 'type' => ['type' => 'string'], 'category_id' => ['type' => 'string'], 'description' => ['type' => 'string'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']], 'allow_backorder' => ['type' => 'boolean'], 'meta_title' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'min_tier' => ['type' => 'string'], 'vendor_id' => ['type' => 'string', 'nullable' => true, 'description' => 'Vendor id, or null for the house brand.']]],
     'post:/api/admin/products/{id}/lifecycle' => ['required' => ['state'], 'properties' => ['state' => ['type' => 'string', 'enum' => ['draft', 'scheduled', 'published', 'archived']], 'publish_at' => ['type' => 'string', 'format' => 'date-time'], 'version' => ['type' => 'integer']]],
     'post:/api/admin/products/{id}/voucher-codes' => ['required' => ['code'], 'properties' => ['code' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 64], 'expires_at' => ['type' => 'string', 'format' => 'date-time']]],
     'post:/api/v2/admin/warehouses/reservations' => ['required' => ['warehouse_id', 'lines'], 'properties' => ['warehouse_id' => ['type' => 'string'], 'lines' => ['type' => 'array', 'items' => ['type' => 'object']]]],
     'post:/api/v2/admin/warehouses/reservations/{id}/release' => ['required' => [], 'properties' => []],
     'post:/api/v2/admin/jobs' => ['required' => ['type'], 'properties' => ['type' => ['type' => 'string'], 'payload' => ['type' => 'object', 'additionalProperties' => true]]],
     'post:/api/v2/admin/jobs/{id}/{action}' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
     'post:/api/v2/admin/warehouses/transfers' => ['required' => ['from_warehouse_id', 'to_warehouse_id', 'lines'], 'properties' => ['from_warehouse_id' => ['type' => 'string'], 'to_warehouse_id' => ['type' => 'string'], 'lines' => ['type' => 'array', 'items' => ['type' => 'object']]]],
     'put:/api/admin/products/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string'], 'version' => ['type' => 'integer'], 'min_tier' => ['type' => 'string'], 'is_preorder' => ['type' => 'boolean'], 'vendor_id' => ['type' => 'string', 'nullable' => true, 'description' => 'Vendor id, or null for the house brand.']]],
    'put:/api/admin/products/{id}/media/order' => ['required' => ['media_ids'], 'properties' => ['media_ids' => ['type' => 'array', 'items' => ['type' => 'string']]]],
    'post:/api/admin/products/{id}/options' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'sort' => ['type' => 'integer']]],
    'post:/api/admin/products/{id}/variants' => ['required' => ['sku', 'price_minor'], 'properties' => ['sku' => ['type' => 'string'], 'price_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'compare_price_minor' => ['type' => 'integer'], 'tax_class' => ['type' => 'string'], 'weight_g' => ['type' => 'integer'], 'length_mm' => ['type' => 'integer'], 'width_mm' => ['type' => 'integer'], 'height_mm' => ['type' => 'integer'], 'option_value_ids' => ['type' => 'array', 'items' => ['type' => 'string']]]],
    'post:/api/admin/products/{product}/options/{option}/values' => ['required' => ['value'], 'properties' => ['value' => ['type' => 'string'], 'sort' => ['type' => 'integer'], 'surcharge_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string']]],
    'post:/api/admin/redirects' => ['required' => ['from_path', 'to_path'], 'properties' => ['from_path' => ['type' => 'string'], 'to_path' => ['type' => 'string'], 'code' => ['type' => 'integer']]],
    'post:/api/admin/returns/{id}/label' => ['required' => ['shipping'], 'properties' => ['shipping' => ['type' => 'object']]],
    'post:/api/admin/returns/{id}/reject' => ['required' => [], 'properties' => ['note' => ['type' => 'string']]],
    'post:/api/admin/reviews/{id}/moderate' => ['required' => ['status'], 'properties' => ['status' => ['type' => 'string'], 'moderation_note' => ['type' => 'string']]],
    'put:/api/admin/seo/{type}/{id}' => ['required' => ['title'], 'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'canonical' => ['type' => 'string'], 'robots' => ['type' => 'string'], 'og_image' => ['type' => 'string'], 'schema_json' => ['type' => 'string']]],
    'post:/api/admin/smart/execute' => ['required' => ['command'], 'properties' => ['command' => ['type' => 'string']]],
    'post:/api/admin/stores' => ['required' => ['code', 'name'], 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'status' => ['type' => 'string']]],
     'post:/api/analytics/track' => ['required' => ['event', 'event_id', 'session_id', 'store_id', 'channel_id', 'locale', 'currency', 'properties'], 'properties' => ['event' => ['type' => 'string', 'enum' => ['product_view', 'wishlist_add', 'wishlist_remove', 'search_submit', 'checkout_started', 'web_vitals']], 'event_id' => ['type' => 'string', 'maxLength' => 64], 'session_id' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128], 'properties' => ['type' => 'object', 'maxProperties' => 8], 'analytics_consent' => ['type' => 'boolean'], 'store_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'], 'channel_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'], 'locale' => ['type' => 'string', 'enum' => ['en', 'vi', 'ja', 'de', 'zh']], 'currency' => ['type' => 'string', 'enum' => ['VND', 'USD', 'EUR', 'JPY', 'CNY']]]],
    'post:/api/products/{id}/reviews' => ['required' => ['order_id', 'order_line_id', 'rating', 'body'], 'properties' => ['order_id' => ['type' => 'string'], 'order_line_id' => ['type' => 'string'], 'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5], 'title' => ['type' => 'string'], 'body' => ['type' => 'string']]],
    'post:/api/promotions/preview' => ['required' => ['code', 'subtotal_minor'], 'properties' => ['code' => ['type' => 'string'], 'subtotal_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'lines' => ['type' => 'array', 'items' => ['type' => 'object']], 'customer_group_id' => ['type' => 'string'], 'applied_promotions' => ['type' => 'array']]],
     'post:/api/v2/admin/catalog/imports' => ['required' => ['format', 'source'], 'properties' => ['format' => ['type' => 'string'], 'source' => ['type' => 'string'], 'validate_only' => ['type' => 'boolean']]],
      'post:/api/v2/admin/products/{id}/approval' => ['required' => ['approval_status'], 'properties' => ['approval_status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected']], 'version' => ['type' => 'integer', 'minimum' => 1]]],
      'post:/api/admin/orders/{id}/refund-lines' => ['required' => ['lines'], 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], 'reason' => ['type' => 'string']]],
      'post:/api/v2/admin/exports' => ['required' => ['dataset', 'format'], 'properties' => ['dataset' => ['type' => 'string'], 'format' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'store_id' => ['type' => 'string'], 'channel_id' => ['type' => 'string']]],
      'post:/api/v2/admin/workbench/views' => ['required' => ['name', 'resource', 'filters'], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 128], 'resource' => ['type' => 'string'], 'filters' => ['type' => 'object', 'additionalProperties' => true], 'columns' => ['type' => 'array', 'items' => ['type' => 'string']]]],
      'put:/api/v2/admin/workbench/permissions/{role}' => ['required' => ['permissions'], 'properties' => ['permissions' => ['type' => 'array', 'items' => ['type' => 'string']]]],
      'post:/api/v2/admin/workbench/workflows' => ['required' => ['name', 'resource', 'action', 'steps'], 'properties' => ['name' => ['type' => 'string'], 'resource' => ['type' => 'string'], 'action' => ['type' => 'string'], 'steps' => ['type' => 'array', 'items' => ['type' => 'string']]]],
      'post:/api/v2/admin/workbench/approvals' => ['required' => ['workflow_id', 'resource_id'], 'properties' => ['workflow_id' => ['type' => 'string'], 'resource_id' => ['type' => 'string'], 'payload' => ['type' => 'object', 'additionalProperties' => true]]],
      'post:/api/v2/admin/workbench/approvals/{id}/decision' => ['required' => ['decision'], 'properties' => ['decision' => ['type' => 'string', 'enum' => ['approved', 'rejected']]]],
      'post:/api/v2/admin/workbench/bulk-orders' => ['required' => ['ids', 'to'], 'properties' => ['ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'string']], 'to' => ['type' => 'string'], 'reason' => ['type' => 'string']]],
     'post:/api/v2/admin/ai/copilot/tasks' => ['required' => ['task', 'input'], 'properties' => ['task' => ['type' => 'string'], 'input' => ['type' => 'object', 'additionalProperties' => true], 'product_id' => ['type' => 'string'], 'max_cost_minor' => ['type' => 'integer', 'minimum' => 0]]],
    'post:/api/v2/admin/ai/suggestions' => ['required' => ['task', 'input', 'max_cost_minor'], 'properties' => ['task' => ['type' => 'string', 'pattern' => '^[a-z0-9._-]{1,64}$'], 'input' => ['type' => 'object', 'additionalProperties' => true], 'max_cost_minor' => ['type' => 'integer', 'minimum' => 0]]],
    'post:/api/v2/admin/search/reindex' => ['required' => [], 'properties' => ['type' => ['type' => 'string', 'default' => 'all']]],
     'post:/api/v2/b2b/companies/{id}/quotes' => ['required' => ['currency', 'lines'], 'properties' => ['currency' => ['type' => 'string'], 'lines' => ['type' => 'array', 'items' => ['type' => 'object']], 'expires_at' => ['type' => 'string'], 'purchase_order_number' => ['type' => 'string'], 'payment_terms_days' => ['type' => 'integer']]],
     'post:/api/v2/b2b/companies/{id}/orders/validate-bulk' => ['required' => ['lines'], 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['variant_id', 'qty']]]]],
    'post:/api/v2/b2b/contracts/{id}/approve' => ['required' => [], 'properties' => []],
    'post:/api/v2/b2b/orders/{id}/approve' => ['required' => [], 'properties' => []],
    'post:/api/v2/b2b/orders/{id}/approve-company' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/v2/b2b/orders/{id}/draft' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/v2/b2b/orders/{id}/reject' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/v2/b2b/orders/{id}/submit' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/v2/b2b/orders/{id}/transition' => ['required' => ['state'], 'properties' => ['state' => ['type' => 'string'], 'reason' => ['type' => 'string']]],
    'post:/api/v2/b2b/quotes/{id}/convert' => ['required' => [], 'properties' => []],
    'post:/api/v2/b2b/quotes/{id}/{action}' => ['required' => [], 'properties' => ['reason' => ['type' => 'string']]],
    'post:/api/v2/headless/pages' => ['required' => ['slug', 'title'], 'properties' => ['slug' => ['type' => 'string'], 'title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'locale' => ['type' => 'string'], 'status' => ['type' => 'string']]],
    'post:/api/webhooks/shipping/dhl' => ['required' => ['event_id', 'status'], 'properties' => ['event_id' => ['type' => 'string'], 'status' => ['type' => 'string']]],
    'post:/api/webhooks/shipping/{provider}' => ['required' => ['event_id', 'status'], 'properties' => ['event_id' => ['type' => 'string'], 'status' => ['type' => 'string']]],
     'post:/api/webhooks/{provider}' => ['required' => [], 'properties' => ['event_id' => ['type' => 'string', 'description' => 'Provider-defined event identifier; the remaining signed payload is intentionally provider-owned.']]],
     'post:/api/v2/admin/vendors' => ['required' => ['code', 'name'], 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'profile' => ['type' => 'object', 'additionalProperties' => true]]],
     'post:/api/admin/vendors' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 255], 'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,79}$'], 'status' => ['type' => 'string', 'enum' => ['active', 'suspended'], 'default' => 'active'], 'commission_bps' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000, 'default' => 0], 'contact_email' => ['type' => 'string', 'format' => 'email', 'nullable' => true], 'description' => ['type' => 'string', 'nullable' => true], 'owner_customer_id' => ['type' => 'string', 'nullable' => true]]],
     'put:/api/admin/vendors/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 255], 'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,79}$'], 'status' => ['type' => 'string', 'enum' => ['active', 'suspended']], 'commission_bps' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000], 'contact_email' => ['type' => 'string', 'format' => 'email', 'nullable' => true], 'description' => ['type' => 'string', 'nullable' => true], 'owner_customer_id' => ['type' => 'string', 'nullable' => true]]],
     'patch:/api/v2/admin/vendors/{id}' => ['required' => ['version'], 'properties' => ['version' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'profile' => ['type' => 'object', 'additionalProperties' => true]]],
     'post:/api/v2/admin/vendors/{id}/status' => ['required' => ['status'], 'properties' => ['status' => ['type' => 'string', 'enum' => ['pending', 'active', 'suspended', 'closed']]]],
     'post:/api/v2/admin/vendors/{vendor}/catalog' => ['required' => ['product_id', 'visibility'], 'properties' => ['product_id' => ['type' => 'string'], 'visibility' => ['type' => 'string', 'enum' => ['draft', 'visible', 'hidden']], 'version' => ['type' => 'integer']]],
     'post:/api/v2/admin/commissions' => ['required' => ['vendor_id', 'gross_minor', 'rate_bps', 'currency'], 'properties' => ['vendor_id' => ['type' => 'string'], 'gross_minor' => ['type' => 'integer', 'minimum' => 0], 'rate_bps' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000], 'currency' => ['type' => 'string'], 'order_id' => ['type' => 'string']]],
     'post:/api/v2/admin/payouts' => ['required' => ['vendor_id', 'amount_minor', 'currency'], 'properties' => ['vendor_id' => ['type' => 'string'], 'amount_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string']]],
     'post:/api/v2/admin/payouts/{id}/transition' => ['required' => ['state'], 'properties' => ['state' => ['type' => 'string', 'enum' => ['eligible', 'held', 'processing', 'paid']]]],
     'post:/api/v2/admin/orders/{id}/seller-split' => ['required' => ['currency', 'parts'], 'properties' => ['currency' => ['type' => 'string'], 'parts' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]]],
     'put:/api/auth/me' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email']]],
     'post:/api/auth/reset-password' => ['required' => ['token', 'password'], 'properties' => ['token' => ['type' => 'string'], 'password' => ['type' => 'string', 'format' => 'password']]],
     'post:/api/auth/verify-email' => ['required' => ['token'], 'properties' => ['token' => ['type' => 'string']]],
     'post:/api/auth/verify-email/resend' => ['required' => [], 'properties' => []],
     'post:/api/categories' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string']]],
     'put:/api/categories/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string']]],
     'post:/api/posts' => ['required' => ['title'], 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'status' => ['type' => 'string']]],
     'put:/api/posts/{id}' => ['required' => [], 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'status' => ['type' => 'string']]],
     'post:/api/products' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'type' => ['type' => 'string']]],
     'put:/api/products/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string']]],
     'put:/api/profile' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'phone' => ['type' => 'string']]],
     'put:/api/profile/password' => ['required' => ['password'], 'properties' => ['current_password' => ['type' => 'string', 'format' => 'password'], 'password' => ['type' => 'string', 'format' => 'password']]],
     'put:/api/settings' => ['required' => [], 'properties' => ['settings' => ['type' => 'object', 'additionalProperties' => true]]],
     'post:/api/tags' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string']]],
     'put:/api/tags/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string']]],
     'post:/api/upload' => ['required' => [], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]],
     'post:/api/upload/avatar' => ['required' => [], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]],
     'post:/api/users' => ['required' => ['name', 'email'], 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'], 'role' => ['type' => 'string']]],
     'put:/api/users/{id}' => ['required' => [], 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'], 'role' => ['type' => 'string']]],
     'post:/api/admin/ecosystem/install' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
     'post:/api/admin/ecosystem/update' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
     'post:/api/admin/ecosystem/pro/install' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string'], 'version' => ['type' => 'string', 'nullable' => true]]],
     'post:/api/admin/ecosystem/pro/update' => ['required' => ['id'], 'properties' => ['id' => ['type' => 'string'], 'version' => ['type' => 'string', 'nullable' => true]]],
     'post:/api/admin/ecosystem/pro-artifacts/download' => ['required' => ['token'], 'properties' => ['token' => ['type' => 'string']]],
     'post:/api/admin/extensions/license/import' => ['required' => ['license'], 'properties' => ['license' => ['type' => 'object', 'additionalProperties' => true]]],
 ];
$responseDefinitions = [
    'get:/api/admin/inventory/{variant}' => 'InventoryItem',
    'put:/api/admin/inventory/{variant}' => 'InventoryItem',
    'post:/api/admin/inventory/{variant}/transfer' => 'InventoryTransfer',
    'post:/api/v2/inventory/{variant}/atp' => 'InventoryReservation',
    'get:/api/v2/catalog/product-types' => 'ProductTypeList',
    'get:/api/v2/catalog/tags' => 'CatalogTagList',
    'post:/api/admin/products/import' => 'ProductCsvImportResult',
    'get:/api/admin/launch/status' => 'LaunchStatus',
    'get:/api/admin/providers/status' => 'ProviderStatus',
    'get:/api/admin/providers/verify' => 'ProviderVerification',
    'post:/api/admin/launch/seed-demo' => 'LaunchSeedResult',
    'get:/api/v2/admin/dashboard/bi' => 'DashboardBi',
];
$paginatedPaths = ['/api/orders/{id}/returns', '/api/account/orders', '/api/account/returns', '/api/admin/analytics/events', '/api/v2/admin/reports/failures', '/api/v2/admin/operations', '/api/products'];
$bodylessWritePaths = [
     '/api/admin/storefront/config/publish', '/api/admin/storefront/config/restore/{revision}',
    '/api/auth/logout', '/api/admin/fulfillment/{id}/deliver', '/api/admin/fulfillment/{id}/cancel',
    '/api/admin/returns/{id}/complete', '/api/admin/returns/{id}/approve', '/api/admin/returns/{id}/approve-refund',
     '/api/admin/pages/{id}/publish', '/api/admin/pages/{id}/restore/{revision}',
     '/api/admin/pages/{id}/autosave', '/api/admin/pages/{id}/merge/{revision}',
    '/api/v2/shipments/{id}/cancel', '/api/v2/payments/{id}/void',
    '/api/account/loyalty/{order}/earn', '/api/account/privacy/anonymize', '/api/account/subscriptions/{id}/renew',
     '/api/admin/invoices/{id}/issue',
     '/api/admin/vendor-payouts/{id}/mark-paid', '/api/admin/vendor-payouts/{id}/cancel',
     '/api/v2/admin/exports/{id}/retry', '/api/v2/admin/operations/{id}/retry', '/api/v2/admin/search/reindex', '/api/v2/b2b/contracts/{id}/approve',
    '/api/v2/b2b/orders/{id}/approve', '/api/v2/b2b/orders/{id}/approve-company', '/api/v2/b2b/orders/{id}/draft',
    '/api/v2/b2b/orders/{id}/reject', '/api/v2/b2b/orders/{id}/submit', '/api/v2/b2b/quotes/{id}/convert',
     '/api/v2/b2b/quotes/{id}/{action}',
      '/api/admin/coupons/{id}/disable', '/api/admin/orders/{id}/downloads', '/api/admin/smart/rescue/{id}',
     '/api/admin/ops-alerts/{kind}/ack',
     '/api/v2/admin/ai/copilot/queue/{id}/approve', '/api/v2/admin/ai/copilot/queue/{id}/reject',
     '/api/admin/launch/seed-demo',
 ];
$commerceScopePaths = [
     '/api/admin/storefront/config', '/api/admin/storefront/config/preview', '/api/admin/storefront/config/publish', '/api/admin/storefront/config/restore/{revision}', '/api/admin/storefront/config/revisions', '/api/admin/storefront/config/revisions/diff', '/api/admin/storefront/themes', '/api/admin/storefront/vertical-presets', '/api/admin/storefront/config/apply-preset',
     '/api/storefront/config', '/api/pages/{slug}', '/api/menus/{key}', '/api/headless/graphql',
    '/api/admin/pages', '/api/admin/pages/{id}', '/api/admin/pages/{id}/preview',
    '/api/admin/pages/{id}/blocks', '/api/admin/blocks/{id}', '/api/admin/cms-revisions/{type}/{id}',
     '/api/admin/pages/{id}/publish', '/api/admin/pages/{id}/restore/{revision}', '/api/v2/headless/pages',
     '/api/admin/pages/{id}/autosave', '/api/admin/pages/{id}/merge/{revision}', '/api/admin/cms-revisions/{type}/{id}/diff',
    '/api/admin/products', '/api/admin/products/{id}', '/api/admin/product-components',
    '/api/admin/products/export.csv', '/api/admin/products/import',
    '/api/admin/digital-assets', '/api/admin/catalog/import', '/api/v2/admin/catalog/imports',
    '/api/admin/products/{id}/options', '/api/admin/products/{product}/options/{option}/values',
    '/api/admin/products/{id}/media', '/api/admin/products/{id}/media/order',
     '/api/admin/products/{product}/media/{media}', '/api/admin/products/{id}/variants',
     '/api/admin/products/{id}/lifecycle', '/api/admin/products/{id}/voucher-codes',
     '/api/v2/admin/warehouses/reservations', '/api/v2/admin/warehouses/reservations/{id}/release',
     '/api/admin/orders', '/api/admin/orders/{id}', '/api/admin/orders/{id}/transition',
     '/api/admin/orders/{id}/cancel',
     '/api/v2/catalog/product-types', '/api/v2/catalog/tags',
     '/api/v2/admin/dashboard/bi',
];
$idempotencyRequired = [
     '/api/admin/storefront/config/publish', '/api/admin/storefront/config/restore/{revision}', '/api/admin/storefront/config/apply-preset',
    '/api/checkout/place', '/api/v2/payments/{id}/capture', '/api/v2/payments/{id}/refund', '/api/v2/payments/{id}/void',
    '/api/v2/shipping/rates', '/api/v2/shipments', '/api/v2/shipments/{id}/cancel',
    '/api/admin/fulfillment/allocate', '/api/admin/fulfillment/{id}/ship', '/api/admin/fulfillment/{id}/deliver', '/api/admin/fulfillment/{id}/cancel',
    '/api/admin/returns', '/api/admin/returns/{id}/complete', '/api/admin/returns/{id}/approve', '/api/admin/returns/{id}/inspect', '/api/admin/returns/{id}/approve-refund', '/api/admin/returns/{id}/reject', '/api/admin/returns/{id}/label',
    '/api/account/loyalty/{order}/earn', '/api/account/loyalty/redeem', '/api/account/loyalty/expire',
    '/api/account/subscriptions', '/api/account/subscriptions/{id}/transition', '/api/account/subscriptions/{id}/renew',
    '/api/account/privacy/anonymize', '/api/account/consents', '/api/v2/inventory/{variant}/atp', '/api/admin/inventory/{variant}/transfer',
     '/api/admin/pages/{id}/publish', '/api/admin/pages/{id}/restore/{revision}', '/api/admin/orders/{id}/refund', '/api/admin/orders/{id}/cancel',
     '/api/admin/products/{id}/lifecycle', '/api/admin/products/{id}/voucher-codes',
     '/api/v2/admin/warehouses/reservations', '/api/v2/admin/warehouses/reservations/{id}/release',
];
$idempotencyRequired[] = '/api/v2/payments/{id}/authorize';
$idempotencyRequired[] = '/api/v2/admin/catalog/imports';
$idempotencyRequired[] = '/api/admin/products/import';
$idempotencyRequired[] = '/api/admin/products/{id}/voucher-codes';
$idempotencyRequired[] = '/api/admin/pages/{id}/autosave';
$idempotencyRequired[] = '/api/admin/pages/{id}/merge/{revision}';
$idempotencyRequired = array_merge($idempotencyRequired, ['/api/v2/admin/vendors', '/api/v2/admin/vendors/{id}', '/api/v2/admin/vendors/{id}/status', '/api/v2/admin/vendors/{vendor}/catalog', '/api/v2/admin/commissions', '/api/v2/admin/payouts', '/api/v2/admin/payouts/{id}/transition', '/api/v2/admin/orders/{id}/seller-split']);
$idempotencyRequired[] = '/api/v2/b2b/companies/{id}/orders/validate-bulk';
$idempotencyRequired[] = '/api/admin/launch/seed-demo';
$idempotencyRequired[] = '/api/admin/vendors/{id}/payouts';
foreach ($matches as $match) {
    $method = strtolower($match[1]);
    $path = $match[2];
    $operationId = $method . '_' . trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $path), '_');
    $isPublic = !str_contains($match[0], 'auth') && !str_contains($match[0], 'cap:') && !str_starts_with($path, '/api/admin/') && !str_starts_with($path, '/api/v2/admin/') && !str_starts_with($path, '/api/v2/payments/') && !str_contains($path, '/webhooks/');
    $isWrite = in_array($method, ['post', 'put', 'patch', 'delete'], true);
    $responses = [
        '200' => ['$ref' => '#/components/responses/Success'],
        '400' => ['$ref' => '#/components/responses/BadRequest'],
        '401' => ['$ref' => '#/components/responses/Unauthorized'],
        '403' => ['$ref' => '#/components/responses/Forbidden'],
        '404' => ['$ref' => '#/components/responses/NotFound'],
        '409' => ['$ref' => '#/components/responses/Conflict'],
        '422' => ['$ref' => '#/components/responses/ValidationError'],
        '429' => ['$ref' => '#/components/responses/RateLimited'],
        '500' => ['$ref' => '#/components/responses/ServerError'],
    ];
    if ($method === 'post') $responses['201'] = ['$ref' => '#/components/responses/Created'];
    $apiVersion = str_starts_with($path, '/api/v2/') ? 'v2' : 'v1';
    $operation = [
        'operationId' => $operationId,
        'summary' => ucfirst($method) . ' ' . $path,
        'responses' => $responses,
        'x-route-evidence' => 'routes/api.php',
        'x-api-version' => $apiVersion,
        'x-client-contract' => 1,
        'deprecated' => false,
        'x-deprecation' => ['status' => 'not_deprecated', 'replacement' => null],
        'x-request-schema' => 'Request_' . $operationId,
        'x-response-envelope' => '#/components/schemas/SuccessEnvelope',
        'x-stable-error-codes' => ['SCOPE_ERROR', 'VALIDATION_ERROR', 'INVALID_ARGUMENT', 'INVALID_REQUEST', 'REQUEST_ERROR', 'UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'CONFLICT', 'RATE_LIMITED', 'INTERNAL_ERROR'],
    ];
    $requestKey = $method . ':' . $path;
    $knownRequest = array_key_exists($requestKey, $requestDefinitions);
    $definition = $requestDefinitions[$requestKey] ?? ['required' => [], 'properties' => []];
    $requestSchemas['Request_' . $operationId] = ['type' => 'object', 'additionalProperties' => !$knownRequest, 'required' => $definition['required'], 'properties' => $definition['properties'], 'description' => $knownRequest ? 'Fields accepted by the route request validator.' : 'Request fields are validated by the route controller; this generator does not introspect that validator.'];
    if (isset($definition['example'])) $requestSchemas['Request_' . $operationId]['example'] = $definition['example'];
    $middlewareText = $match[0];
    $hasAuth = str_contains($middlewareText, 'auth') || str_contains($middlewareText, '$staff') || str_contains($middlewareText, '$reportStaff') || str_contains($middlewareText, '$searchStaff') || str_contains($middlewareText, '$growthStaff') || str_contains($middlewareText, '$analyticsStaff') || str_contains($middlewareText, '$smartStaff') || str_contains($middlewareText, '$devStaff');
    $hasFeGuard = str_contains($middlewareText, '$fe');
    if (!$isPublic && !$hasAuth && str_contains($path, '/webhooks/')) {
        $operation['security'] = [['webhookHmac' => []]];
        $operation['x-authentication'] = 'webhook_hmac';
    } elseif (!$isPublic) {
        $operation['security'] = [['bearerAuth' => [], 'feSharedSecret' => []]];
        $operation['x-authentication'] = 'bearer';
    } elseif ($hasFeGuard) {
        $operation['security'] = [['feSharedSecret' => []]];
        $operation['x-authentication'] = 'fe_shared_secret';
    } else {
        $operation['x-authentication'] = 'public';
    }
    preg_match_all('~cap:([a-zA-Z0-9_-]+)~', $middlewareText, $capabilities);
    $capabilityGates = array_values(array_unique($capabilities[1] ?? []));
    foreach (['$reportStaff' => 'reports', '$searchStaff' => 'search', '$exportStaff' => 'exports', '$analyticsStaff' => 'analytics', '$cmsStaff' => 'cms'] as $middlewareVariable => $capability) {
        if (str_contains($middlewareText, $middlewareVariable)) {
            $capabilityGates[] = $capability;
        }
    }
    $operation['x-capability-gates'] = array_values(array_unique($capabilityGates));
    $operation['x-idempotency'] = $isWrite
        ? ['header' => 'Idempotency-Key', 'required' => in_array($path, $idempotencyRequired, true), 'replay' => 'same key and equivalent request returns the original result']
        : ['required' => false, 'replay' => 'safe read'];
    if ($isWrite && in_array($path, $idempotencyRequired, true)) $operation['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]];
    if (in_array($path, ['/api/carts/{id}/lines', '/api/carts/{id}/quote', '/api/checkout/place', '/api/v2/checkout/quote'], true)) {
        $operation['parameters'][] = ['name' => 'X-Cart-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']];
        $operation['x-cart-access'] = 'SHA-256 hashed bearer token returned once when the cart is created';
    }
    if (str_contains($path, '/webhooks/')) {
        $operation['x-webhook-authentication'] = 'HMAC over the raw request body';
        $operation['x-replay-protection'] = 'provider/event identifier is idempotent';
    }
     if ($path === '/api/headless/graphql' && $method === 'post') {
        $operation['x-graphql-limits'] = ['max_query_bytes' => 20000, 'max_depth' => 8, 'max_complexity' => 80, 'body_only' => true];
    }
    if (preg_match('~throttle:(\\d+),(\\d+)~', $middlewareText, $limit) === 1) {
        $operation['x-rate-limit'] = ['requests' => (int) $limit[1], 'window_seconds' => (int) $limit[2]];
    } elseif (str_contains($middlewareText, '$graphqlThrottle')) {
        $operation['x-rate-limit'] = ['requests' => 15, 'window_seconds' => 60];
    } elseif (str_contains($middlewareText, '$authThrottle')) {
        $operation['x-rate-limit'] = ['requests' => 30, 'window_seconds' => 60];
    } elseif (str_contains($middlewareText, '$fe') || str_contains($middlewareText, '$staff')) {
        $operation['x-rate-limit'] = ['requests' => 60, 'window_seconds' => 60];
    }
    preg_match_all('~\\{([A-Za-z_][A-Za-z0-9_]*)\\}~', $path, $pathParameters);
    foreach (array_unique($pathParameters[1]) as $parameter) {
        $operation['parameters'][] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }
     if ($method === 'get' && in_array($path, $paginatedPaths, true)) {
        $operation['parameters'][] = ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]];
        $operation['parameters'][] = ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]];
        $operation['x-pagination'] = 'page/per_page; response meta contains page, per_page, total, last_page';
        $operation['x-read-consistency'] = 'eventual; successful writes invalidate relevant read caches';
        $operation['responses']['200'] = ['description' => 'Paginated response', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PaginatedEnvelope']]]];
        $operation['x-response-envelope'] = '#/components/schemas/PaginatedEnvelope';
    }
    if ($path === '/api/products' && $method === 'get') {
        foreach ([['category', 'string'], ['categories', 'string'], ['brand', 'string'], ['vendor', 'string'], ['tag', 'string'], ['type', 'string'], ['search', 'string'], ['sort', 'string'], ['min_price', 'integer'], ['max_price', 'integer'], ['in_stock', 'boolean']] as [$name, $type]) {
            $operation['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => $type]];
        }
        $operation['x-catalog-filters'] = ['category' => 'category slug', 'categories' => 'comma-separated category slugs', 'brand' => 'brand id', 'vendor' => 'vendor slug; suspended vendors return no products', 'tag' => 'case-insensitive tag slug', 'type' => 'comma-separated product types', 'search' => 'name, description, or tag text', 'min_price' => 'minor units inclusive', 'max_price' => 'minor units inclusive', 'in_stock' => 'available inventory or backorder', 'sort' => ['featured', 'price-asc', 'price-desc', 'name-asc', 'name-desc'], 'facets' => 'category, brand, type, tag counts and price bounds in pagination meta'];
    }
    $responseSchema = $responseDefinitions[$method . ':' . $path] ?? null;
    if (is_string($responseSchema)) {
        $operation['responses']['200'] = ['description' => 'Successful response', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $responseSchema . 'Envelope']]]];
        $operation['x-response-envelope'] = '#/components/schemas/' . $responseSchema . 'Envelope';
    }
    if ($isWrite && !str_contains($path, '/webhooks/') && !is_string($responseSchema)) {
        $operation['responses']['200'] = ['description' => 'Successful response', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WriteResultEnvelope']]]];
        $operation['x-response-envelope'] = '#/components/schemas/WriteResultEnvelope';
    }
    if ($isWrite && str_contains($path, '/webhooks/')) {
        $operation['responses']['200'] = ['description' => 'Provider acknowledgement', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ProviderWebhookResultEnvelope']]]];
        $operation['x-response-envelope'] = '#/components/schemas/ProviderWebhookResultEnvelope';
        $operation['x-provider-owned-fields'] = ['signed_payload' => 'Provider-defined and intentionally opaque; only event_id is consumed by this API.'];
    }
    if ($path === '/api/admin/vendor-payouts/{id}/export.csv' && $method === 'get') {
        $operation['responses']['200'] = [
            'description' => 'UTF-8 BOM bank-bulk CSV download',
            'headers' => [
                'Content-Disposition' => ['schema' => ['type' => 'string']],
                'X-Export-Rows' => ['schema' => ['type' => 'integer']],
            ],
            'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
        ];
        $operation['x-response-envelope'] = 'text/csv';
        $operation['x-csv-export'] = ['encoding' => 'UTF-8 with BOM', 'columns' => ['payout_id', 'vendor', 'amount_minor', 'currency', 'period_start', 'period_end', 'status']];
        $operation['x-read-only'] = true;
    }
    if ($path === '/api/admin/products/export.csv' && $method === 'get') {
        $operation['responses']['200'] = [
            'description' => 'UTF-8 BOM CSV download',
            'headers' => [
                'Content-Disposition' => ['schema' => ['type' => 'string']],
                'X-Export-Rows' => ['schema' => ['type' => 'integer']],
                'X-Export-Total' => ['schema' => ['type' => 'integer']],
                'X-Export-Truncated' => ['schema' => ['type' => 'string', 'enum' => ['0', '1']]],
            ],
            'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
        ];
        $operation['parameters'][] = ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 10000]];
        $operation['x-response-envelope'] = 'text/csv';
        $operation['x-csv-export'] = ['max_rows' => 10000, 'encoding' => 'UTF-8 with BOM', 'columns' => ['sku', 'name', 'slug', 'status', 'price_minor', 'compare_price_minor', 'stock', 'description', 'category', 'min_tier', 'allow_backorder', 'is_preorder']];
    }
    if (in_array($path, ['/api/v2/admin/reports/overview', '/api/v2/admin/reports/failures', '/api/v2/admin/reports/advanced', '/api/admin/analytics/events', '/api/admin/analytics/export'], true)) {
        $operation['parameters'][] = ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date-time']];
        $operation['parameters'][] = ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date-time']];
        $operation['x-date-window'] = 'bounded server-side; invalid or oversized windows are rejected';
    }
    if (in_array($path, $commerceScopePaths, true)) {
        foreach ([['store_id', 'X-Store-Id'], ['channel_id', 'X-Channel-Id'], ['locale', 'X-Locale'], ['currency', 'X-Currency']] as [$name, $header]) {
            $operation['parameters'][] = ['name' => $header, 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Explicit CommerceScope ' . $name . '.'];
        }
        $operation['x-scope'] = 'CommerceScope headers are required; CMS records and revisions are isolated by store, channel, locale and currency.';
    }
    if (in_array($path, ['/api/products', '/api/products/{slug}', '/api/pages/{slug}', '/api/menus/{key}', '/api/headless/graphql'], true)) {
        $operation['parameters'][] = ['name' => 'locale', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['en', 'vi', 'ja', 'de', 'zh'], 'default' => 'en']];
        $operation['x-localization'] = 'locale query, X-Locale header, then Accept-Language where implemented';
    }
     if ($path === '/api/v2/admin/search') {
        $operation['parameters'][] = ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'maxLength' => 80]];
        $operation['parameters'][] = ['name' => 'type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['catalog', 'orders', 'customers'], 'default' => 'catalog']];
        $operation['x-search'] = ['facets' => 'type', 'max_query_length' => 80, 'index_lag_seconds' => 20, 'result_consistency' => 'eventual'];
     }
     if ($path === '/api/headless/schema' && $method === 'get') {
         $operation['responses']['200'] = ['description' => 'Read-only headless schema and limits', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/HeadlessSchemaEnvelope']]]];
         $operation['x-response-envelope'] = '#/components/schemas/HeadlessSchemaEnvelope';
         $operation['x-read-only'] = true;
     }
     if ($path === '/api/v2/admin/reports/contract' && $method === 'get') {
         $operation['responses']['200'] = ['description' => 'Provider-neutral reporting contract', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReportingContractEnvelope']]]];
         $operation['x-response-envelope'] = '#/components/schemas/ReportingContractEnvelope';
         $operation['x-read-only'] = true;
     }
     if ($path === '/api/v2/admin/ai/suggestions' && $method === 'post') {
         $operation['responses']['200'] = ['description' => 'Provider-neutral suggestion result', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AiSuggestionEnvelope']]]];
         $operation['x-response-envelope'] = '#/components/schemas/AiSuggestionEnvelope';
         $operation['x-ai-boundary'] = ['suggestions_only' => true, 'external_provider_calls' => false, 'autonomous_financial_mutations' => false, 'audit_required' => true, 'tenant_required' => true];
     }
     if ($path === '/api/admin/cms-revisions/{type}/{id}/diff' && $method === 'get') {
         foreach (['from', 'to'] as $name) $operation['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
     }
    if ($path === '/api/admin/payments/reconcile' && $method === 'post') {
        $operation['parameters'][] = ['name' => 'apply', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean', 'default' => false]];
         $operation['x-query-compatibility'] = 'The JSON apply field is canonical; query apply is retained for existing operational callers.';
    }
    if ($path === '/api/v2/admin/search/status') $operation['x-search-readiness'] = ['index_version' => true, 'lag_seconds' => true, 'checkpoint' => true, 'provider_neutral' => true];
    if ($path === '/api/v2/admin/search/reindex') $operation['x-search-reindex'] = ['async' => true, 'checkpoint' => true, 'dead_letter' => true, 'accepted_status' => 202];
    if (in_array($path, ['/api/v2/admin/reports/overview', '/api/v2/admin/reports/failures', '/api/v2/admin/reports/advanced', '/api/admin/analytics/events', '/api/admin/analytics/export'], true)) {
        $operation['parameters'][] = ['name' => 'store_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
        $operation['parameters'][] = ['name' => 'channel_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
        $operation['x-scope'] = 'store_id/channel_id; records are isolated by explicit scope';
    }
    if ($path === '/api/v2/admin/dashboard/bi' && $method === 'get') {
        $operation['parameters'][] = ['name' => 'range', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['7d', '30d', '90d'], 'default' => '30d']];
        $operation['x-cache'] = 'shared read cache for 60s keyed by range and commerce scope; aggregate failures degrade to zeros instead of 500';
    }
    if ($path === '/api/admin/products/import' && $method === 'post') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/ProductCsvImportUpload']],
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Request_' . $operationId]],
            ],
        ];
        $operation['x-request-body'] = 'multipart/form-data';
        $operation['parameters'][] = ['name' => 'dry_run', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean', 'default' => false], 'description' => 'Validate and report without writing.'];
        $operation['x-csv-limits'] = ['max_bytes' => 2097152, 'max_rows' => 500, 'encoding' => 'UTF-8 with optional BOM', 'upsert_key' => 'sku'];
    } elseif ($path === '/api/admin/products/{id}/media' && $method === 'post') {
        $operation['requestBody'] = [
            'required' => $definition['required'] !== [],
            'content' => ['multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/ProductMediaUpload']]],
        ];
        $operation['x-request-body'] = 'multipart/form-data';
    } elseif ($path === '/api/headless/graphql' && $method === 'post') {
        $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/GraphQlRequest']]]];
        $operation['x-request-body'] = 'application/json';
        $operation['responses']['200'] = ['description' => 'GraphQL response', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/GraphQlResponse']]]];
        $operation['x-response-envelope'] = '#/components/schemas/GraphQlResponse';
    } elseif ($method === 'delete' || (in_array($path, $bodylessWritePaths, true) && $path !== '/api/admin/payments/reconcile')) {
        $operation['x-request-body'] = 'not_applicable';
    } elseif (array_key_exists($requestKey, $requestDefinitions)) {
        $operation['requestBody'] = [
            'required' => $definition['required'] !== [],
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Request_' . $operationId]]],
        ];
        $operation['x-request-body'] = 'application/json';
        $operation['x-request-schema'] = 'Request_' . $operationId;
    } elseif ($isWrite) {
        // Do not claim a body shape or requiredness when the controller was not mapped.
        $operation['x-request-body'] = 'unknown';
    } else {
        $operation['x-request-body'] = 'not_applicable';
    }
    $paths[$path][$method] = $operation;
}
ksort($paths);
foreach ($paths as &$operations) {
    ksort($operations);
}
unset($operations);

$document = [
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'SiroCommerce API',
        'version' => $commerceVersion,
        'description' => 'Version-safe SiroCommerce API contract generated from routes/api.php. REST envelopes use contract version 1; /api/v2 routes are marked explicitly.',
        'x-be2-contract' => [
            'scope' => ['tenant' => 'X-Tenant-Id', 'site' => ['X-Store-Id', 'X-Channel-Id', 'X-Locale', 'X-Currency'], 'conflict' => 'reject'],
            'idempotency' => ['header' => 'Idempotency-Key', 'replay' => 'same fingerprint returns original result', 'conflict' => '409'],
            'external_status' => 'BLOCKED_EXTERNAL', 'event_schema' => 'name.v1',
        ],
    ],
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            'feSharedSecret' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Siro-FE'],
            'webhookHmac' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Webhook-Signature'],
        ],
        'schemas' => [
            'MutationRequest' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Endpoint-specific JSON request. See the endpoint FormRequest for accepted fields.'],
            'PaginationMeta' => ['type' => 'object', 'required' => ['page', 'per_page', 'total', 'last_page'], 'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                 'total' => ['type' => 'integer', 'minimum' => 0], 'last_page' => ['type' => 'integer', 'minimum' => 1], 'facets' => ['$ref' => '#/components/schemas/CatalogFacets'],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            ]],
            'SuccessEnvelope' => ['type' => 'object', 'required' => ['success', 'message', 'data', 'meta'], 'properties' => [
                 'success' => ['type' => 'boolean', 'const' => true], 'message' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/UnknownData'],
                'meta' => ['type' => 'object', 'additionalProperties' => true],
            ]],
            'PaginatedEnvelope' => ['type' => 'object', 'required' => ['success', 'message', 'data', 'meta'], 'properties' => [
                 'success' => ['type' => 'boolean', 'const' => true], 'message' => ['type' => 'string'], 'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UnknownData']],
                'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
            ]],
            'GraphQlRequest' => ['type' => 'object', 'required' => ['query'], 'properties' => [
                'query' => ['type' => 'string', 'maxLength' => 20000],
                'variables' => ['type' => 'object', 'additionalProperties' => true],
                'extensions' => ['type' => 'object', 'additionalProperties' => true],
            ], 'additionalProperties' => false],
             'GraphQlResponse' => ['oneOf' => [
                ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'additionalProperties' => true]]],
                ['type' => 'object', 'required' => ['errors'], 'properties' => ['errors' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GraphQlError']]]],
            ]],
            'ProductMediaUpload' => ['type' => 'object', 'required' => ['file'], 'properties' => [
                 'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'JPEG, PNG, WebP, AVIF, MP4, or WebM. Image/video limits are configurable server-side.'],
                'alt' => ['type' => 'string'], 'title' => ['type' => 'string'], 'role' => ['type' => 'string'], 'sort' => ['type' => 'integer'],
            ]],
            'ProductCsvImportUpload' => ['type' => 'object', 'required' => ['file'], 'properties' => [
                'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'CSV file, max 2MB and 500 data rows. UTF-8 with optional BOM.'],
                'csv' => ['type' => 'string', 'maxLength' => 2097152, 'description' => 'Inline CSV alternative when multipart upload is not used.'],
            ]],
            'ProductCsvImportRowError' => ['type' => 'object', 'required' => ['row', 'error'], 'properties' => ['row' => ['type' => 'integer', 'minimum' => 1], 'error' => ['type' => 'string']]],
            'ProductCsvImportResult' => ['type' => 'object', 'required' => ['created', 'updated', 'skipped', 'errors', 'dry_run', 'rows_seen'], 'properties' => [
                'created' => ['type' => 'integer', 'minimum' => 0],
                'updated' => ['type' => 'integer', 'minimum' => 0],
                'skipped' => ['type' => 'integer', 'minimum' => 0],
                'errors' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProductCsvImportRowError']],
                'dry_run' => ['type' => 'boolean'],
                'rows_seen' => ['type' => 'integer', 'minimum' => 0],
            ]],
            'ProductCsvImportResultEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/ProductCsvImportResult']]],
            'ReturnLineRequest' => ['type' => 'object', 'required' => ['order_line_id', 'qty'], 'additionalProperties' => false, 'properties' => ['order_line_id' => ['type' => 'string'], 'qty' => ['type' => 'integer', 'minimum' => 1], 'reason' => ['type' => 'string'], 'disposition' => ['type' => 'string', 'enum' => ['restock', 'damaged']], 'condition' => ['type' => 'string']]],
            'InventoryItem' => ['type' => 'object', 'required' => ['variant_id', 'source_id', 'on_hand', 'reserved'], 'properties' => ['variant_id' => ['type' => 'string'], 'source_id' => ['type' => 'string'], 'on_hand' => ['type' => 'integer'], 'reserved' => ['type' => 'integer'], 'available' => ['type' => 'integer'], 'low_threshold' => ['type' => 'integer']]],
            'InventoryTransfer' => ['type' => 'object', 'required' => ['id', 'variant_id', 'from_source_id', 'to_source_id', 'qty', 'state'], 'properties' => ['id' => ['type' => 'string'], 'variant_id' => ['type' => 'string'], 'from_source_id' => ['type' => 'string'], 'to_source_id' => ['type' => 'string'], 'qty' => ['type' => 'integer'], 'state' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string']]],
             'InventoryReservation' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'ATP reservation result; fields are the reservation allocation contract.'],
              'CommerceScope' => ['type' => 'object', 'required' => ['store_id', 'channel_id', 'locale', 'currency'], 'properties' => ['tenant_id' => ['type' => 'string'], 'store_id' => ['type' => 'string'], 'channel_id' => ['type' => 'string'], 'locale' => ['type' => 'string'], 'currency' => ['type' => 'string']]],
              'DeliveryMethod' => ['type' => 'object', 'required' => ['code', 'label', 'base_cost_minor', 'currency'], 'properties' => ['code' => ['type' => 'string'], 'label' => ['type' => 'string'], 'base_cost_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string'], 'rule' => ['type' => 'object', 'additionalProperties' => true]]],
              'PaymentAvailability' => ['type' => 'object', 'required' => ['method', 'available', 'status'], 'properties' => ['method' => ['type' => 'string'], 'available' => ['type' => 'boolean'], 'status' => ['type' => 'string'], 'reason' => ['type' => 'string']]],
              'EventEnvelope' => ['type' => 'object', 'required' => ['event_id', 'name', 'occurred_at', 'correlation_id', 'causation_id', 'actor', 'payload', 'schema_version', 'redaction'], 'properties' => ['event_id' => ['type' => 'string'], 'name' => ['type' => 'string', 'pattern' => '^[a-z0-9_.-]+\\.v[0-9]+$'], 'occurred_at' => ['type' => 'string', 'format' => 'date-time'], 'correlation_id' => ['type' => 'string'], 'causation_id' => ['type' => 'string'], 'actor' => ['type' => 'string'], 'payload' => ['type' => 'object', 'additionalProperties' => true], 'schema_version' => ['type' => 'integer'], 'redaction' => ['type' => 'array', 'items' => ['type' => 'string']]]],
              'JobStatus' => ['type' => 'string', 'enum' => ['pending', 'running', 'succeeded', 'failed', 'blocked_external', 'dead_letter']],
              'ProductType' => ['type' => 'object', 'required' => ['type'], 'additionalProperties' => false, 'properties' => ['type' => ['type' => 'string', 'enum' => ['simple', 'configurable', 'grouped', 'bundle', 'virtual', 'downloadable', 'voucher']]]],
             'ProductTypeList' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProductType']],
             'CatalogTag' => ['type' => 'object', 'required' => ['slug', 'count'], 'additionalProperties' => false, 'properties' => ['slug' => ['type' => 'string'], 'count' => ['type' => 'integer', 'minimum' => 1]]],
             'CatalogTagList' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogTag']],
             'InventoryItemEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/InventoryItem']]],
            'InventoryTransferEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/InventoryTransfer']]],
              'InventoryReservationEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/InventoryReservation']]],
              'ProductTypeListEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/ProductTypeList']]],
             'CatalogTagListEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/CatalogTagList']]],
             'Money' => ['type' => 'object', 'required' => ['amount_minor', 'currency'], 'properties' => ['amount_minor' => ['type' => 'integer', 'minimum' => 0], 'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3]]],
             'CatalogFacetCount' => ['type' => 'object', 'required' => ['value', 'count'], 'properties' => ['value' => ['type' => 'string'], 'count' => ['type' => 'integer', 'minimum' => 0]]],
             'CatalogFacets' => ['type' => 'object', 'properties' => ['category' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogFacetCount']], 'brand' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogFacetCount']], 'type' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogFacetCount']], 'tag' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CatalogFacetCount']], 'price' => ['type' => 'object', 'properties' => ['min_minor' => ['type' => 'integer'], 'max_minor' => ['type' => 'integer']]]]],
             'WriteResult' => ['type' => 'object', 'description' => 'Stable write result fields. Endpoint-specific resource fields are listed by the controller/resource contract.', 'properties' => [
                 'id' => ['type' => 'string'], 'state' => ['type' => 'string'], 'status' => ['type' => 'string'], 'message' => ['type' => 'string'],
                 'ok' => ['type' => 'boolean'], 'queued' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'created_at' => ['type' => 'string', 'format' => 'date-time'],
             ]],
             'HeadlessSchemaEnvelope' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'required' => ['schema', 'limits'], 'properties' => ['schema' => ['type' => 'object', 'additionalProperties' => true], 'limits' => ['type' => 'object', 'properties' => ['max_query_bytes' => ['type' => 'integer'], 'max_depth' => ['type' => 'integer'], 'max_complexity' => ['type' => 'integer'], 'read_only' => ['type' => 'boolean']]]]]]],
             'AiSuggestionEnvelope' => ['type' => 'object', 'required' => ['success', 'message', 'data', 'meta'], 'properties' => ['success' => ['type' => 'boolean'], 'message' => ['type' => 'string'], 'data' => ['type' => 'object', 'required' => ['status', 'autonomous_mutation'], 'properties' => ['status' => ['type' => 'string', 'enum' => ['available', 'provider_unavailable', 'budget_exceeded', 'denied']], 'suggestion' => ['type' => 'object', 'additionalProperties' => true], 'usage' => ['type' => 'object', 'additionalProperties' => true], 'reason' => ['type' => 'string', 'nullable' => true], 'autonomous_mutation' => ['type' => 'boolean', 'const' => false]]], 'meta' => ['type' => 'object', 'additionalProperties' => true]]],
              'ReportingContractEnvelope' => ['type' => 'object', 'required' => ['success', 'message', 'data', 'meta'], 'properties' => ['success' => ['type' => 'boolean'], 'message' => ['type' => 'string'], 'data' => ['type' => 'object', 'additionalProperties' => true], 'meta' => ['type' => 'object', 'additionalProperties' => true]]],
              'DashboardBiSalesDay' => ['type' => 'object', 'required' => ['date', 'gross_minor', 'orders'], 'properties' => ['date' => ['type' => 'string', 'format' => 'date'], 'gross_minor' => ['type' => 'integer'], 'orders' => ['type' => 'integer', 'minimum' => 0]]],
              'DashboardBiSalesBlock' => ['type' => 'object', 'required' => ['currency', 'gross_minor', 'orders', 'aov_minor', 'refunds_minor', 'by_day'], 'properties' => [
                  'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                  'gross_minor' => ['type' => 'integer'], 'orders' => ['type' => 'integer', 'minimum' => 0],
                  'aov_minor' => ['type' => 'integer'], 'refunds_minor' => ['type' => 'integer'],
                  'by_day' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DashboardBiSalesDay']],
              ]],
              'DashboardBiSales' => ['oneOf' => [['$ref' => '#/components/schemas/DashboardBiSalesBlock'], ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DashboardBiSalesBlock']]]],
              'DashboardBiFunnel' => ['type' => 'object', 'required' => ['sessions', 'product_views', 'add_to_cart', 'checkout_started', 'orders'], 'properties' => [
                  'sessions' => ['type' => 'integer', 'minimum' => 0], 'product_views' => ['type' => 'integer', 'minimum' => 0],
                  'add_to_cart' => ['type' => 'integer', 'minimum' => 0], 'checkout_started' => ['type' => 'integer', 'minimum' => 0],
                  'orders' => ['type' => 'integer', 'minimum' => 0],
              ]],
              'DashboardBiTopProduct' => ['type' => 'object', 'required' => ['product_id', 'name', 'slug', 'qty', 'revenue_minor'], 'properties' => [
                  'product_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'slug' => ['type' => 'string'],
                  'qty' => ['type' => 'integer', 'minimum' => 0], 'revenue_minor' => ['type' => 'integer'],
              ]],
              'DashboardBiLowStock' => ['type' => 'object', 'required' => ['variant_id', 'name', 'sku', 'available'], 'properties' => [
                  'variant_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'sku' => ['type' => 'string'], 'available' => ['type' => 'integer'],
              ]],
              'DashboardBiOps' => ['type' => 'object', 'required' => ['failed_payments', 'pending_refunds', 'queue_dead_letters', 'open_ops_alerts'], 'properties' => [
                  'failed_payments' => ['type' => 'integer', 'minimum' => 0], 'pending_refunds' => ['type' => 'integer', 'minimum' => 0],
                  'queue_dead_letters' => ['type' => 'integer', 'minimum' => 0], 'open_ops_alerts' => ['type' => 'integer', 'minimum' => 0],
              ]],
              'DashboardBi' => ['type' => 'object', 'required' => ['range', 'window', 'sales', 'funnel', 'top_products', 'low_stock', 'ops', 'generated_at'], 'properties' => [
                  'range' => ['type' => 'string', 'enum' => ['7d', '30d', '90d']],
                  'window' => ['type' => 'object', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']]],
                  'sales' => ['$ref' => '#/components/schemas/DashboardBiSales'],
                  'sales_by_currency' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DashboardBiSalesBlock']],
                  'funnel' => ['$ref' => '#/components/schemas/DashboardBiFunnel'],
                  'top_products' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DashboardBiTopProduct']],
                  'low_stock' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DashboardBiLowStock']],
                  'ops' => ['$ref' => '#/components/schemas/DashboardBiOps'],
                  'generated_at' => ['type' => 'string', 'format' => 'date-time'],
              ]],
              'DashboardBiEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/DashboardBi']]],
             'WriteResultEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/WriteResult']]],
             'LaunchCheck' => ['type' => 'object', 'required' => ['key', 'state', 'hint', 'href', 'weight', 'detail'], 'properties' => [
                 'key' => ['type' => 'string', 'enum' => ['store_config', 'catalog', 'payments', 'shipping', 'mail', 'search', 'legal_pages', 'domain_tls']],
                 'state' => ['type' => 'string', 'enum' => ['ok', 'warn', 'fail']],
                 'hint' => ['type' => 'string'], 'href' => ['type' => 'string'], 'weight' => ['type' => 'number', 'enum' => [1, 0.5, 0]], 'detail' => ['type' => 'string'],
             ]],
             'LaunchStatus' => ['type' => 'object', 'required' => ['ready', 'score', 'checks'], 'properties' => [
                 'ready' => ['type' => 'boolean'], 'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                 'checks' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LaunchCheck']],
             ]],
             'LaunchStatusEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/LaunchStatus']]],
             'ProviderStatus' => ['type' => 'object', 'required' => ['payment', 'shipping', 'mail', 'search', 'ai'], 'properties' => [
                 'payment' => ['type' => 'object', 'required' => ['provider', 'mode', 'configured'], 'properties' => ['provider' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'configured' => ['type' => 'boolean']]],
                 'shipping' => ['type' => 'object', 'required' => ['provider', 'mode', 'configured'], 'properties' => ['provider' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'configured' => ['type' => 'boolean']]],
                 'mail' => ['type' => 'object', 'required' => ['provider', 'configured'], 'properties' => ['provider' => ['type' => 'string'], 'configured' => ['type' => 'boolean']]],
                 'search' => ['type' => 'object', 'required' => ['provider', 'url_configured', 'configured'], 'properties' => ['provider' => ['type' => 'string'], 'url_configured' => ['type' => 'boolean'], 'configured' => ['type' => 'boolean']]],
                 'ai' => ['type' => 'object', 'required' => ['provider', 'budget_configured', 'configured'], 'properties' => ['provider' => ['type' => 'string'], 'budget_configured' => ['type' => 'boolean'], 'configured' => ['type' => 'boolean']]],
             ]],
             'ProviderStatusEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/ProviderStatus']]],
             'ProviderVerificationCheck' => ['type' => 'object', 'required' => ['name', 'type', 'configured', 'reachable', 'detail'], 'properties' => [
                 'name' => ['type' => 'string', 'enum' => ['stripe', 'vnpay', 'momo', 'ghn', 'viettelpost', 'dhl', 'meili', 'mail']],
                 'type' => ['type' => 'string', 'enum' => ['payment', 'shipping', 'search', 'mail']],
                 'configured' => ['type' => 'boolean'],
                 'reachable' => ['type' => 'boolean'],
                 'detail' => ['type' => 'string', 'description' => 'Short reachability detail; never contains credentials.'],
             ]],
             'ProviderVerification' => ['type' => 'object', 'required' => ['providers', 'certified'], 'properties' => [
                 'providers' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProviderVerificationCheck']],
                 'certified' => ['type' => 'boolean', 'description' => 'True when every configured provider is reachable; missing providers are not failures.'],
             ]],
             'ProviderVerificationEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/ProviderVerification']]],
             'LaunchSeedCounts' => ['type' => 'object', 'required' => ['theme', 'categories', 'products', 'variants', 'inventory', 'prices', 'pages', 'menus', 'menu_items'], 'properties' => [
                 'theme' => ['type' => 'integer', 'minimum' => 0], 'categories' => ['type' => 'integer', 'minimum' => 0], 'products' => ['type' => 'integer', 'minimum' => 0],
                 'variants' => ['type' => 'integer', 'minimum' => 0], 'inventory' => ['type' => 'integer', 'minimum' => 0], 'prices' => ['type' => 'integer', 'minimum' => 0],
                 'pages' => ['type' => 'integer', 'minimum' => 0], 'menus' => ['type' => 'integer', 'minimum' => 0], 'menu_items' => ['type' => 'integer', 'minimum' => 0],
             ]],
             'LaunchSeedResult' => ['type' => 'object', 'required' => ['created', 'skipped'], 'properties' => [
                 'created' => ['$ref' => '#/components/schemas/LaunchSeedCounts'], 'skipped' => ['$ref' => '#/components/schemas/LaunchSeedCounts'],
             ]],
             'LaunchSeedResultEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/LaunchSeedResult']]],
             'ProviderWebhookResult' => ['type' => 'object', 'description' => 'Provider acknowledgement. Provider-specific response fields are intentionally not certified here.', 'properties' => ['event_id' => ['type' => 'string'], 'status' => ['type' => 'string']]],
             'ProviderWebhookResultEnvelope' => ['allOf' => [['$ref' => '#/components/schemas/SuccessEnvelope']], 'properties' => ['data' => ['$ref' => '#/components/schemas/ProviderWebhookResult']]],
            'Error' => ['type' => 'object', 'required' => ['success', 'message', 'meta'], 'example' => ['success' => false, 'message' => 'Request could not be processed', 'meta' => ['timestamp' => '2026-01-01T00:00:00Z', 'error_code' => 'INVALID_REQUEST']], 'properties' => [
                'success' => ['type' => 'boolean', 'example' => false],
                'message' => ['type' => 'string'],
                'meta' => ['type' => 'object', 'required' => ['timestamp'], 'properties' => [
                    'timestamp' => ['type' => 'string', 'format' => 'date-time'], 'error_code' => ['$ref' => '#/components/schemas/ErrorCode'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ], 'additionalProperties' => true],
            ]],
            'GraphQlError' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => [
                 'code' => ['type' => 'string'], 'message' => ['type' => 'string'],
            ]],
            'ErrorCode' => ['type' => 'string', 'enum' => ['SCOPE_ERROR', 'VALIDATION_ERROR', 'INVALID_ARGUMENT', 'INVALID_REQUEST', 'REQUEST_ERROR', 'UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'CONFLICT', 'RATE_LIMITED', 'INTERNAL_ERROR']],
            'UnknownData' => ['description' => 'Endpoint-specific success data not yet mapped by the generator. Consumers must treat this payload as opaque.', 'nullable' => true, 'oneOf' => [['type' => 'object', 'additionalProperties' => true], ['type' => 'array', 'items' => []], ['type' => 'string'], ['type' => 'number'], ['type' => 'boolean']]],
        ],
        'responses' => [
            'Success' => ['description' => 'Successful response', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessEnvelope']]]],
            'Created' => ['description' => 'Resource created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessEnvelope']]]],
            'NoContent' => ['description' => 'No content', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessEnvelope']]]],
            'BadRequest' => ['description' => 'Bad request', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'Unauthorized' => ['description' => 'Authentication required', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'Forbidden' => ['description' => 'Insufficient permissions', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'NotFound' => ['description' => 'Resource not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'Conflict' => ['description' => 'Resource conflict', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'ValidationError' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'RateLimited' => ['description' => 'Too many requests', 'headers' => ['Retry-After' => ['schema' => ['type' => 'integer']]], 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            'ServerError' => ['description' => 'Internal server error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
        ],
    ],
    'x-generated-from' => 'routes/api.php',
    'x-client-contract' => ['version' => 1, 'api_versions' => ['v1', 'v2'], 'pagination' => 'page/per_page', 'error' => 'envelope'],
    'x-compatibility' => ['current' => 'v1', 'supported' => ['v1', 'v2'], 'v1_policy' => 'additive changes only; /api remains compatible', 'deprecations' => []],
    'x-read-guarantees' => ['writes' => 'request completes after primary write', 'reads' => 'eventual for cached/search read models', 'search_index_lag_seconds' => 20],
    'x-scope-contract' => ['locale' => 'explicit locale where supported', 'currency' => 'endpoint/resource-specific ISO-4217 code', 'store_channel' => 'explicit store_id/channel_id on reporting/export reads'],
];
$document['components']['schemas'] += $requestSchemas;

$directory = dirname($output);
if (!is_dir($directory)) {
    mkdir($directory, 0775, true);
}
file_put_contents($output, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
file_put_contents($publicOutput, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
if (is_dir(dirname($adminOutput)) && getenv('SKIP_ADMIN_OPENAPI') !== '1') file_put_contents($adminOutput, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo 'openapi: generated ' . count($paths) . " paths at {$output}\n";
