<?php
declare(strict_types=1);
namespace Tests\Contract;
use PHPUnit\Framework\TestCase;
final class StorefrontConfigOpenApiTest extends TestCase
{
    public function testLifecycleRoutesAndMutationMetadataAreGenerated(): void
    {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['/api/storefront/config', '/api/admin/storefront/config', '/api/admin/storefront/config/preview', '/api/admin/storefront/config/publish', '/api/admin/storefront/config/restore/{revision}', '/api/admin/storefront/config/revisions', '/api/admin/storefront/config/revisions/diff', '/api/admin/storefront/themes', '/api/admin/storefront/vertical-presets', '/api/admin/storefront/config/apply-preset'] as $path) self::assertArrayHasKey($path, $document['paths']);
        self::assertTrue($document['paths']['/api/admin/storefront/config/publish']['post']['x-idempotency']['required']);
        self::assertContains('cms', $document['paths']['/api/admin/storefront/config/publish']['post']['x-capability-gates']);
    }
}
