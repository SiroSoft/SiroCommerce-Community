<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use Siro\Core\Audit;
use Siro\Core\Event;
use Siro\Core\Metrics;
use Siro\Core\Container;
use App\Modules\Subscriptions\Application\SubscriptionService;
use App\Modules\Analytics\Application\AnalyticsService;
use App\Modules\MarketingAutomation\Application\MarketingAutomationService;
use App\Modules\Vendor\Application\VendorPayoutService;

final class CommerceEventListeners
{
    public function register(): void
    {
        static $registered = false;
        if ($registered) return;
        $registered = true;
        $analytics = Container::getInstance()->make(AnalyticsService::class);
        if (!$analytics instanceof AnalyticsService) throw new \RuntimeException('Analytics binding invalid');
        $automation = new MarketingAutomationService();
        foreach (['order.placed', 'order.transitioned', 'order.refunded', 'payment.captured', 'payment.failed', 'refund.created', 'refund.succeeded', 'subscription.started', 'subscription.paused', 'subscription.resumed', 'subscription.cancelled', 'subscription.renewed', 'subscription.payment_failed', 'b2b.order.submitted', 'b2b.order.approved', 'b2b.order.rejected', 'promotion.applied', 'promotion.rejected', 'fulfillment.allocated', 'fulfillment.shipped', 'fulfillment.delivered', 'fulfillment.cancelled', 'fulfillment.failed', 'fulfillment.ready_for_pickup', 'fulfillment.picked_up'] as $event) {
            Event::on($event, static function (mixed $payload) use ($analytics, $event): void {
                $data = [];
                if (is_array($payload)) foreach ($payload as $key => $value) if (is_string($key)) $data[$key] = $value;
                // Analytics must never break the flow it observes: a store
                // outage or an unconfigured test database turns recording into
                // a no-op instead of a failed subscription, order, or payment.
                try {
                    $analytics->record($event, $data);
                } catch (\Throwable) {
                    // Best effort: the business event already happened.
                }
            });
        }
        Event::on('order.placed', static function (mixed $payload): void {
            $data = [];
            if (is_array($payload)) foreach ($payload as $key => $value) if (is_string($key)) $data[$key] = $value;
            Audit::log('commerce.order.placed', $data);
            Metrics::counter('commerce_orders_total', 1, ['state' => 'placed']);
        });
        // Vendor commission ledger: sale on paid, negative entry on refund.
        // Best effort: the order/payment outcome must never depend on it.
        Event::on('order.transitioned', static function (mixed $payload): void {
            if (!is_array($payload) || ($payload['to'] ?? null) !== 'paid') return;
            $orderId = is_string($payload['order_id'] ?? null) ? $payload['order_id'] : '';
            if ($orderId === '') return;
            try {
                $service = Container::getInstance()->make(VendorPayoutService::class);
                if ($service instanceof VendorPayoutService) $service->recordSalesForOrder($orderId);
            } catch (\Throwable) {
                // Best effort: the business transition already happened.
            }
        });
        Event::on('order.refunded', static function (mixed $payload): void {
            if (!is_array($payload)) return;
            $orderId = is_string($payload['order_id'] ?? null) ? $payload['order_id'] : '';
            $amount = is_int($payload['amount_minor'] ?? null) ? $payload['amount_minor'] : 0;
            $key = is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : '';
            $allocation = is_array($payload['allocation'] ?? null) ? $payload['allocation'] : [];
            if ($orderId === '' || $amount <= 0 || $key === '') return;
            /** @var array<int, array<string, mixed>> $rows */
            $rows = [];
            foreach ($allocation as $line) if (is_array($line)) {
                $row = [];
                foreach ($line as $field => $value) if (is_string($field)) $row[$field] = $value;
                $rows[] = $row;
            }
            try {
                $service = Container::getInstance()->make(VendorPayoutService::class);
                if ($service instanceof VendorPayoutService) $service->recordRefund($orderId, $amount, $key, $rows);
            } catch (\Throwable) {
                // Best effort: the refund already happened.
            }
        });
        Event::on('order.placed', static function (mixed $payload) use ($automation): void {
            if (!is_array($payload)) return;
            $cartId = is_string($payload['cart_id'] ?? null) ? $payload['cart_id'] : null;
            $customerId = is_string($payload['customer_id'] ?? null) ? $payload['customer_id'] : null;
            $automation->suppressAfterPurchase($cartId, $customerId);
        });
        Event::on('cart.quoted', static function (mixed $payload): void {
            Metrics::counter('commerce_cart_quotes_total');
        });
        Event::on('subscription.renewal', static function (mixed $payload): void {
            if (!is_array($payload)) throw new \RuntimeException('Invalid subscription renewal payload');
            $service = Container::getInstance()->make(SubscriptionService::class);
            if (!$service instanceof SubscriptionService) throw new \RuntimeException('Subscription service unavailable');
            $service->processRenewal(is_string($payload['subscription_id'] ?? null) ? $payload['subscription_id'] : '', is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : '');
        });
        (new OrderNotificationListener())->register();
    }
}
