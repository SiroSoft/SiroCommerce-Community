<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;

final class CommerceEvidenceFragmentTest extends TestCase
{
    public function testCommerceFragmentIsExactAndEveryPassHasARealEvidenceReference(): void
    {
        $base = dirname(__DIR__, 2);
        $fragment = json_decode((string) file_get_contents($base . '/tests/api-handoff/evidence/commerce.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('sirocommerce.api-handoff.domain.v1', $fragment['schema']);
        self::assertSame(41, $fragment['owned_operation_count']);
        self::assertCount($fragment['owned_operation_count'], $fragment['operations']);
        self::assertSame(['unit', 'integration', 'api_component', 'contract', 'workflow'], $fragment['layers']);

        foreach ($fragment['operations'] as $operation => $layers) {
            self::assertMatchesRegularExpression('/^(GET|POST|PUT|PATCH|DELETE) \/api\//', $operation);
            self::assertSame($fragment['layers'], array_keys($layers));
            foreach ($layers as $layer) {
                self::assertContains($layer['status'], ['PASS', 'BLOCKED', 'SKIP']);
                if ($layer['status'] === 'PASS') {
                    self::assertNotSame('', $layer['evidence']);
                    self::assertFileExists($base . '/' . $layer['evidence']);
                } else {
                    self::assertNotSame('', $layer['reason']);
                    self::assertNotSame('', $layer['owner']);
                    self::assertNotSame('', $layer['issue']);
                }
            }
        }
    }
}
