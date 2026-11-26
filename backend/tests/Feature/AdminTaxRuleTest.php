<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TaxRule;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class AdminTaxRuleTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @param array<string, mixed> $query @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $error) {
            $response = $error->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function staff(string $role = 'admin'): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $email = 'tax-' . $role . '@shop.test';
        $auth->register(['name' => 'tax admin', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return [
            'Authorization' => 'Bearer ' . $tokens->access,
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function validRule(array $overrides = []): array
    {
        return array_merge([
            'code' => 'vn-standard',
            'destination_country' => 'VN',
            'destination_region' => 'HCM',
            'destination_postal_code' => '700000',
            'tax_class' => 'standard',
            'rate_basis_points' => 1000,
            'priority' => 5,
            'state' => 'active',
            'compound' => false,
            'effective_at' => '2026-01-01T00:00:00Z',
            'expires_at' => '2027-01-01T00:00:00Z',
        ], $overrides);
    }

    public function testCreateListFilterUpdateAndDelete(): void
    {
        $headers = $this->staff();
        $created = $this->call('POST', '/api/admin/tax-rules', $this->validRule(), $headers);
        self::assertSame(201, $created['status'], json_encode($created['json']));
        $rule = $created['json']['data'];
        self::assertSame('VN', $rule['destination_country']);
        self::assertSame(0, (int) $rule['compound']);
        self::assertSame('2026-01-01 00:00:00', $rule['effective_at']);

        $second = $this->call('POST', '/api/admin/tax-rules', $this->validRule([
            'code' => 'us-reduced',
            'destination_country' => 'US',
            'destination_region' => null,
            'destination_postal_code' => null,
            'tax_class' => 'reduced',
            'rate_basis_points' => 500,
            'compound' => true,
        ]), $headers);
        self::assertSame(201, $second['status'], json_encode($second['json']));
        self::assertSame(1, (int) $second['json']['data']['compound']);

        $list = $this->call('GET', '/api/admin/tax-rules', [], $headers, ['state' => 'active', 'country' => 'VN', 'tax_class' => 'standard']);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['json']['data']);
        self::assertSame('vn-standard', $list['json']['data'][0]['code']);

        $updated = $this->call('PUT', '/api/admin/tax-rules/' . (string) $rule['id'], ['compound' => true, 'rate_basis_points' => 800, 'state' => 'disabled'], $headers);
        self::assertSame(200, $updated['status'], json_encode($updated['json']));
        self::assertSame(1, (int) $updated['json']['data']['compound']);
        self::assertSame(800, (int) $updated['json']['data']['rate_basis_points']);
        self::assertSame('disabled', $updated['json']['data']['state']);

        $deleted = $this->call('DELETE', '/api/admin/tax-rules/' . (string) $rule['id'], [], $headers);
        self::assertSame(200, $deleted['status']);
        self::assertNull($deleted['json']['data']);
        self::assertNull(TaxRule::query()->where('id', '=', (string) $rule['id'])->first());
    }

    public function testDuplicateCodeReturnsConflict(): void
    {
        $headers = $this->staff();
        self::assertSame(201, $this->call('POST', '/api/admin/tax-rules', $this->validRule(), $headers)['status']);

        $duplicate = $this->call('POST', '/api/admin/tax-rules', $this->validRule(['rate_basis_points' => 500]), $headers);
        self::assertSame(409, $duplicate['status']);
        self::assertSame('CONFLICT', $duplicate['json']['meta']['error_code'] ?? null);

        $other = $this->call('POST', '/api/admin/tax-rules', $this->validRule(['code' => 'other']), $headers);
        self::assertSame(201, $other['status']);
        $conflict = $this->call('PUT', '/api/admin/tax-rules/' . (string) $other['json']['data']['id'], ['code' => 'vn-standard'], $headers);
        self::assertSame(409, $conflict['status']);
    }

    public function testInvalidPayloadsReturnValidationErrors(): void
    {
        $headers = $this->staff();
        $cases = [
            $this->validRule(['rate_basis_points' => 10001]),
            $this->validRule(['rate_basis_points' => -1]),
            $this->validRule(['destination_country' => 'VNM']),
            $this->validRule(['state' => 'paused']),
            $this->validRule(['effective_at' => '2027-01-01T00:00:00Z', 'expires_at' => '2026-01-01T00:00:00Z']),
            $this->validRule(['code' => '']),
            $this->validRule(['effective_at' => 'not-a-date']),
            $this->validRule(['compound' => 'maybe']),
        ];
        foreach ($cases as $index => $payload) {
            $response = $this->call('POST', '/api/admin/tax-rules', $payload, $headers);
            self::assertSame(422, $response['status'], 'case ' . $index . ' ' . json_encode($response['json']));
        }
        self::assertSame(0, TaxRule::query()->count());
    }

    public function testFinanceRoleCanManageTaxRules(): void
    {
        $headers = $this->staff('finance');
        $created = $this->call('POST', '/api/admin/tax-rules', $this->validRule(['code' => 'finance-rule']), $headers);
        self::assertSame(201, $created['status'], json_encode($created['json']));
    }

    public function testMutationsWriteAnAuditEvent(): void
    {
        $headers = $this->staff();
        $created = $this->call('POST', '/api/admin/tax-rules', $this->validRule(['code' => 'audit-rule']), $headers);
        self::assertSame(201, $created['status']);
        $id = (string) $created['json']['data']['id'];
        $this->call('PUT', '/api/admin/tax-rules/' . $id, ['state' => 'disabled'], $headers);
        $this->call('DELETE', '/api/admin/tax-rules/' . $id, [], $headers);

        $actions = Database::connection()->query("SELECT action FROM commerce_audit_events WHERE action LIKE 'tax.rule.%' ORDER BY action")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['tax.rule.created', 'tax.rule.deleted', 'tax.rule.updated'], $actions);
    }
}
