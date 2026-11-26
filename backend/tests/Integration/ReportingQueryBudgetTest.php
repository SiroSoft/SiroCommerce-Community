<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Order;
use App\Models\Refund;
use App\Modules\Platform\Infrastructure\ReportingReadAdapter;
use App\Modules\Reporting\Application\ReportingService;
use Siro\Core\Cache;
use Siro\Core\Database;
use Tests\TestCase;

final class ReportingQueryBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function order(string $id, string $currency, int $total, string $state, string $createdAt): void
    {
        Order::query()->insert([
            'id' => $id,
            'number' => strtoupper($id),
            'currency' => $currency,
            'subtotal_minor' => $total,
            'grand_total_minor' => $total,
            'state' => $state,
            'idempotency_key' => $id . '-key',
            'created_at' => $createdAt,
        ]);
    }

    public function testOverviewAggregatesCurrencyTotalsAndExcludesCancelled(): void
    {
        $day = '2026-09-20 10:00:00';
        $this->order('usd-1', 'USD', 100, 'paid', $day);
        $this->order('usd-2', 'USD', 200, 'paid', $day);
        $this->order('usd-3', 'USD', 999, 'cancelled', $day);
        $this->order('eur-1', 'EUR', 50, 'paid', $day);

        Refund::query()->insert(['id' => 'ref-usd', 'order_id' => 'usd-1', 'payment_id' => 'pay-1', 'amount_minor' => 30, 'currency' => 'USD', 'state' => 'succeeded', 'created_at' => $day]);
        Refund::query()->insert(['id' => 'ref-eur', 'order_id' => 'eur-1', 'payment_id' => 'pay-2', 'amount_minor' => 10, 'currency' => 'EUR', 'state' => 'succeeded', 'created_at' => $day]);

        $result = (new ReportingService(new ReportingReadAdapter()))->overview('2026-09-20', '2026-09-20');

        self::assertSame(['EUR' => 50, 'USD' => 300], $result['sales_minor_by_currency']);
        self::assertSame(['EUR' => 10, 'USD' => 30], $result['refunds_minor_by_currency']);
        self::assertNull($result['sales_minor']);
        self::assertNull($result['refunds_minor']);
        self::assertSame(4, $result['orders']);
    }

    public function testOverviewQueryCountDoesNotGrowWithOrderCount(): void
    {
        foreach (range(1, 5) as $i) $this->order('small-' . $i, 'USD', 100, 'paid', '2026-09-20 10:00:00');
        foreach (range(1, 30) as $i) $this->order('large-' . $i, 'USD', 100, 'paid', '2026-09-21 10:00:00');

        $reports = new ReportingService(new ReportingReadAdapter());
        Database::enableQueryCapture();

        Database::resetCapturedQueries();
        $small = $reports->overview('2026-09-20', '2026-09-20');
        $smallQueries = count(Database::getCapturedQueries());

        Cache::flush();
        Database::resetCapturedQueries();
        $large = $reports->overview('2026-09-21', '2026-09-21');
        $largeQueries = count(Database::getCapturedQueries());

        self::assertSame(5, $small['orders']);
        self::assertSame(30, $large['orders']);
        self::assertSame($smallQueries, $largeQueries, 'overview query count must be independent of row count');
        self::assertLessThanOrEqual(12, $largeQueries);
    }
}
