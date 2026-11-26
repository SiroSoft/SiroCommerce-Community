<?php
declare(strict_types=1);
namespace Tests\Contract;
use PHPUnit\Framework\TestCase;
final class SearchRouteEvidenceTest extends TestCase
{
    public function testSearchRoutesArePresentInGeneratedOpenApiAndSdk(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');
        foreach (['/api/v2/admin/search', '/api/v2/admin/search/status', '/api/v2/admin/search/reindex'] as $path) self::assertArrayHasKey($path, $openapi['paths']);
        self::assertStringContainsString('get_api_v2_admin_search_status', $sdk);
        self::assertStringContainsString('post_api_v2_admin_search_reindex', $sdk);
    }
}
