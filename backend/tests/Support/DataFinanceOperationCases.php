<?php

declare(strict_types=1);

namespace Tests\Support;

final class DataFinanceOperationCases
{
    /** @return array<string, array{0:string,1:string,2:int}> */
    public static function all(): array
    {
        $operations = [
            'GET /api/v2/admin/reports/overview', 'GET /api/v2/admin/reports/failures', 'GET /api/v2/admin/search',
            'POST /api/v2/admin/exports', 'GET /api/v2/admin/exports/{id}', 'GET /api/v2/admin/exports/{id}/download', 'POST /api/v2/admin/exports/{id}/retry',
            'POST /api/admin/invoices', 'GET /api/admin/invoices/{id}', 'GET /api/admin/invoices/{id}/evidence', 'POST /api/admin/invoices/{id}/issue',
            'POST /api/admin/invoices/{id}/credit-memos', 'POST /api/admin/orders/{id}/promotion-allocations/reconcile', 'GET /api/admin/credit-memos/{id}',
            'GET /api/account/notification-preferences', 'PUT /api/account/notification-preferences', 'GET /api/account/privacy/export',
            'POST /api/account/privacy/anonymize', 'POST /api/account/consents', 'POST /api/admin/customers/{id}/privacy/legal-hold',
            'POST /api/analytics/track', 'GET /api/admin/analytics/funnel', 'GET /api/admin/analytics/events', 'GET /api/admin/analytics/export',
        ];
        $cases = [];
        foreach ($operations as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            $path = str_replace('{id}', 'one', $path);
            $cases[$operation] = [$method, $path, $operation === 'POST /api/analytics/track' ? 422 : 401];
        }
        return $cases;
    }
}
