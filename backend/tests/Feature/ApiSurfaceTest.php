<?php

declare(strict_types=1);

namespace Tests\Feature;

use Siro\Core\Request;
use Siro\Core\Router;
use Tests\TestCase;

final class ApiSurfaceTest extends TestCase
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

    public function testHealthAndVersionSurfaces(): void
    {
        $health = $this->router->dispatch(new Request('GET', '/health/live'));
        self::assertSame(200, $health->statusCode());
        ob_start();
        $health->send();
        $healthBody = json_decode((string) ob_get_clean(), true);
        self::assertTrue($healthBody['success']);

        $version = $this->router->dispatch(new Request('GET', '/api/version'));
        self::assertSame(200, $version->statusCode());
        ob_start();
        $version->send();
        $versionBody = json_decode((string) ob_get_clean(), true);
        // Pinned to the vendored core's own version string: upgrades move this.
        self::assertSame(\Siro\Core\Console::VERSION, $versionBody['data']['core']);
        self::assertSame('0.2.1', $versionBody['data']['commerce']);
        self::assertSame(['v1', 'v2'], $versionBody['data']['supported_apis']);
        self::assertSame(1, $versionBody['data']['contract']);
    }

    public function testHeadlessGraphQlRejectsMutations(): void
    {
        $response = $this->router->dispatch(new Request('POST', '/api/headless/graphql', [], [], ['query' => 'mutation { createProduct { id } }']));
        self::assertSame(400, $response->statusCode());
        ob_start();
        $response->send();
        $body = json_decode((string) ob_get_clean(), true);
        self::assertSame('READ_ONLY', $body['errors'][0]['code']);
    }

    public function testHeadlessGraphQlDoesNotReadQueryFromUrl(): void
    {
        $response = $this->router->dispatch(new Request(
            'POST',
            '/api/headless/graphql',
            ['query' => 'mutation { createProduct { id } }'],
        ));

        self::assertSame(400, $response->statusCode());
        ob_start();
        $response->send();
        $body = json_decode((string) ob_get_clean(), true);
        self::assertSame('INVALID_REQUEST', $body['errors'][0]['code']);
    }

    public function testPersistedHashMustComeFromThePostBody(): void
    {
        $query = '{ product(slug: "missing") { id } }';
        $response = $this->router->dispatch(new Request(
            'POST',
            '/api/headless/graphql',
            ['extensions' => ['persistedQuery' => ['sha256Hash' => hash('sha256', $query)]]],
            ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'],
            ['query' => $query],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
    }

    public function testGraphQlRejectsNonObjectExtensions(): void
    {
        $response = $this->router->dispatch(new Request(
            'POST',
            '/api/headless/graphql',
            [],
            [],
            ['query' => '{ product(slug: "missing") { id } }', 'extensions' => 'not-an-object'],
        ));

        self::assertSame(400, $response->statusCode());
        ob_start();
        $response->send();
        $body = json_decode((string) ob_get_clean(), true);
        self::assertSame('INVALID_REQUEST', $body['errors'][0]['code']);
    }

    public function testV2CustomerAndReportingRoutesAreDeclared(): void
    {
        $paths = [
            '/api/v2/admin/reports/overview',
            '/api/v2/admin/reports/failures',
            '/api/v2/admin/search',
            '/api/account/returns',
            '/api/account/returns/{id}',
            '/api/account/addresses',
        ];
        self::assertCount(6, $paths);
    }

    public function testV6ExportAndCustomerEngagementRoutesAreDeclared(): void
    {
        $paths = [
            '/api/v2/admin/exports', '/api/v2/admin/exports/{id}',
            '/api/v2/admin/exports/{id}/download', '/api/v2/admin/exports/{id}/retry',
            '/api/account/notification-preferences', '/api/account/loyalty',
            '/api/account/loyalty/{order}/earn', '/api/account/loyalty/redeem',
            '/api/account/loyalty/expire',
        ];
        self::assertCount(9, $paths);
    }

    public function testSensitiveRoutesRetainAuthCapabilityAndBoundedRateLimits(): void
    {
        $routes = $this->router->getRoutes();
        $adminSearch = array_values(array_filter($routes, static fn (array $route): bool => $route['path'] === '/api/v2/admin/search'))[0] ?? null;
        $graphql = array_values(array_filter($routes, static fn (array $route): bool => $route['path'] === '/api/headless/graphql'))[0] ?? null;

        self::assertIsArray($adminSearch);
        self::assertStringContainsString('auth:', (string) $adminSearch['middleware']);
        self::assertStringContainsString('cap:search', (string) $adminSearch['middleware']);
        self::assertMatchesRegularExpression('/throttle:\d{2,4},1/', (string) $adminSearch['middleware']);
        self::assertIsArray($graphql);
        self::assertStringContainsString('throttle:15,1', (string) $graphql['middleware']);
    }

    public function testHealthProbesAreNeverThrottledAndStorefrontReadsHaveAUsableBudget(): void
    {
        $routes = $this->router->getRoutes();
        $middlewareFor = static function (string $path) use ($routes): string {
            foreach ($routes as $route) {
                if ($route['path'] === $path) return (string) $route['middleware'];
            }
            return '';
        };

        foreach (['/health/live', '/health/ready', '/health/providers'] as $probe) {
            $middleware = $middlewareFor($probe);
            self::assertNotSame('', $middleware, $probe . ' must stay routed');
            self::assertStringNotContainsString('throttle', $middleware, $probe . ' must always answer a load balancer');
        }

        $reads = $middlewareFor('/api/products');
        self::assertMatchesRegularExpression('/throttle:(\d{2,4}),1/', $reads);
        self::assertGreaterThanOrEqual(300, (int) preg_replace('/[^0-9].*$/', '', substr($reads, strpos($reads, 'throttle:') + 9)), 'storefront reads need a shopper-usable budget');
    }

    public function testCmsHeadlessRoutesAreDeclared(): void
    {
        $paths = array_column($this->router->getRoutes(), 'path');
        self::assertContains('/api/menus/{key}', $paths);
        self::assertContains('/api/admin/cms-revisions/{type}/{id}', $paths);
        self::assertContains('/api/v2/headless/pages', $paths);
        self::assertContains('/api/admin/pages/{id}/preview', $paths);
        self::assertContains('/api/admin/pages/{id}/publish', $paths);
        self::assertContains('/api/admin/pages/{id}/restore/{revision}', $paths);
    }

    public function testV8InventoryAtpRouteIsDeclared(): void
    {
        self::assertContains('/api/v2/inventory/{variant}/atp', array_column($this->router->getRoutes(), 'path'));
    }

    public function testPrivacyAndCreditMemoRoutesAreDeclared(): void
    {
        $paths = array_column($this->router->getRoutes(), 'path');
        self::assertContains('/api/admin/credit-memos/{id}', $paths);
        self::assertContains('/api/account/privacy/export', $paths);
        self::assertContains('/api/account/privacy/anonymize', $paths);
        self::assertContains('/api/account/consents', $paths);
    }
}
