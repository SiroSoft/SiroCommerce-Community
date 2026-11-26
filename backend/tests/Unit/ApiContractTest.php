<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiContract;
use App\Modules\Reporting\Application\ReportingService;
use App\Modules\Reporting\Contracts\ReportingReader;
use PHPUnit\Framework\TestCase;

final class ApiContractTest extends TestCase
{
    public function testMetadataAdvertisesBothApiGenerationsWithoutChangingEnvelope(): void
    {
        $metadata = ApiContract::metadata();

        self::assertSame('v1', $metadata['current_api']);
        self::assertSame(['v1', 'v2'], $metadata['supported_apis']);
        self::assertSame(1, $metadata['contract']);
        self::assertSame(['style' => 'page', 'parameters' => ['page', 'per_page'], 'max_per_page' => 100], $metadata['pagination']);
        self::assertSame('envelope', $metadata['error_format']);
        self::assertSame([], $metadata['deprecations']);
        self::assertSame('eventual; writes invalidate relevant read caches', $metadata['read_consistency']);
    }

    public function testReportingRejectsInvalidDateBoundsInsteadOfDefaulting(): void
    {
        $reader = $this->createMock(ReportingReader::class);
        $reader->expects(self::never())->method('overview');

        $this->expectException(\InvalidArgumentException::class);
        (new ReportingService($reader))->overview('not-a-date', null);
    }
}
