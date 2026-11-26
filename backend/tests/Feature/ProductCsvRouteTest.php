<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ProductCsvRouteTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed, output: string, headers: array<string, string>} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true), 'output' => $output, 'headers' => $response->headers()];
    }

    /** @return array<string, string> */
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'USD'];
    }

    public function testCsvEndpointsRequireAdminAuthentication(): void
    {
        $anonExport = $this->call('GET', '/api/admin/products/export.csv');
        self::assertSame(401, $anonExport['status']);
        $anonImport = $this->call('POST', '/api/admin/products/import', ['csv' => 'sku,name']);
        self::assertSame(401, $anonImport['status']);
    }

    public function testImportRequiresAValidIdempotencyKey(): void
    {
        $admin = $this->loginAs('csv-admin@shop.test', 'admin');
        $response = $this->call('POST', '/api/admin/products/import', ['csv' => "sku,name\nSKU-1,One\n"], $admin);
        self::assertSame(422, $response['status']);
        self::assertStringContainsString('Idempotency-Key', (string) ($response['json']['message'] ?? ''));
    }

    public function testDryRunDoesNotWriteAndRealImportIsReplaySafe(): void
    {
        $admin = $this->loginAs('csv-owner@shop.test', 'owner');
        $headers = $admin + ['Idempotency-Key' => 'csv-route-test-1'];
        $csv = "sku,name,slug,status,price_minor,compare_price_minor,stock,description,category,min_tier,allow_backorder,is_preorder\nSKU-ROUTE,Route Product,route-product,active,900,,4,,,,,\n";

        $dry = $this->call('POST', '/api/admin/products/import?dry_run=1', ['csv' => $csv], $headers);
        self::assertSame(200, $dry['status']);
        self::assertSame(1, $dry['json']['data']['created']);
        self::assertTrue($dry['json']['data']['dry_run']);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM products')->fetchColumn());

        $real = $this->call('POST', '/api/admin/products/import', ['csv' => $csv], $headers);
        self::assertSame(200, $real['status']);
        self::assertSame(1, $real['json']['data']['created']);
        self::assertSame(1, (int) Database::connection()->query('SELECT COUNT(*) FROM products')->fetchColumn());

        $replay = $this->call('POST', '/api/admin/products/import', ['csv' => $csv], $headers);
        self::assertSame(0, $replay['json']['data']['created']);
        self::assertSame(0, $replay['json']['data']['updated']);
        self::assertSame(1, $replay['json']['data']['skipped']);

        $export = $this->call('GET', '/api/admin/products/export.csv', [], $admin);
        self::assertSame(200, $export['status']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $export['output']);
        self::assertStringContainsString('sku,name,slug,status,price_minor,compare_price_minor,stock,description,category,min_tier,allow_backorder,is_preorder', $export['output']);
        self::assertStringContainsString('SKU-ROUTE,"Route Product",route-product,active,900', $export['output']);
        self::assertSame('0', $export['headers']['X-Export-Truncated'] ?? null);
    }
}
