<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Modules\Platform\Infrastructure\ReportingReadAdapter;
use App\Modules\Search\Contracts\SearchAdapter;
use PHPUnit\Framework\TestCase;
final class SearchRouteEvidenceTest extends TestCase
{
    public function testSearchAdapterAndReportingReaderShareTheProviderBoundary(): void
    {
        $adapter = $this->createMock(SearchAdapter::class);
        $adapter->expects(self::once())->method('search')->with(self::callback(static fn (array $request): bool => $request['q'] === 'lamp' && $request['type'] === 'catalog'))->willReturn(['data' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]]);
        self::assertSame(0, (new ReportingReadAdapter($adapter))->search('lamp', 'catalog', 1, 20)['meta']['total']);
    }
}
