<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Tests\TestCase;

final class RedirectResolveRouteTest extends TestCase
{
    private function insertRewrite(string $from, string $target, int $type = 301): void
    {
        Database::table('url_rewrites')->insert([
            'id' => Ids::new(),
            'request_path' => $from,
            'target_path' => $target,
            'redirect_type' => $type,
            'store_id' => 'redirect-store',
            'channel_id' => 'web',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, string> $headers */
    private function dispatch(string $path, array $headers): Response
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        return $router->dispatch(new Request('GET', '/api/redirects/resolve', ['path' => $path], $headers));
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        ob_start();
        $response->send();
        $decoded = json_decode((string) ob_get_clean(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testResolveReturnsInternalTargetsOnly(): void
    {
        $this->insertRewrite('/old-page', '/pages/new-page', 301);
        $this->insertRewrite('/external-page', 'https://evil.example/phish', 301);
        $this->insertRewrite('/self-page', '/self-page', 301);
        $headers = [
            'X-Store-Id' => 'redirect-store',
            'X-Channel-Id' => 'web',
            'X-Locale' => 'en',
            'X-Currency' => 'USD',
        ];

        $resolved = $this->dispatch('/old-page', $headers);
        self::assertSame(200, $resolved->statusCode());
        $payload = $this->payload($resolved);
        self::assertSame('/pages/new-page', $payload['data']['target']);
        self::assertSame(301, $payload['data']['code']);

        foreach (['/missing-page', '/external-page', '/self-page'] as $path) {
            $response = $this->dispatch($path, $headers);
            self::assertSame(200, $response->statusCode());
            $body = $this->payload($response);
            self::assertNull($body['data']['target'], $path);
        }
    }
}
