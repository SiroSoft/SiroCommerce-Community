<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Vendor\Application\PayoutCsvExportService;
use App\Modules\Vendor\Application\VendorPayoutService;
use PHPUnit\Framework\TestCase;

final class PayoutCsvExportServiceTest extends TestCase
{
    public function testEmptyBatchStillWritesBomAndHeader(): void
    {
        $result = (new PayoutCsvExportService(new VendorPayoutService()))->build([], []);

        self::assertSame(0, $result['rows']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $result['csv']);
        self::assertSame(
            'payout_id,vendor,amount_minor,currency,period_start,period_end,status',
            trim(substr($result['csv'], 3)),
        );
    }

    public function testRowUsesBankBulkColumns(): void
    {
        $payout = [
            'id' => 'payout-1',
            'vendor_id' => 'vendor-1',
            'amount_minor' => 123456,
            'currency' => 'VND',
            'period_start' => '2026-11-01 00:00:00',
            'period_end' => '2026-11-30 23:59:59',
            'state' => 'pending',
        ];
        $result = (new PayoutCsvExportService(new VendorPayoutService()))->build([$payout], ['vendor-1' => 'North Loom']);

        self::assertSame(1, $result['rows']);
        $lines = preg_split('/\r\n/', trim(substr($result['csv'], 3))) ?: [];
        self::assertSame('payout-1,"North Loom",123456,VND,"2026-11-01 00:00:00","2026-11-30 23:59:59",pending', $lines[1]);
    }
}
