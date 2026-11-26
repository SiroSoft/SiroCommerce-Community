<?php
declare(strict_types=1);
namespace Tests\Feature;

use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class MediaLibraryTest extends TestCase
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

    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
            $query = is_array($query) ? $query : [];
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
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $email = 'media-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new \App\Modules\Auth\Application\AuthService(new \App\Modules\Platform\Infrastructure\UserStoreAdapter(new \App\Repositories\UserRepository()));
        $auth->register(['name' => 'admin', 'email' => $email, 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testLibraryIndexAndValidation(): void
    {
        $headers = $this->adminHeaders();
        $list = $this->call('GET', '/api/admin/media-library', [], $headers);
        $this->assertSame(200, $list['status']);
        // bad type filter
        $bad = $this->call('GET', '/api/admin/media-library?type=exe', [], $headers);
        $this->assertSame(422, $bad['status']);
        // upload without file
        $nofile = $this->call('POST', '/api/admin/media-library', [], array_merge($headers, ['Idempotency-Key' => 'm-' . bin2hex(random_bytes(4))]));
        $this->assertSame(422, $nofile['status']);
        // upload without idempotency key
        $nokey = $this->call('POST', '/api/admin/media-library', [], $headers);
        $this->assertSame(422, $nokey['status']);
        // delete missing
        $del = $this->call('DELETE', '/api/admin/media-library/' . bin2hex(random_bytes(13)), [], $headers);
        $this->assertSame(404, $del['status']);
    }

    public function testProductVideoUrlValidation(): void
    {
        $headers = $this->adminHeaders();
        $created = $this->call('POST', '/api/admin/products', ['name' => 'Vid Prod', 'price_minor' => 1000, 'stock' => 5, 'sku' => 'VID-' . bin2hex(random_bytes(2))], $headers);
        $this->assertSame(201, $created['status']);
        $pid = (string) ($created['json']['data']['id'] ?? '');
        // evil scheme rejected
        $evil = $this->call('POST', "/api/admin/products/{$pid}/media", ['url' => 'javascript:alert(1)', 'type' => 'video'], $headers);
        $this->assertSame(422, $evil['status']);
        // youtube host with image type rejected
        $mismatch = $this->call('POST', "/api/admin/products/{$pid}/media", ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'image'], $headers);
        $this->assertSame(422, $mismatch['status']);
        // valid youtube
        $yt = $this->call('POST', "/api/admin/products/{$pid}/media", ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'youtube'], $headers);
        $this->assertSame(201, $yt['status']);
        $this->assertSame('youtube', $yt['json']['data']['type']);
        // valid mp4 inferred as video
        $mp4 = $this->call('POST', "/api/admin/products/{$pid}/media", ['url' => 'https://cdn.example.test/clip.mp4'], $headers);
        $this->assertSame(201, $mp4['status']);
        $this->assertSame('video', $mp4['json']['data']['type']);
    }
}
