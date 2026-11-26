<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class PlatformContractTest extends TestCase
{
    public function testPlatformEvidenceMapsEveryOwnedOperationToFiveLayers(): void
    {
        $path = dirname(__DIR__, 2) . '/tests/api-handoff/evidence/platform.json';
        $evidence = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sirocommerce.platform.api-evidence.v2', $evidence['schema']);
        self::assertCount(43, $evidence['operations']);
        foreach ($evidence['operations'] as $operation => $layers) {
            self::assertSame(['unit', 'integration', 'api_component', 'contract', 'workflow'], array_keys($layers), $operation);
            foreach ($layers as $layer => $record) {
                self::assertSame('PASS', $record['status'], $operation . ' ' . $layer);
                self::assertNotSame('', trim((string) $record['evidence']), $operation . ' ' . $layer);
            }
        }
    }
}
