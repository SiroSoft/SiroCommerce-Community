<?php

declare(strict_types=1);

namespace Tests\Feature;

use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * Auth round-trip (spec 08§1): register → login → me → refresh rotation →
 * logout revokes. Turnstile fail-open in tests (no TURNSTILE_SECRET).
 */
final class AuthTest extends TestCase
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

    public function testRegisterLoginMeRefreshLogout(): void
    {
        $reg = $this->call('POST', '/api/auth/register', [
            'name' => 'Boss', 'email' => 'boss@shop.test', 'password' => 'secret123',
        ]);
        $this->assertSame(201, $reg['status']);

        $dup = $this->call('POST', '/api/auth/register', [
            'name' => 'Boss', 'email' => 'boss@shop.test', 'password' => 'secret123',
        ]);
        $this->assertSame(422, $dup['status']);

        $bad = $this->call('POST', '/api/auth/login', ['email' => 'boss@shop.test', 'password' => 'nope']);
        $this->assertSame(401, $bad['status']);

        $login = $this->call('POST', '/api/auth/login', ['email' => 'boss@shop.test', 'password' => 'secret123']);
        $this->assertSame(200, $login['status']);
        /** @var array<string, mixed> $loginData */
        $loginData = $login['json']['data'];
        $access = is_string($loginData['access'] ?? null) ? $loginData['access'] : '';
        $refresh = is_string($loginData['refresh'] ?? null) ? $loginData['refresh'] : '';
        $this->assertNotSame('', $access);
        $this->assertNotSame('', $refresh);

        $anon = $this->call('GET', '/api/auth/me');
        $this->assertSame(401, $anon['status']);

        $me = $this->call('GET', '/api/auth/me', [], ['Authorization' => 'Bearer ' . $access]);
        $this->assertSame(200, $me['status']);
        $this->assertSame('boss@shop.test', $me['json']['data']['email']);

        $updated = $this->call('PUT', '/api/auth/me', ['name' => 'Updated buyer'], ['Authorization' => 'Bearer ' . $access]);
        $this->assertSame(200, $updated['status']);
        $this->assertSame('Updated buyer', $updated['json']['data']['name']);
        $meAgain = $this->call('GET', '/api/auth/me', [], ['Authorization' => 'Bearer ' . $access]);
        $this->assertSame('Updated buyer', $meAgain['json']['data']['name']);
        $invalid = $this->call('PUT', '/api/auth/me', ['name' => '   '], ['Authorization' => 'Bearer ' . $access]);
        $this->assertSame(422, $invalid['status']);

        $rotated = $this->call('POST', '/api/auth/refresh', ['refresh' => $refresh]);
        $this->assertSame(200, $rotated['status']);

        $reused = $this->call('POST', '/api/auth/refresh', ['refresh' => $refresh]);
        $this->assertSame(401, $reused['status']);

        $logout = $this->call('POST', '/api/auth/logout', [], ['Authorization' => 'Bearer ' . $access]);
        $this->assertSame(200, $logout['status']);

        $afterLogout = $this->call('GET', '/api/auth/me', [], ['Authorization' => 'Bearer ' . $access]);
        $this->assertSame(401, $afterLogout['status']);
    }
}
