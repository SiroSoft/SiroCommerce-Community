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

/**
 * Password recovery + email verification (admin console auth surfaces).
 */
final class AuthRecoveryTest extends TestCase
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
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
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

    public function testForgotResetRoundTrip(): void
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'recovery', 'email' => 'recovery@shop.test', 'password' => 'secret123', 'role' => 'admin']);

        $unknown = $this->call('POST', '/api/auth/forgot-password', ['email' => 'nobody@shop.test']);
        self::assertSame(200, $unknown['status']);

        $forgot = $this->call('POST', '/api/auth/forgot-password', ['email' => 'recovery@shop.test']);
        self::assertSame(200, $forgot['status']);
        $token = (string) ($forgot['json']['data']['reset_token'] ?? '');
        self::assertNotSame('', $token);

        $mismatch = $this->call('POST', '/api/auth/reset-password', ['token' => $token, 'password' => 'newsecret1', 'password_confirmation' => 'other']);
        self::assertSame(422, $mismatch['status']);

        $reset = $this->call('POST', '/api/auth/reset-password', ['token' => $token, 'password' => 'newsecret1', 'password_confirmation' => 'newsecret1']);
        self::assertSame(200, $reset['status']);

        $replay = $this->call('POST', '/api/auth/reset-password', ['token' => $token, 'password' => 'newsecret1']);
        self::assertSame(422, $replay['status']);

        $tokens = $auth->login('recovery@shop.test', 'newsecret1');
        self::assertNotSame('', $tokens->access);
    }

    public function testVerifyEmailRoundTrip(): void
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'verify', 'email' => 'verify@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('verify@shop.test', 'secret123');
        $headers = ['Authorization' => 'Bearer ' . $tokens->access];

        $resend = $this->call('POST', '/api/auth/verify-email/resend', [], $headers);
        self::assertSame(200, $resend['status']);
        $token = (string) ($resend['json']['data']['verification_token'] ?? '');
        self::assertNotSame('', $token);

        $bad = $this->call('POST', '/api/auth/verify-email', ['token' => 'invalid']);
        self::assertSame(422, $bad['status']);

        $verified = $this->call('POST', '/api/auth/verify-email', ['token' => $token]);
        self::assertSame(200, $verified['status']);

        $me = $this->call('GET', '/api/auth/me', [], $headers);
        self::assertSame(200, $me['status']);
        self::assertNotNull($me['json']['data']['email_verified_at']);
    }

    public function testChangingPasswordExpiresExistingSessions(): void
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'session', 'email' => 'session@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('session@shop.test', 'secret123');
        $headers = ['Authorization' => 'Bearer ' . $tokens->access];

        $changed = $this->call('PUT', '/api/profile/password', ['current_password' => 'secret123', 'new_password' => 'newsecret1'], $headers);
        self::assertSame(200, $changed['status']);
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], $headers)['status']);
        self::assertNotSame('', $auth->login('session@shop.test', 'newsecret1')->access);
    }
}
