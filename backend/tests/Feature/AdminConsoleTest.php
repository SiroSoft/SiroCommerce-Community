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
 * Admin console skeleton surfaces: users CRUD, profile, password,
 * settings, server info, uploads.
 */
final class AdminConsoleTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @param array<int|string, mixed> $query @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
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
    private function adminHeaders(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'admin', 'email' => 'console-admin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('console-admin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testUserCrudRoundTrip(): void
    {
        $admin = $this->adminHeaders();
        self::assertSame(401, $this->call('GET', '/api/users')['status']);

        $created = $this->call('POST', '/api/users', ['name' => 'Console Buyer', 'email' => 'console-buyer@shop.test', 'password' => 'buyerpass1', 'role' => 'customer'], $admin);
        self::assertSame(201, $created['status']);
        self::assertSame('console-buyer@shop.test', $created['json']['data']['email']);
        self::assertArrayNotHasKey('password', $created['json']['data']);
        $id = (int) $created['json']['data']['id'];

        $duplicate = $this->call('POST', '/api/users', ['name' => 'Dup', 'email' => 'console-buyer@shop.test', 'password' => 'buyerpass1'], $admin);
        self::assertSame(422, $duplicate['status']);

        $updated = $this->call('PUT', '/api/users/' . $id, ['name' => 'Renamed Buyer', 'status' => 'suspended'], $admin);
        self::assertSame(200, $updated['status']);
        self::assertSame('Renamed Buyer', $updated['json']['data']['name']);

        $deleted = $this->call('DELETE', '/api/users/' . $id, [], $admin);
        self::assertSame(204, $deleted['status']);
        self::assertSame(404, $this->call('GET', '/api/users/' . $id, [], $admin)['status']);
    }

    public function testProfilePasswordSettingsServerInfo(): void
    {
        $admin = $this->adminHeaders();

        $profile = $this->call('GET', '/api/profile', [], $admin);
        self::assertSame(200, $profile['status']);
        self::assertSame('console-admin@shop.test', $profile['json']['data']['email']);

        $renamed = $this->call('PUT', '/api/profile', ['name' => 'Console Chief'], $admin);
        self::assertSame(200, $renamed['status']);
        self::assertSame('Console Chief', $renamed['json']['data']['name']);

        $badPassword = $this->call('PUT', '/api/profile/password', ['current_password' => 'wrong', 'new_password' => 'newsecret1'], $admin);
        self::assertSame(422, $badPassword['status']);
        $changed = $this->call('PUT', '/api/profile/password', ['current_password' => 'secret123', 'new_password' => 'newsecret1'], $admin);
        self::assertSame(200, $changed['status']);

        // Password changes rotate token_version; obtain a fresh access token
        // before continuing with authenticated console requests.
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $tokens = $auth->login('console-admin@shop.test', 'newsecret1');
        $admin = ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];

        $settings = $this->call('GET', '/api/settings', [], $admin);
        self::assertSame(200, $settings['status']);
        self::assertArrayHasKey('app_name', $settings['json']['data']);
        $saved = $this->call('PUT', '/api/settings', ['app_name' => 'Console Shop', 'maintenance_mode' => true], $admin);
        self::assertSame(200, $saved['status']);
        self::assertSame('Console Shop', $saved['json']['data']['app_name']);
        self::assertTrue($saved['json']['data']['maintenance_mode']);

        $info = $this->call('GET', '/api/server/info');
        self::assertSame(200, $info['status']);
        self::assertSame('healthy', $info['json']['data']['status']);

        $upload = $this->call('POST', '/api/upload', [], $admin);
        self::assertSame(422, $upload['status']);
    }
}
