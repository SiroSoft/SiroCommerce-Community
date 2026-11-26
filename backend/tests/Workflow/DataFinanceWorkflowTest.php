<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Models\Order;
use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Modules\Platform\Infrastructure\BiExportService;
use App\Modules\Platform\Infrastructure\CustomerPrivacyService;
use App\Modules\Platform\Infrastructure\PromotionAllocationService;
use App\Modules\Reporting\Application\ReportingService;
use App\Modules\Platform\Infrastructure\ReportingReadAdapter;
use App\Support\Ids;
use Siro\Core\Queue;
use Siro\Core\Storage;
use Tests\TestCase;

final class DataFinanceWorkflowTest extends TestCase
{
    public function testReportingWorkflowRejectsAnUnboundedWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportingService(new ReportingReadAdapter()))->overview('2020-01-01', '2026-01-01');
    }

    public function testBiExportWorkflowCompletesOnlyAfterSnapshotAndRedaction(): void
    {
        Queue::fake();
        Storage::fake();
        $id = Ids::new();
        Order::create([
            'id' => $id, 'number' => 'WF-EXPORT', 'guest_email' => 'secret@example.test',
            'currency' => 'USD', 'subtotal_minor' => 100, 'grand_total_minor' => 100,
            'state' => 'paid', 'idempotency_key' => Ids::new(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $service = new BiExportService();
        $export = $service->create('orders', 'jsonl', date('Y-m-d'), date('Y-m-d'));
        self::assertSame('queued', $export['state']);
        $service->process((string) $export['id']);
        $status = $service->status((string) $export['id']);
        self::assertSame('completed', $status['state']);
        self::assertStringNotContainsString('secret@example.test', (string) Storage::get('exports/' . $export['id'] . '.jsonl'));
        Storage::reset();
    }

    public function testFinanceAllocationWorkflowRejectsChangedReplayAndPrivacyHoldBlocksAnonymization(): void
    {
        Order::create([
            'id' => 'wf-order', 'number' => 'WF-ORDER', 'currency' => 'USD',
            'subtotal_minor' => 10000, 'discount_minor' => 1000, 'shipping_minor' => 0,
            'tax_minor' => 0, 'grand_total_minor' => 9000, 'state' => 'paid',
            'idempotency_key' => 'wf-order-key',
        ]);
        $allocations = new PromotionAllocationService();
        $allocations->reconcile('wf-order', [['promotion_id' => 'promo-1', 'discount_minor' => 1000]], 'wf-allocation');
        try {
            $allocations->reconcile('wf-order', [['promotion_id' => 'promo-2', 'discount_minor' => 1000]], 'wf-allocation');
            self::fail('Changed allocation replay must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Idempotency key was reused', $e->getMessage());
        }
        Customer::create(['id' => 'wf-customer', 'email' => 'wf@example.test', 'name' => 'Workflow customer', 'created_at' => date('Y-m-d H:i:s')]);
        PrivacyRequest::create([
            'id' => Ids::new(), 'customer_id' => 'wf-customer', 'type' => 'legal_hold',
            'state' => 'completed', 'idempotency_key' => 'wf-hold-key', 'fingerprint' => hash('sha256', 'hold'),
            'legal_hold' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->expectException(\RuntimeException::class);
        (new CustomerPrivacyService())->anonymize('wf-customer', 'wf-privacy-key');
    }
}
