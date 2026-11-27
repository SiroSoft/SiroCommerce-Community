<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Consent\Application\ConsentService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\ConsentRecordRepository;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/** Batch 1 Platform §2.5: marketing-consent read API + opt-in endpoint. */
final class Batch1ConsentApiTest extends TestCase
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

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
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

    private function token(string $email): string
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'shopper', 'email' => $email, 'password' => 'secret123', 'role' => 'viewer']);
        return $auth->login($email, 'secret123')->access;
    }

    private function consentService(): ConsentService
    {
        return new ConsentService(new ConsentRecordRepository());
    }

    public function testMarketingOptInForLoggedInCustomer(): void
    {
        $email = 'shopper-' . bin2hex(random_bytes(4)) . '@test.local';
        $headers = ['Authorization' => 'Bearer ' . $this->token($email)];

        $in = $this->call('POST', '/api/consent/marketing', ['marketing' => true], $headers);
        self::assertSame(200, $in['status'], (string) json_encode($in['json']));
        self::assertTrue((bool) ($in['json']['data']['marketing_email'] ?? false));
        $customerId = (string) ($in['json']['data']['customer_id'] ?? '');
        self::assertNotSame('', $customerId);

        $row = Database::table('customer_notification_preferences')->where('customer_id', $customerId)->first();
        self::assertIsArray($row);
        self::assertSame(1, (int) ($row['marketing_email'] ?? 0));
        self::assertTrue($this->consentService()->hasMarketingConsent($customerId));

        $out = $this->call('POST', '/api/consent/marketing', ['marketing' => false], $headers);
        self::assertSame(200, $out['status']);
        self::assertFalse((bool) ($out['json']['data']['marketing_email'] ?? true));
        self::assertFalse($this->consentService()->hasMarketingConsent($customerId));
    }

    public function testMarketingOptInForAnonymousSession(): void
    {
        $session = 'sess-' . bin2hex(random_bytes(6));
        $res = $this->call('POST', '/api/consent/marketing', ['marketing' => true], ['X-Session-Id' => $session]);
        self::assertSame(200, $res['status'], (string) json_encode($res['json']));
        self::assertTrue((bool) ($res['json']['data']['marketing_email'] ?? false));
        self::assertTrue($this->consentService()->hasMarketingConsent(null, $session));
        self::assertFalse($this->consentService()->hasMarketingConsent(null, 'other-session'));
        self::assertFalse($this->consentService()->hasMarketingConsent());
    }

    public function testMarketingRequiresIdentity(): void
    {
        $res = $this->call('POST', '/api/consent/marketing', ['marketing' => true]);
        self::assertSame(422, $res['status']);
    }

    public function testMarketingRejectsInvalidEmail(): void
    {
        $email = 'shopper-' . bin2hex(random_bytes(4)) . '@test.local';
        $res = $this->call('POST', '/api/consent/marketing', ['email' => 'not-an-email'], ['Authorization' => 'Bearer ' . $this->token($email)]);
        self::assertSame(422, $res['status']);
    }

    public function testHasMarketingConsentReadsPreferenceRow(): void
    {
        $service = $this->consentService();
        self::assertFalse($service->hasMarketingConsent('cust-missing'));
        Database::table('customer_notification_preferences')->insert([
            'customer_id' => 'cust-off', 'transactional_email' => 1, 'marketing_email' => 0,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::assertFalse($service->hasMarketingConsent('cust-off'));
        Database::table('customer_notification_preferences')->insert([
            'customer_id' => 'cust-on', 'transactional_email' => 1, 'marketing_email' => 1,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::assertTrue($service->hasMarketingConsent('cust-on'));
    }
}
