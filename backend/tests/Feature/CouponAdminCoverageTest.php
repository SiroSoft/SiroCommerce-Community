<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class CouponAdminCoverageTest extends TestCase
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

    public function testIndexFiltersAndStoreValidationBranches(): void
    {
        $headers = $this->adminHeaders();

        self::assertSame(200, $this->call('GET', '/api/admin/coupons', [], $headers)['status']);

        $invalidCodes = ['no', 'bad code!', ''];
        foreach ($invalidCodes as $code) {
            self::assertSame(422, $this->call('POST', '/api/admin/coupons', ['code' => $code], $headers)['status']);
        }

        $invalidRules = [
            'not-json',
            '[]',
            '{"currency":"US"}',
            '{"usage_limit":-1}',
            '{"rate_limit_attempts":-1}',
            '{"type":"bogus"}',
            '{"type":"fixed","value":-1}',
            '{"type":"percent","value":150}',
            '{"type":"buy_x_get_y"}',
            '{"type":"buy_x_get_y","buy_product_ids":["p"],"get_variant_ids":[]}',
            '{"type":"buy_x_get_y","buy_product_ids":["p"],"get_variant_ids":["v"],"buy_qty":0}',
            '{"type":"buy_x_get_y","buy_product_ids":["p"],"get_variant_ids":["v"],"max_sets":-1}',
            '{"exclusive":"yes"}',
            '{"scope":"bogus"}',
            '{"starts_at":"not-a-date"}',
            '{"product_ids":"not-an-array"}',
            '{"scope":"item"}',
        ];
        foreach ($invalidRules as $rules) {
            $response = $this->call('POST', '/api/admin/coupons', ['code' => 'RULE' . bin2hex(random_bytes(3)), 'rules' => $rules], $headers);
            self::assertSame(422, $response['status'], 'rules should fail: ' . $rules);
        }

        $created = $this->call('POST', '/api/admin/coupons', [
            'code' => 'welcome-10',
            'rules' => '{"type":"fixed","value":100,"usage_limit":5,"scope":"cart"}',
        ], $headers);
        self::assertSame(201, $created['status']);
        self::assertSame('WELCOME-10', $created['json']['data']['code']);

        $list = $this->call('GET', '/api/admin/coupons?q=welcome&state=active&page=1&per_page=1', [], $headers);
        self::assertSame(200, $list['status']);
        self::assertSame(1, $list['json']['data']['meta']['total']);
        self::assertSame('WELCOME-10', $list['json']['data']['data'][0]['code']);
    }

    public function testUpdateEnableDisableVersionsUsageAndPnl(): void
    {
        $headers = $this->adminHeaders();
        $created = $this->call('POST', '/api/admin/coupons', ['code' => 'LIFECYCLE-1'], $headers);
        self::assertSame(201, $created['status']);
        $id = (string) $created['json']['data']['id'];

        self::assertSame(404, $this->call('PUT', '/api/admin/coupons/missing', ['state' => 'active'], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/admin/coupons/' . $id, ['code' => 'x!'], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/admin/coupons/' . $id, ['rules' => '[]'], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/admin/coupons/' . $id, ['state' => 'bogus'], $headers)['status']);
        self::assertSame(422, $this->call('PUT', '/api/admin/coupons/' . $id, [], $headers)['status']);

        $updated = $this->call('PUT', '/api/admin/coupons/' . $id, [
            'code' => 'LIFECYCLE-2',
            'rules' => '{"type":"percent","value":20}',
            'state' => 'disabled',
        ], $headers);
        self::assertSame(200, $updated['status']);
        self::assertSame('LIFECYCLE-2', $updated['json']['data']['code']);
        self::assertSame('disabled', $updated['json']['data']['state']);

        self::assertSame(200, $this->call('POST', '/api/admin/coupons/' . $id . '/enable', [], $headers)['status']);
        self::assertSame(200, $this->call('POST', '/api/admin/coupons/' . $id . '/disable', [], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/coupons/missing/disable', [], $headers)['status']);
        self::assertSame(404, $this->call('POST', '/api/admin/coupons/missing/enable', [], $headers)['status']);

        $versions = $this->call('GET', '/api/admin/coupons/' . $id . '/versions', [], $headers);
        self::assertSame(200, $versions['status']);
        self::assertGreaterThanOrEqual(2, count($versions['json']['data']));
        self::assertSame(404, $this->call('GET', '/api/admin/coupons/missing/versions', [], $headers)['status']);

        $order = (new OrderRepository())->createOrder([
            'id' => str_pad('COUPON-ORDER', 26, '0'), 'number' => 'COUPON-ORDER-1', 'customer_id' => 'coupon-customer',
            'currency' => 'VND', 'subtotal_minor' => 5000, 'grand_total_minor' => 5000,
            'state' => 'paid', 'idempotency_key' => 'coupon-order-1',
        ]);
        Database::table('coupon_usages')->insert([
            'id' => 'usage-1', 'coupon_id' => $id, 'order_id' => $order['id'], 'discount_minor' => 500, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('coupon_usages')->insert([
            'id' => 'usage-2', 'coupon_id' => $id, 'order_id' => '', 'discount_minor' => 200, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $usage = $this->call('GET', '/api/admin/coupons/' . $id . '/usage', [], $headers);
        self::assertSame(200, $usage['status']);
        self::assertSame(2, $usage['json']['data']['total']);
        self::assertSame(1, $usage['json']['data']['per_customer']['coupon-customer']);
        self::assertSame(404, $this->call('GET', '/api/admin/coupons/missing/usage', [], $headers)['status']);

        $pnl = $this->call('GET', '/api/admin/coupons/pnl', [], $headers);
        self::assertSame(200, $pnl['status']);
        $row = null;
        foreach ($pnl['json']['data'] as $candidate) {
            if (($candidate['coupon_id'] ?? null) === $id) {
                $row = $candidate;
            }
        }
        self::assertNotNull($row);
        self::assertSame(2, $row['uses']);
        self::assertSame(700, $row['discount_minor']);
        self::assertSame(1, $row['orders']);
        self::assertSame(5000, $row['revenue_minor']);
        self::assertSame(4300, $row['net_minor']);
    }

    public function testBulkGenerationValidationReplayAndStatus(): void
    {
        $headers = $this->adminHeaders() + ['Idempotency-Key' => 'bulk-key-1'];

        self::assertSame(422, $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'X', 'count' => 1], $this->adminHeaders())['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'NOPE', 'count' => 1], $headers)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'SAVE{0000}', 'count' => 0], $headers)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'SAVE{0000}', 'count' => 1001], $headers)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'SAVE{0000}', 'count' => 1, 'start' => -1], $headers)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/coupons/bulk', ['pattern' => 'SAVE{0000}', 'count' => 1, 'rules' => '[]'], $headers)['status']);

        $first = $this->call('POST', '/api/admin/coupons/bulk', [
            'pattern' => 'SAVE{0000}', 'count' => 3, 'start' => 1, 'rules' => '{"type":"fixed","value":50}',
        ], $headers);
        self::assertSame(201, $first['status']);
        self::assertSame(['SAVE0001', 'SAVE0002', 'SAVE0003'], $first['json']['data']['created']);
        self::assertSame([], $first['json']['data']['failed']);
        $jobId = (string) $first['json']['data']['job_id'];

        $replay = $this->call('POST', '/api/admin/coupons/bulk', [
            'pattern' => 'SAVE{0000}', 'count' => 3, 'start' => 1, 'rules' => '{"type":"fixed","value":50}',
        ], $headers);
        self::assertSame(201, $replay['status']);
        self::assertSame([], $replay['json']['data']['created']);
        self::assertCount(3, $replay['json']['data']['failed']);

        $status = $this->call('GET', '/api/admin/coupons/bulk/' . $jobId, [], $this->adminHeaders());
        self::assertSame(200, $status['status']);
        self::assertSame($jobId, $status['json']['data']['id']);
        self::assertSame(404, $this->call('GET', '/api/admin/coupons/bulk/missing-job', [], $this->adminHeaders())['status']);
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $email = 'coupon-admin-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'Coupon Admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: array<string, mixed>} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        $route = $path;
        if (str_contains($path, '?')) {
            [$route, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }
        $request = new Request($method, $route, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true);
        /** @var array<string, mixed> $decoded */
        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }
}
