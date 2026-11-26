<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ConsentApiTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '203.0.113.42');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function ownerHeaders(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'Owner', 'email' => 'consent-owner@shop.test', 'password' => 'secret123', 'role' => 'owner']);
        $tokens = $auth->login('consent-owner@shop.test', 'secret123');

        return ['Authorization' => 'Bearer ' . $tokens->access];
    }

    private function countRows(string $sql): int
    {
        $rows = Database::select($sql);
        $first = $rows[0] ?? [];
        $value = is_array($first) ? (array_values($first)[0] ?? 0) : 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    public function testPolicyEndpointReturnsConfiguredShape(): void
    {
        $response = $this->call('GET', '/api/consent/policy');

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['json']);
        /** @var array<string, mixed> $data */
        $data = $response['json']['data'];
        self::assertSame('1', $data['version']);
        self::assertIsString($data['updated_at']);
        self::assertIsArray($data['sections']);
        self::assertSame(['shipping', 'returns', 'privacy', 'cookies'], array_column($data['sections'], 'id'));
        foreach ($data['sections'] as $section) {
            self::assertIsArray($section);
            self::assertArrayHasKey('title', $section);
            self::assertArrayHasKey('body', $section);
        }
    }

    public function testValidationRejectsDisabledNecessaryAndWrongPolicyVersion(): void
    {
        $disabled = $this->call('POST', '/api/consent', [
            'necessary' => false,
            'analytics' => true,
            'marketing' => false,
            'policy_version' => '1',
        ], ['X-Session-Id' => 'session-validation-1']);
        self::assertSame(422, $disabled['status']);

        $wrongVersion = $this->call('POST', '/api/consent', [
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
            'policy_version' => '999',
        ], ['X-Session-Id' => 'session-validation-2']);
        self::assertSame(422, $wrongVersion['status']);

        $badBoolean = $this->call('POST', '/api/consent', [
            'necessary' => true,
            'analytics' => 'yes',
            'marketing' => false,
            'policy_version' => '1',
        ], ['X-Session-Id' => 'session-validation-3']);
        self::assertSame(422, $badBoolean['status']);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM consent_records'));
    }

    public function testConsentRecordIsIdempotentAndNeverStoresRawPii(): void
    {
        $sessionId = 'session-idempotent-123';
        $first = $this->call('POST', '/api/consent', [
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
            'policy_version' => '1',
        ], ['X-Session-Id' => $sessionId, 'User-Agent' => str_repeat('A', 300)]);
        self::assertSame(200, $first['status']);

        $second = $this->call('POST', '/api/consent', [
            'necessary' => true,
            'analytics' => false,
            'marketing' => true,
            'policy_version' => '1',
        ], ['X-Session-Id' => $sessionId, 'User-Agent' => 'Second agent']);
        self::assertSame(200, $second['status']);

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM consent_records'));
        $rows = Database::select('SELECT * FROM consent_records');
        $row = $rows[0] ?? [];
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $sessionId), $row['session_hash']);
        self::assertNotSame($sessionId, $row['session_hash']);
        self::assertSame('1', $row['policy_version']);
        self::assertSame(64, strlen((string) $row['ip_hash']));
        self::assertNotSame('203.0.113.42', $row['ip_hash']);
        self::assertLessThanOrEqual(200, strlen((string) $row['user_agent']));
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('203.0.113.42', $encoded);
        self::assertStringNotContainsString($sessionId, $encoded);
        /** @var array<string, mixed> $choices */
        $choices = json_decode((string) $row['choices_json'], true);
        self::assertFalse($choices['analytics']);
        self::assertTrue($choices['marketing']);
        self::assertTrue($choices['necessary']);
    }

    public function testAdminSummaryRequiresStaffAndReportsOptIns(): void
    {
        $noAuth = $this->call('GET', '/api/admin/consent/summary');
        self::assertSame(401, $noAuth['status']);

        $this->call('POST', '/api/consent', [
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
            'policy_version' => '1',
        ], ['X-Session-Id' => 'summary-session-1']);
        $this->call('POST', '/api/consent', [
            'necessary' => true,
            'analytics' => true,
            'marketing' => true,
            'policy_version' => '1',
        ], ['X-Session-Id' => 'summary-session-2']);

        $response = $this->call('GET', '/api/admin/consent/summary', [], $this->ownerHeaders());
        self::assertSame(200, $response['status']);
        /** @var array<string, mixed> $data */
        $data = $response['json']['data'];
        self::assertSame(2, $data['records']);
        self::assertSame(2, $data['analytics_opt_in']);
        self::assertSame(1, $data['marketing_opt_in']);
        self::assertSame('1', $data['policy_version']);
    }
}
