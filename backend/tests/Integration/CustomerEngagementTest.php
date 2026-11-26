<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Customer;
use App\Models\CustomerNotificationPreference;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Platform\Infrastructure\CustomerEngagementService;
use Tests\TestCase;

final class CustomerEngagementTest extends TestCase
{
    public function testPreferencesDefaultToTransactionalOnlyAndCanOptOut(): void
    {
        $customer = $this->customer('pref-customer');
        $service = new CustomerEngagementService();

        self::assertSame(['transactional_email' => 1, 'marketing_email' => 0, 'updated_at' => null], $service->preferences($customer));
        self::assertSame(0, $service->updatePreferences($customer, ['transactional_email' => false])['transactional_email']);
    }

    public function testEarnIsOwnedIdempotentAndRedeemCannotOverdraw(): void
    {
        $customer = $this->customer('loyalty-customer');
        Order::create(['id' => 'loyalty-order-1', 'number' => 'LOYALTY-1', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'delivered', 'idempotency_key' => 'loyalty-order-key']);
        $this->settledPayment('loyalty-order-1');
        $service = new CustomerEngagementService();

        $first = $service->earn($customer, 'loyalty-order-1', 'earn-1');
        self::assertSame($first['idempotency_key'], $service->earn($customer, 'loyalty-order-1', 'earn-1')['idempotency_key']);
        self::assertSame(10, $service->loyalty($customer)['balance_points']);
        $this->expectException(\InvalidArgumentException::class);
        $service->redeem($customer, 11, 'redeem-too-much');
    }

    public function testExpiryIsIdempotentAndOnlyExpiresEligiblePoints(): void
    {
        $customer = $this->customer('expiry-customer');
        Order::create(['id' => 'expiry-order-1', 'number' => 'EXPIRY-1', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 5000, 'grand_total_minor' => 5000, 'state' => 'delivered', 'idempotency_key' => 'expiry-order-key']);
        $this->settledPayment('expiry-order-1');
        $service = new CustomerEngagementService();
        $service->earn($customer, 'expiry-order-1', 'earn-expiry');

        $expired = $service->expire($customer, date('c', time() + 31600000), 'expire-1');
        self::assertSame(-50, $expired['points']);
        self::assertSame($expired['idempotency_key'], $service->expire($customer, date('c', time() + 31600000), 'expire-1')['idempotency_key']);
        self::assertSame(0, $service->loyalty($customer)['balance_points']);
    }

    public function testEarnRequiresDeliveredOwnedOrderAndRejectsNegativeTotals(): void
    {
        $customer = $this->customer('ownership-customer');
        $other = $this->customer('other-customer');
        Order::create(['id' => 'not-delivered-order', 'number' => 'NOT-DELIVERED', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'paid', 'idempotency_key' => 'not-delivered-key']);
        Order::create(['id' => 'other-order', 'number' => 'OTHER', 'customer_id' => $other, 'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'delivered', 'idempotency_key' => 'other-order-key']);
        Order::create(['id' => 'negative-order', 'number' => 'NEGATIVE', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => -100, 'grand_total_minor' => -100, 'state' => 'delivered', 'idempotency_key' => 'negative-order-key']);
        $service = new CustomerEngagementService();

        try {
            $service->earn($customer, 'not-delivered-order', 'state-key');
            self::fail('Undelivered orders must not earn points');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $service->earn($customer, 'other-order', 'owner-key');
            self::fail('An order owned by another customer must not earn points');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        try {
            $service->earn($customer, 'negative-order', 'negative-key');
            self::fail('Negative order totals must not earn points');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testIdempotencyKeyReuseRequiresTheSameRequestAndCustomer(): void
    {
        $customer = $this->customer('key-customer');
        $other = $this->customer('key-other');
        Order::create(['id' => 'key-order-1', 'number' => 'KEY-1', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'delivered', 'idempotency_key' => 'key-order-1-order']);
        $this->settledPayment('key-order-1');
        Order::create(['id' => 'key-order-2', 'number' => 'KEY-2', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 2000, 'grand_total_minor' => 2000, 'state' => 'delivered', 'idempotency_key' => 'key-order-2-order']);
        $service = new CustomerEngagementService();
        $service->earn($customer, 'key-order-1', 'same-key');

        try {
            $service->earn($customer, 'key-order-2', 'same-key');
            self::fail('A key must not be reused for a different order');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            $service->earn($other, 'key-order-1', 'same-key');
            self::fail('A key must not be replayed by another customer');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
    }

    public function testRefundReversalIsProportionalAndIdempotent(): void
    {
        $customer = $this->customer('refund-loyalty-customer');
        Order::create(['id' => 'refund-loyalty-order', 'number' => 'REFUND-LOYALTY', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 10000, 'grand_total_minor' => 10000, 'state' => 'delivered', 'idempotency_key' => 'refund-loyalty-order-key']);
        $this->settledPayment('refund-loyalty-order');
        $service = new CustomerEngagementService();
        $service->earn($customer, 'refund-loyalty-order', 'refund-earn');

        $first = $service->reverseForRefund($customer, 'refund-loyalty-order', 2500, 10000, 'refund-loyalty-1');
        self::assertSame(-25, $first['points']);
        self::assertSame($first['idempotency_key'], $service->reverseForRefund($customer, 'refund-loyalty-order', 2500, 10000, 'refund-loyalty-1')['idempotency_key']);
        self::assertSame(75, $service->loyalty($customer)['balance_points']);
        self::assertSame(-50, $service->reverseForRefund($customer, 'refund-loyalty-order', 5000, 10000, 'refund-loyalty-2')['points']);
        self::assertSame(50, $service->loyalty($customer)['balance_points']);
    }

    public function testPreferenceFlagsAreStrictAndStoredWithoutPii(): void
    {
        $customer = $this->customer('preference-strict');
        $service = new CustomerEngagementService();
        self::assertSame(1, $service->updatePreferences($customer, ['marketing_email' => true])['marketing_email']);
        $stored = CustomerNotificationPreference::query()->where('customer_id', $customer)->first();
        self::assertNotNull($stored);
        self::assertArrayNotHasKey('email', $stored->toArray());

        $this->expectException(\InvalidArgumentException::class);
        $service->updatePreferences($customer, ['marketing_email' => 'yes']);
    }

    public function testPreferenceUpdatesPreserveOmittedFlagsAndReplayIdempotently(): void
    {
        $customer = $this->customer('preference-idempotent');
        $service = new CustomerEngagementService();
        $first = $service->updatePreferences($customer, ['marketing_email' => true], 'preference-key');
        $replay = $service->updatePreferences($customer, ['marketing_email' => true], 'preference-key');

        self::assertSame($first['idempotency_key'], $replay['idempotency_key']);
        self::assertSame($first['request_fingerprint'], $replay['request_fingerprint']);
        try {
            $service->updatePreferences($customer, ['marketing_email' => false], 'preference-key');
            self::fail('A preference idempotency key must not be reused for a different request');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame(1, $service->updatePreferences($customer, ['transactional_email' => false])['marketing_email']);
    }

    public function testExpiryIncludesTheExactBoundaryAndRejectsInvalidKeys(): void
    {
        $customer = $this->customer('boundary-customer');
        Order::create(['id' => 'boundary-order', 'number' => 'BOUNDARY', 'customer_id' => $customer, 'currency' => 'VND', 'subtotal_minor' => 100, 'grand_total_minor' => 100, 'state' => 'delivered', 'idempotency_key' => 'boundary-order-key']);
        $this->settledPayment('boundary-order');
        $service = new CustomerEngagementService();
        $service->earn($customer, 'boundary-order', 'boundary-earn');
        $earned = LoyaltyLedger::query()->where('idempotency_key', 'boundary-earn')->first();
        self::assertNotNull($earned);
        $expiresAt = (string) $earned->getAttribute('expires_at');
        self::assertSame(-1, $service->expire($customer, $expiresAt, 'boundary-expire')['points']);
        self::assertSame(0, $service->loyalty($customer)['balance_points']);

        $this->expectException(\InvalidArgumentException::class);
        $service->redeem($customer, 1, '   ');
    }

    private function customer(string $id): string
    {
        Customer::create(['id' => $id, 'email' => $id . '@example.test', 'name' => 'Test Customer', 'created_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    private function settledPayment(string $orderId): void
    {
        Payment::create(['id' => $orderId . '-payment', 'order_id' => $orderId, 'provider' => 'test', 'amount_minor' => 10000, 'currency' => 'VND', 'state' => 'captured', 'captured_amount_minor' => 10000, 'idempotency_key' => $orderId . '-payment-key']);
    }
}
