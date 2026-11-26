<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Reporting\Application\ReportingService;
use App\Modules\Reporting\Contracts\ReportingReader;
use PHPUnit\Framework\TestCase;

final class ApiApplicationServiceContractTest extends TestCase
{
    public function testInvalidReportingDateBoundsAreRejectedBeforeTheReaderIsCalled(): void
    {
        $reader = $this->createMock(ReportingReader::class);
        $reader->expects(self::never())->method('overview');

        $this->expectException(\InvalidArgumentException::class);
        (new ReportingService($reader))->overview('not-a-date', null);
    }
}
