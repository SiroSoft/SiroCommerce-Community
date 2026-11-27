<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ExtensionInterface;
use App\Extension\ExtensionRegistry;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Coupon\Application\CouponService;
use App\Modules\Platform\Infrastructure\CouponStoreAdapter;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\CouponRepository;
use App\Repositories\UserRepository;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/** Batch 1 Platform §2.6: coupon:create capability + CouponService contract. */
final class Batch1CouponCapabilityTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        // Test-only extension route, mounted exactly like a real plugin route
        // would be (ExtensionRouteLoader adds `auth` for every `cap:` token).
        $this->router->post('/api/ext/siro/test-coupon/coupons', [Batch1TestCouponController::class, 'store'])
            ->middleware(['auth', 'cap:coupon:create']);
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        parent::tearDown();
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

    /** @return array<string, string> */
    private function headers(string $role): array
    {
        $email = 'coupon-' . $role . '-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access];
    }

    private function coupons(): CouponService
    {
        return new CouponService(new CouponStoreAdapter(new CouponRepository()));
    }

    public function testRouteWithoutCapabilityReturns403(): void
    {
        // Viewer never holds extension permissions; admin holds them only when
        // an enabled extension declares coupon:create (none registered here).
        self::assertSame(403, $this->call('POST', '/api/ext/siro/test-coupon/coupons', ['code' => 'TEST10'], $this->headers('viewer'))['status']);
        self::assertSame(403, $this->call('POST', '/api/ext/siro/test-coupon/coupons', ['code' => 'TEST10'], $this->headers('admin'))['status']);
    }

    public function testRouteWithDeclaredCapabilityCreatesCoupon(): void
    {
        ExtensionRegistry::register(new Batch1TestCouponExtension());
        $code = 'BATCH1-' . strtoupper(bin2hex(random_bytes(3)));
        $res = $this->call('POST', '/api/ext/siro/test-coupon/coupons', ['code' => $code, 'rules' => '{"type":"percent","value":10}'], $this->headers('admin'));
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
        self::assertSame($code, $res['json']['data']['code'] ?? null);
        $found = null;
        foreach ($this->coupons()->all() as $row) {
            if (($row['code'] ?? null) === $code) $found = $row;
        }
        self::assertNotNull($found);
    }

    public function testCouponServiceValidatesCodeAndRules(): void
    {
        $service = $this->coupons();
        foreach (['no', 'bad code!', '', 'x'] as $bad) {
            try {
                $service->create(['code' => $bad]);
                self::fail('Expected InvalidArgumentException for code ' . $bad);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Coupon code', $e->getMessage());
            }
        }
        try {
            $service->create(['code' => 'VALID-1', 'rules' => '[1,2]']);
            self::fail('Expected InvalidArgumentException for list rules');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Coupon rules', $e->getMessage());
        }
        $code = 'SRV-' . strtoupper(bin2hex(random_bytes(3)));
        $row = $service->create(['code' => strtolower($code), 'rules' => ['type' => 'fixed', 'value' => 500]]);
        self::assertSame($code, $row['code'] ?? null);
        self::assertSame('{"type":"fixed","value":500}', $row['rules'] ?? null);
    }
}

final class Batch1TestCouponController extends Controller
{
    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['code' => 'required|string', 'rules' => 'nullable|string']);
        try {
            $coupon = (new CouponService(new CouponStoreAdapter(new CouponRepository())))->create([
                'code' => $data['code'] ?? '',
                'rules' => $data['rules'] ?? '{}',
                'state' => 'active',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->created($coupon, 'Coupon created');
    }
}

/** Minimal extension declaring the coupon:create permission (RULE-E7). */
final class Batch1TestCouponExtension implements ExtensionInterface
{
    public static function id(): string { return 'siro/test-coupon'; }
    public static function compatibleCore(): string { return '^1.0'; }
    public static function compatibleCommerce(): string { return '^0.3'; }
    /** @return array<string, mixed> */
    public function config(): array { return []; }
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return ['id' => self::id(), 'version' => '1.0.0', 'permissions' => ['coupon:create']];
    }
    public function boot(): void {}
    public function shutdown(): void {}
    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array { return []; }
    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array { return []; }
}
