<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Dashboard\Application\DashboardBiService;
use App\Modules\Dashboard\Contracts\DashboardBiReader;
use App\Support\CommerceScope;
use Siro\Core\Cache;
use Tests\TestCase;

final class DashboardBiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function testRangeWindowsUseInclusiveCalendarDays(): void
    {
        $now = (int) strtotime('2026-09-20 12:00:00');

        self::assertSame(['2026-09-14 00:00:00', '2026-09-20 23:59:59'], DashboardBiService::windowForRange('7d', $now));
        self::assertSame(['2026-08-22 00:00:00', '2026-09-20 23:59:59'], DashboardBiService::windowForRange('30d', $now));
        self::assertSame(['2026-06-23 00:00:00', '2026-09-20 23:59:59'], DashboardBiService::windowForRange('90d', $now));

        $this->expectException(\InvalidArgumentException::class);
        DashboardBiService::windowForRange('14d', $now);
    }

    public function testCacheIsKeyedByRangeAndScope(): void
    {
        $reader = $this->reader();
        $service = new DashboardBiService($reader);
        $scope = new CommerceScope('store-a', 'channel-a', 'en', 'VND');

        $service->bi('7d', $scope);
        self::assertSame(5, $reader->calls);

        $service->bi('7d', $scope);
        self::assertSame(5, $reader->calls, 'same range and scope must reuse the 60s cache entry');

        $service->bi('30d', $scope);
        self::assertSame(10, $reader->calls, 'range is part of the cache key');

        $service->bi('30d', new CommerceScope('store-b', 'channel-a', 'en', 'VND'));
        self::assertSame(15, $reader->calls, 'scope is part of the cache key');
    }

    public function testFailureFallsBackToZerosInsteadOfThrowing(): void
    {
        $reader = $this->reader(true);
        $result = (new DashboardBiService($reader))->bi('7d', new CommerceScope('store-a', 'channel-a', 'en', 'VND'));

        self::assertIsArray($result['sales']);
        self::assertSame(0, $result['sales']['gross_minor']);
        self::assertSame(0, $result['sales']['orders']);
        self::assertSame(0, $result['sales']['aov_minor']);
        self::assertSame(0, $result['sales']['refunds_minor']);
        self::assertSame([], $result['sales']['by_day']);
        self::assertSame(['sessions' => 0, 'product_views' => 0, 'add_to_cart' => 0, 'checkout_started' => 0, 'orders' => 0], $result['funnel']);
        self::assertSame(['failed_payments' => 0, 'pending_refunds' => 0, 'queue_dead_letters' => 0, 'open_ops_alerts' => 0], $result['ops']);
        self::assertSame([], $result['top_products']);
        self::assertSame([], $result['low_stock']);
    }

    public function testSingleCurrencySalesStayAnObjectAndMultiCurrencyBecomesAList(): void
    {
        $single = $this->reader(false, [[
            'currency' => 'VND', 'gross_minor' => 300, 'orders' => 3, 'aov_minor' => 100, 'refunds_minor' => 10,
            'by_day' => [['date' => '2026-09-20', 'gross_minor' => 300, 'orders' => 3]],
        ]]);
        $singleResult = (new DashboardBiService($single))->bi('7d', new CommerceScope('s', 'c', 'en', 'VND'));
        self::assertArrayHasKey('gross_minor', $singleResult['sales']);

        $multi = $this->reader(false, [
            ['currency' => 'USD', 'gross_minor' => 100, 'orders' => 1, 'aov_minor' => 100, 'refunds_minor' => 0, 'by_day' => []],
            ['currency' => 'VND', 'gross_minor' => 300, 'orders' => 3, 'aov_minor' => 100, 'refunds_minor' => 0, 'by_day' => []],
        ]);
        $multiResult = (new DashboardBiService($multi))->bi('7d', new CommerceScope('s-multi', 'c', 'en', 'VND'));
        self::assertCount(2, $multiResult['sales']);
        self::assertCount(2, $multiResult['sales_by_currency']);
    }

    /** @param list<array<string, mixed>> $sales */
    private function reader(bool $fail = false, array $sales = []): DashboardBiReader
    {
        return new class ($fail, $sales) implements DashboardBiReader {
            public int $calls = 0;
            public function __construct(private readonly bool $fail, private readonly array $sales) {}
            private function touch(): void
            {
                $this->calls++;
                if ($this->fail) throw new \RuntimeException('aggregate failed');
            }
            public function sales(string $from, string $to, CommerceScope $scope): array
            {
                $this->touch();
                return $this->sales;
            }
            public function funnel(string $from, string $to, CommerceScope $scope): array
            {
                $this->touch();
                return ['sessions' => 0, 'product_views' => 0, 'add_to_cart' => 0, 'checkout_started' => 0, 'orders' => 0];
            }
            public function topProducts(string $from, string $to, CommerceScope $scope): array
            {
                $this->touch();
                return [];
            }
            public function lowStock(CommerceScope $scope): array
            {
                $this->touch();
                return [];
            }
            public function ops(CommerceScope $scope): array
            {
                $this->touch();
                return ['failed_payments' => 0, 'pending_refunds' => 0, 'queue_dead_letters' => 0, 'open_ops_alerts' => 0];
            }
        };
    }
}
