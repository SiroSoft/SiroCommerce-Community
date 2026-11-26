<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ProductSubscriptionPlan;
use App\Modules\Platform\Application\AdminSubscriptionPlanService;
use App\Repositories\ProductRepository;
use App\Resources\ProductResource;
use Siro\Core\Database;
use Tests\TestCase;

final class SubscriptionPlansTest extends TestCase
{
    private function planData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Monthly care',
            'amount_minor' => 1200,
            'currency' => 'usd',
            'interval' => 'monthly',
            'interval_count' => 1,
            'trial_period' => 'weekly',
            'trial_period_count' => 7,
            'state' => 'active',
            'provider' => 'sandbox',
        ], $overrides);
    }

    public function testPlanCrudListsFiltersAndValidates(): void
    {
        $service = new AdminSubscriptionPlanService();
        $created = $service->create($this->planData(['name' => 'Monthly plan']));
        self::assertSame('MONTHLY', strtoupper($created['interval']));
        self::assertSame('USD', $created['currency']);
        self::assertSame(1200, $created['amount_minor']);
        self::assertSame('weekly', $created['trial_period']);
        self::assertSame(7, $created['trial_period_count']);
        self::assertSame('sandbox', $created['provider']);

        $second = $service->create($this->planData(['name' => 'Yearly plan', 'interval' => 'yearly', 'state' => 'disabled', 'trial_period' => null, 'trial_period_count' => 0]));
        self::assertCount(1, $service->list('disabled'));
        self::assertSame($second['id'], $service->list('disabled')[0]['id']);

        $updated = $service->update($created['id'], ['amount_minor' => 1500, 'interval' => 'weekly', 'interval_count' => 3]);
        self::assertSame(1500, $updated['amount_minor']);
        self::assertSame('weekly', $updated['interval']);
        self::assertSame(3, $updated['interval_count']);

        $invalid = [
            ['name' => ''],
            ['amount_minor' => 0],
            ['amount_minor' => -1],
            ['currency' => 'US'],
            ['interval' => 'daily'],
            ['interval_count' => 0],
            ['interval_count' => 13],
            ['trial_period_count' => 91],
            ['trial_period' => 'daily'],
            ['state' => 'archived'],
        ];
        foreach ($invalid as $override) {
            try {
                $service->create($this->planData($override));
                self::fail('Expected invalid plan payload: ' . json_encode($override, JSON_THROW_ON_ERROR));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDeleteIsBlockedWhileSubscriptionsReferenceThePlan(): void
    {
        $service = new AdminSubscriptionPlanService();
        $plan = $service->create($this->planData());
        $db = Database::connection();
        $db->prepare('INSERT INTO subscriptions (id, customer_id, plan_id, provider, currency, amount_minor, interval, interval_count, state, next_billing_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['plan-ref-sub', 'customer', $plan['id'], 'sandbox', 'USD', 1200, 'monthly', 1, 'active', '2026-12-01 00:00:00']);

        try {
            $service->delete($plan['id']);
            self::fail('Expected a plan in use to be protected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('referenced', $e->getMessage());
        }
        $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute(['plan-ref-sub']);
        self::assertTrue($service->delete($plan['id']));
    }

    public function testProductLinksReplaceAllAndRejectUnknownOrDisabledPlans(): void
    {
        $plans = new AdminSubscriptionPlanService();
        $monthly = $plans->create($this->planData(['name' => 'Monthly']));
        $weekly = $plans->create($this->planData(['name' => 'Weekly', 'interval' => 'weekly']));
        $disabled = $plans->create($this->planData(['name' => 'Disabled', 'state' => 'disabled']));
        $products = new ProductRepository();
        $product = $products->store(['id' => 'plan-product-1', 'name' => 'Plan product', 'slug' => 'plan-product', 'type' => 'simple', 'status' => 'active', 'store_id' => 'default', 'channel_id' => 'default', 'locale' => 'en', 'currency' => 'USD']);

        $products->syncSubscriptionPlans($product['id'], [$monthly['id'], $weekly['id']]);
        self::assertSame([$monthly['id'], $weekly['id']], $products->subscriptionPlanIdsForProduct($product['id']));
        $exposed = $products->subscriptionPlansForProduct($product['id']);
        self::assertCount(2, $exposed);
        self::assertSame(['id', 'name', 'amount_minor', 'currency', 'interval', 'interval_count', 'trial_period', 'trial_period_count'], array_keys($exposed[0]));

        $products->syncSubscriptionPlans($product['id'], [$weekly['id']]);
        self::assertSame([$weekly['id']], $products->subscriptionPlanIdsForProduct($product['id']));
        self::assertSame(1, ProductSubscriptionPlan::query()->where('product_id', $product['id'])->count());

        foreach ([['missing-plan'], [$disabled['id']]] as $badIds) {
            try {
                $products->syncSubscriptionPlans($product['id'], $badIds);
                self::fail('Expected the unknown/disabled plan link to be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('unknown or disabled', $e->getMessage());
            }
        }
        self::assertSame([$weekly['id']], $products->subscriptionPlanIdsForProduct($product['id']));
        self::assertCount(2, ProductResource::make(['name' => 'Plan product', 'subscription_plans' => $exposed])['subscription_plans']);
    }
}
