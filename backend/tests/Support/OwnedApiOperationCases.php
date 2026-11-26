<?php

declare(strict_types=1);

namespace Tests\Support;

final class OwnedApiOperationCases
{
    /** @return array<string, array{string, string, string, int}> */
    public static function all(): array
    {
        $private = [
            'GET /api/auth/me', 'POST /api/auth/logout',
            'GET /api/wishlist', 'POST /api/wishlist', 'DELETE /api/wishlist/{product}',
            'POST /api/products/{id}/reviews', 'GET /api/account/orders', 'GET /api/account/orders/{id}',
            'GET /api/account/returns', 'GET /api/account/returns/{id}', 'GET /api/account/addresses',
            'GET /api/account/notification-preferences', 'PUT /api/account/notification-preferences',
            'GET /api/account/loyalty', 'POST /api/account/loyalty/{order}/earn',
            'POST /api/account/loyalty/redeem', 'POST /api/account/loyalty/expire',
            'POST /api/account/subscriptions', 'POST /api/account/subscriptions/{id}/transition',
            'POST /api/account/subscriptions/{id}/renew', 'GET /api/account/subscriptions', 'GET /api/account/subscriptions/{id}', 'GET /api/account/privacy/export',
            'POST /api/account/privacy/anonymize', 'POST /api/account/consents',
            'POST /api/admin/fulfillment/allocate', 'POST /api/admin/fulfillment/{id}/ship',
            'POST /api/admin/fulfillment/{id}/deliver', 'POST /api/admin/fulfillment/{id}/cancel',
            'GET /api/admin/fulfillment/{id}/track', 'POST /api/admin/returns',
            'POST /api/admin/returns/{id}/complete', 'POST /api/admin/returns/{id}/approve',
            'POST /api/admin/returns/{id}/inspect', 'POST /api/admin/returns/{id}/approve-refund',
            'POST /api/admin/returns/{id}/reject', 'POST /api/admin/returns/{id}/label',
            'POST /api/v2/shipments', 'GET /api/v2/shipments/{id}/tracking', 'POST /api/v2/shipments/{id}/cancel',
            'POST /api/admin/orders/{id}/downloads', 'GET /api/admin/inventory/{variant}',
            'PUT /api/admin/inventory/{variant}', 'POST /api/admin/inventory/{variant}/transfer',
            'PUT /api/admin/inventory/{variant}/priorities', 'POST /api/v2/inventory/{variant}/atp',
            'GET /api/admin/customers/{id}', 'GET /api/admin/customers/{id}/addresses',
            'POST /api/admin/customers/{id}/addresses', 'POST /api/admin/customers/{id}/privacy/legal-hold',
            'GET /api/admin/reviews', 'POST /api/admin/reviews/{id}/moderate',
            'POST /api/admin/b2b/companies',
            'POST /api/admin/b2b/companies/{id}/members', 'POST /api/admin/b2b/companies/{id}/approval-scopes',
            'POST /api/admin/b2b/companies/{id}/credit-account', 'GET /api/admin/b2b/companies/{id}/credit',
            'POST /api/admin/b2b/companies/{id}/contracts', 'POST /api/admin/b2b/companies/{id}/catalogs',
            'POST /api/admin/b2b/companies/{id}/catalogs/{catalog}/items',
             'POST /api/admin/b2b/companies/{id}/negotiated-prices', 'POST /api/v2/b2b/companies/{id}/quotes',
             'GET /api/v2/b2b/quotes/{id}', 'POST /api/v2/b2b/quotes/{id}/convert', 'POST /api/v2/b2b/quotes/{id}/{action}',
             'POST /api/v2/admin/catalog/imports', 'POST /api/admin/pages/{id}/publish', 'POST /api/admin/pages/{id}/restore/{revision}',
             'POST /api/v2/b2b/orders/{id}/approve', 'POST /api/v2/b2b/orders/{id}/transition',
            'POST /api/v2/b2b/orders/{id}/draft', 'POST /api/v2/b2b/orders/{id}/submit',
            'POST /api/v2/b2b/orders/{id}/approve-company', 'POST /api/v2/b2b/orders/{id}/reject',
            'GET /api/v2/b2b/companies/{id}/credit', 'POST /api/v2/b2b/contracts/{id}/approve',
        ];
        $public = [
            'GET /api/products/{id}/reviews', 'GET /api/orders/{id}', 'GET /api/orders/{id}/returns',
            'GET /api/v2/products/{id}/bundle-quote', 'GET /api/v2/downloads/{token}',
            'POST /api/v2/shipping/rates', 'POST /api/webhooks/shipping/dhl',
            'POST /api/webhooks/shipping/{provider}',
        ];
        $cases = [];
        foreach ($private as $operation) $cases[$operation] = self::caseFor($operation, 401);
        foreach ($public as $operation) {
            $expected = str_starts_with($operation, 'GET /api/products/') ? 200 : 422;
            if ($operation === 'GET /api/orders/{id}') $expected = 403;
            if ($operation === 'GET /api/orders/{id}/returns' || str_starts_with($operation, 'GET /api/v2/downloads')) $expected = 404;
            if (str_starts_with($operation, 'POST /api/webhooks/shipping/')) $expected = 401;
            $cases[$operation] = self::caseFor($operation, $expected);
        }
        return $cases;
    }

    /** @return array{string, string, string, int} */
    private static function caseFor(string $operation, int $expected): array
    {
        [$method, $path] = explode(' ', $operation, 2);
         $path = str_replace(['{id}', '{product}', '{order}', '{variant}', '{catalog}', '{token}', '{provider}', '{action}', '{revision}'], ['one', 'product-one', 'order-one', 'variant-one', 'catalog-one', 'not-a-token', 'unknown', 'submit', 'revision-one'], $path);
        return [$method, $path, $operation, $expected];
    }
}
