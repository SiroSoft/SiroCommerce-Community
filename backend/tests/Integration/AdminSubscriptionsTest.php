<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Platform\Application\AdminSubscriptionService;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

final class AdminSubscriptionsTest extends TestCase
{
    private function scope(string $store = 'sub-store'): CommerceScope { return new CommerceScope($store, 'web', 'en', 'USD'); }

    public function testListIsScopedPaginatedAndRedactsCustomerIdentity(): void
    {
        $db = Database::connection();
        $insert = $db->prepare('INSERT INTO subscriptions (id, customer_id, plan_id, provider, currency, amount_minor, interval, interval_count, state, next_billing_at, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['sub-a', 'customer-a', 'plan-a', 'sandbox', 'USD', 1200, 'month', 1, 'active', '2026-10-01 00:00:00', 'sub-store', 'web']);
        $insert->execute(['sub-b', 'customer-b', 'plan-b', 'sandbox', 'USD', 2400, 'month', 1, 'paused', '2026-10-01 00:00:00', 'other-store', 'web']);
        $result = (new AdminSubscriptionService())->list($this->scope(), 1, 1);
        self::assertSame(1, $result['meta']['total']);
        self::assertSame('sub-a', $result['data'][0]['id']);
        self::assertArrayNotHasKey('customer_id', $result['data'][0]);
        self::assertSame(hash('sha256', 'customer-a'), $result['data'][0]['customer_ref']);
    }

    public function testSummaryPartitionsRevenueAndActiveRecurringValueByCurrency(): void
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO subscriptions (id, customer_id, plan_id, provider, currency, amount_minor, interval, interval_count, state, next_billing_at, store_id, channel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['sub-summary', 'customer-summary', 'plan-a', 'sandbox', 'USD', 1200, 'month', 1, 'active', '2026-10-01 00:00:00', 'sub-store', 'web']);
        $db->prepare('INSERT INTO subscription_renewal_attempts (id, subscription_id, idempotency_key, state, amount_minor, currency, provider, created_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['attempt-summary', 'sub-summary', 'summary-key', 'succeeded', 1200, 'USD', 'sandbox', '2026-09-29 00:00:00', '2026-09-29 00:00:00']);
        $summary = (new AdminSubscriptionService())->summary($this->scope(), '2026-09-01', '2026-09-30');
        self::assertSame(1, $summary['active']);
        self::assertSame(1200, $summary['active_recurring_revenue']['USD']);
        self::assertSame(1200, $summary['renewal_revenue']['USD']['amount_minor']);
        self::assertFalse($summary['privacy']['pii']);
    }
}
