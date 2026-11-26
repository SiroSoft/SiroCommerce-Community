<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class DataFinanceOwnedRouteTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function ownedOperations(): array
    {
        return [
            'reports overview' => ['GET', '/api/v2/admin/reports/overview', 'reports'],
            'reports failures' => ['GET', '/api/v2/admin/reports/failures', 'reports'],
            'admin search' => ['GET', '/api/v2/admin/search', 'search'],
            'export create' => ['POST', '/api/v2/admin/exports', 'exports'],
            'export status' => ['GET', '/api/v2/admin/exports/exp-1', 'exports'],
            'export download' => ['GET', '/api/v2/admin/exports/exp-1/download', 'exports'],
            'export retry' => ['POST', '/api/v2/admin/exports/exp-1/retry', 'exports'],
            'invoice create' => ['POST', '/api/admin/invoices', 'finance'],
            'invoice show' => ['GET', '/api/admin/invoices/inv-1', 'finance'],
            'invoice evidence' => ['GET', '/api/admin/invoices/inv-1/evidence', 'finance'],
            'invoice issue' => ['POST', '/api/admin/invoices/inv-1/issue', 'finance'],
            'credit memo create' => ['POST', '/api/admin/invoices/inv-1/credit-memos', 'finance'],
            'promotion allocation reconcile' => ['POST', '/api/admin/orders/order-1/promotion-allocations/reconcile', 'finance'],
            'credit memo show' => ['GET', '/api/admin/credit-memos/cm-1', 'finance'],
            'notification preferences read' => ['GET', '/api/account/notification-preferences', 'account'],
            'notification preferences write' => ['PUT', '/api/account/notification-preferences', 'account'],
            'privacy export' => ['GET', '/api/account/privacy/export', 'account'],
            'privacy anonymize' => ['POST', '/api/account/privacy/anonymize', 'account'],
            'consent record' => ['POST', '/api/account/consents', 'account'],
            'privacy legal hold' => ['POST', '/api/admin/customers/cus-1/privacy/legal-hold', 'finance'],
            'analytics track' => ['POST', '/api/analytics/track', 'public'],
            'analytics funnel' => ['GET', '/api/admin/analytics/funnel', 'analytics'],
            'analytics events' => ['GET', '/api/admin/analytics/events', 'analytics'],
            'analytics export' => ['GET', '/api/admin/analytics/export', 'analytics'],
        ];
    }

    #[DataProvider('ownedOperations')]
    public function testEveryOwnedOperationHasAnExactAuthorizationBoundary(string $method, string $path, string $scope): void
    {
        try {
            $response = $this->router->dispatch(new Request($method, $path, [], [], [], '127.0.0.1'));
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }

        if ($scope === 'public') {
            self::assertNotSame(401, $response->statusCode(), $method . ' ' . $path . ' must remain public');
            return;
        }
        self::assertSame(401, $response->statusCode(), $method . ' ' . $path . ' must reject anonymous access');
    }
}
