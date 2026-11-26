<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiContract;
use PHPUnit\Framework\TestCase;

final class ApiNormalizationContractTest extends TestCase
{
    public function testContractMetadataDefinesStablePaginationAndErrorConventions(): void
    {
        $metadata = ApiContract::metadata();

        self::assertSame(['page', 'per_page'], $metadata['pagination']['parameters']);
        self::assertSame(100, $metadata['pagination']['max_per_page']);
        self::assertSame('envelope', $metadata['error_format']);
        self::assertSame([], $metadata['deprecations']);
    }
}
