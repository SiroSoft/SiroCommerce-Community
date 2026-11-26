<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Order;
use App\Modules\Platform\Infrastructure\ReportingReadAdapter;
use App\Modules\Reporting\Application\ReportingService;
use App\Repositories\ProductRepository;
use App\Models\Store;
use App\Models\Channel;
use App\Support\ReportingScope;
use Tests\TestCase;

final class ReportingContractTest extends TestCase
{
    public function testCustomerSearchIsPaginatedAndMasksPii(): void
    {
        Store::create(['id' => 'report-store', 'code' => 'report-store', 'name' => 'Report Store', 'status' => 'active']);
        Channel::create(['id' => 'report-channel', 'store_id' => 'report-store', 'code' => 'web', 'name' => 'Web', 'status' => 'active']);
        (new ProductRepository())->store(['id' => 'report-product', 'name' => 'Report Lamp', 'slug' => 'report-lamp', 'status' => 'active', 'store_id' => 'report-store', 'channel_id' => 'report-channel']);
        Customer::query()->insert(['id' => 'report-customer', 'name' => 'Private Buyer', 'email' => 'buyer@example.test', 'created_at' => date('Y-m-d H:i:s')]);
        Order::query()->insert(['id' => 'report-order', 'number' => 'REPORT-1', 'customer_id' => 'report-customer', 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'paid', 'idempotency_key' => 'report-order-key', 'store_id' => 'report-store', 'channel_id' => 'report-channel', 'created_at' => date('Y-m-d H:i:s')]);

        $result = (new ReportingService(new ReportingReadAdapter()))->search('buyer', 'customers', 1, 1, 'report-store', 'report-channel');

        self::assertSame(1, $result['meta']['total']);
        self::assertSame('b***@example.test', $result['data'][0]['email_masked']);
        self::assertArrayNotHasKey('email', $result['data'][0]);
    }

    public function testReportingWindowIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportingService(new ReportingReadAdapter()))->overview('2020-01-01', '2026-01-01');
    }

    public function testReportingScopeRejectsUnknownOrCrossStoreChannelIds(): void
    {
        Store::create(['id' => 'report-store-a', 'code' => 'a', 'name' => 'A', 'status' => 'active']);
        Store::create(['id' => 'report-store-b', 'code' => 'b', 'name' => 'B', 'status' => 'active']);
        Channel::create(['id' => 'report-channel-a', 'store_id' => 'report-store-a', 'code' => 'web', 'name' => 'Web', 'status' => 'active']);

        $this->expectExceptionMessage('Reporting scope not found');
        ReportingScope::validate('report-store-b', 'report-channel-a');
    }

    public function testReportingScopeRequiresBothDimensions(): void
    {
        $this->expectExceptionMessage('store_id and channel_id must be provided together');
        ReportingScope::validate('report-store-a', null);
    }

}
