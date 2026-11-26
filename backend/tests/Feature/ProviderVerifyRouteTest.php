<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ProviderVerifyRouteTest extends TestCase
{
    private Router $router;

    /** @var array<string, string|false> */
    private array $env = [];

    /** @var array<int, string> */
    private const PROVIDER_KEYS = [
        'STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET',
        'VNPAY_TMN_CODE', 'VNPAY_HASH_SECRET', 'MOMO_PARTNER_CODE', 'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY',
        'GHN_TOKEN', 'GHN_SHOP_ID', 'VIETTELPOST_USERNAME', 'VIETTELPOST_PASSWORD',
        'DHL_API_URL', 'DHL_API_KEY', 'DHL_API_SECRET',
        'CATALOG_SEARCH_PROVIDER', 'SEARCH_URL', 'SEARCH_API_KEY',
        'MAIL_PROVIDER', 'MAIL_SMTP_HOST', 'MAIL_FROM',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::PROVIDER_KEYS as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
        \App\Support\Mailer::reset();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        \App\Support\Mailer::reset();
        foreach ($this->env as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /** @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, [], '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }

    /** @return array<string, string> */
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testOwnerCanVerifyProvidersAndMissingCredentialsAreNotFailures(): void
    {
        $response = $this->call('GET', '/api/admin/providers/verify', $this->loginAs('verify-owner@shop.test', 'owner'));

        self::assertSame(200, $response['status']);
        $data = $response['json']['data'];
        self::assertTrue($data['certified']);
        self::assertCount(8, $data['providers']);
        foreach ($data['providers'] as $provider) {
            self::assertSame(['name', 'type', 'configured', 'reachable', 'detail'], array_keys($provider));
            self::assertFalse($provider['configured']);
            self::assertFalse($provider['reachable']);
        }
    }

    public function testSupportAndCustomersCannotVerifyProviders(): void
    {
        self::assertSame(403, $this->call('GET', '/api/admin/providers/verify', $this->loginAs('verify-support@shop.test', 'support'))['status']);
        self::assertSame(403, $this->call('GET', '/api/admin/providers/verify', $this->loginAs('verify-customer@shop.test', 'customer'))['status']);
        self::assertSame(401, $this->call('GET', '/api/admin/providers/verify')['status']);
    }
}
