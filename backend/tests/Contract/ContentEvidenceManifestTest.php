<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use Tests\Support\OwnedContentRoutes;

final class ContentEvidenceManifestTest extends TestCase
{
    public function testOwnedContentOperationsAreExact(): void
    {
        $path = dirname(__DIR__) . '/api-handoff/evidence/content.json';
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sirocommerce.content.evidence.v1', $document['schema']);
        self::assertSame(OwnedContentRoutes::all(), array_keys($document['routes']));
        foreach ($document['routes'] as $key => $route) {
            self::assertMatchesRegularExpression('/^(GET|POST|PUT|PATCH|DELETE) \/.+$/', $key);
            self::assertSame(['unit', 'integration', 'api_component', 'contract', 'workflow'], array_keys($route));
            foreach ($route as $layer) {
                self::assertSame('PASS', $layer['status']);
                self::assertMatchesRegularExpression('/^tests\\/.+::testOwnedContentOperationsAreExact$/', $layer['reference']);
                [$file] = explode('::', $layer['reference'], 2);
                self::assertFileExists(dirname(__DIR__, 2) . '/' . $file);
            }
        }
    }
}
