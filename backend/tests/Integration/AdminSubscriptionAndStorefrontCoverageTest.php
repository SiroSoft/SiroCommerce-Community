<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Platform\Application\AdminSubscriptionService;
use App\Modules\MarketingAutomation\Application\MarketingAutomationService;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigConflict;
use App\Modules\StorefrontConfig\Infrastructure\StorefrontConfigStoreAdapter;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Tests\TestCase;

final class AdminSubscriptionAndStorefrontCoverageTest extends TestCase
{
    private CommerceScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new CommerceScope('store-sub', 'channel-sub', 'en', 'USD');
    }

    public function testAdminSubscriptionListDetailSummaryAndRetry(): void
    {
        $service = new AdminSubscriptionService();
        $now = date('Y-m-d H:i:s');
        foreach ([
            ['sub-active', 'active', 'USD', 1000, 'monthly', 1],
            ['sub-paused', 'paused', 'USD', 2000, 'yearly', 1],
            ['sub-cancelled', 'cancelled', 'USD', 3000, 'monthly', 1],
        ] as [$id, $state, $currency, $amount, $interval, $count]) {
            Database::table('subscriptions')->insert([
                'id' => $id, 'customer_id' => $id . '-customer', 'plan_id' => 'plan-1', 'provider' => 'sandbox',
                'currency' => $currency, 'amount_minor' => $amount, 'interval' => $interval, 'interval_count' => $count,
                'state' => $state, 'started_at' => $now, 'paused_at' => null, 'cancelled_at' => null,
                'next_billing_at' => $now, 'created_at' => $now, 'store_id' => 'store-sub', 'channel_id' => 'channel-sub',
            ]);
        }
        Database::table('subscriptions')->insert([
            'id' => 'sub-other-store', 'customer_id' => 'other-customer', 'plan_id' => 'plan-1', 'provider' => 'sandbox',
            'currency' => 'USD', 'amount_minor' => 4000, 'interval' => 'monthly', 'interval_count' => 1,
            'state' => 'active', 'started_at' => $now, 'paused_at' => null, 'cancelled_at' => null,
            'next_billing_at' => $now, 'created_at' => $now, 'store_id' => 'other-store', 'channel_id' => 'channel-sub',
        ]);

        Database::table('subscription_renewal_attempts')->insert([
            'id' => 'attempt-succeeded', 'subscription_id' => 'sub-active', 'idempotency_key' => 'attempt-key-1',
            'state' => 'succeeded', 'amount_minor' => 1000, 'currency' => 'USD', 'provider' => 'sandbox',
            'payment_operation_id' => null, 'evidence_json' => '{}', 'last_error' => null, 'attempts' => 1,
            'next_attempt_at' => null, 'created_at' => $now, 'completed_at' => $now,
        ]);
        Database::table('subscription_renewal_attempts')->insert([
            'id' => 'attempt-failed', 'subscription_id' => 'sub-active', 'idempotency_key' => 'attempt-key-2',
            'state' => 'failed', 'amount_minor' => 1000, 'currency' => 'USD', 'provider' => 'sandbox',
            'payment_operation_id' => null, 'evidence_json' => '{}', 'last_error' => 'declined', 'attempts' => 2,
            'next_attempt_at' => null, 'created_at' => $now, 'completed_at' => null,
        ]);

        $list = $service->list($this->scope, 1, 10);
        self::assertSame(3, $list['meta']['total']);
        self::assertCount(3, $list['data']);
        self::assertArrayNotHasKey('customer_id', $list['data'][0]);
        self::assertArrayHasKey('customer_ref', $list['data'][0]);
        $filtered = $service->list($this->scope, 1, 10, 'active', 'sub-active');
        self::assertSame(1, $filtered['meta']['total']);
        self::assertSame('succeeded', $filtered['data'][0]['latest_attempt_state']);

        self::assertNull($service->detail('missing-sub', $this->scope));
        $detail = $service->detail('sub-active', $this->scope);
        self::assertSame('sub-active', $detail['id'] ?? null);
        self::assertCount(2, $detail['renewals']);
        $failedAttempt = null; foreach ($detail['renewals'] as $renewal) { if (($renewal['state'] ?? '') === 'failed') { $failedAttempt = $renewal; } } self::assertNotNull($failedAttempt); self::assertSame('payment_failed', $failedAttempt['error_code']);

        $summary = $service->summary($this->scope, '2026-01-01', '2026-12-31');
        self::assertSame(1, $summary['active']);
        self::assertSame(1, $summary['paused']);
        self::assertSame(1, $summary['cancelled']);
        self::assertEqualsWithDelta(0.5, $summary['churn_rate'], 0.001);
        self::assertSame(1000, $summary['renewal_revenue']['USD']['amount_minor'] ?? null);
        self::assertSame(1000, $summary['active_recurring_revenue']['USD'] ?? null);
        self::assertSame(1, $summary['payment_failure_queue']);
        self::assertFalse($summary['privacy']['pii']);
        try {
            $service->summary($this->scope, '2026-12-31', '2026-01-01');
            self::fail('Invalid subscription report windows must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Reporting window', $error->getMessage());
        }

        try {
            $service->retry('attempt-failed', $this->scope, 'bad key', 'admin', 'corr-1');
            self::fail('Invalid retry keys must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Idempotency-Key', $error->getMessage());
        }
        try {
            $service->retry('missing-attempt', $this->scope, 'retry-key-1', 'admin', 'corr-1');
            self::fail('Missing renewal attempts must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }
        try {
            $service->retry('attempt-succeeded', $this->scope, 'retry-key-1', 'admin', 'corr-1');
            self::fail('Succeeded renewals cannot be retried.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('failed renewals', $error->getMessage());
        }

        $retried = $service->retry('attempt-failed', $this->scope, 'retry-key-1', 'admin', 'corr-1');
        self::assertSame('pending', $retried['state']);
        self::assertSame($retried, $service->retry('attempt-failed', $this->scope, 'retry-key-1', 'admin', 'corr-1'));
    }

    public function testStorefrontConfigDraftPublishRestoreDiffAndCatalog(): void
    {
        $adapter = new StorefrontConfigStoreAdapter();
        $now = date('Y-m-d H:i:s');
        Database::table('storefront_configs')->insert([
            'id' => 1, 'store_id' => 'store-cfg', 'channel_id' => 'channel-cfg', 'locale' => 'en', 'currency' => 'USD',
            'theme_key' => 'editorial', 'vertical_key' => 'general', 'status' => 'published', 'version' => 1,
            'config_json' => json_encode(['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general'], 'homepage' => ['title' => 'Home']], JSON_THROW_ON_ERROR),
            'scheduled_at' => null, 'published_at' => $now, 'created_by' => null, 'updated_by' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $scope = new CommerceScope('store-cfg', 'channel-cfg', 'en', 'USD');

        $published = $adapter->published($scope);
        self::assertSame(1, $published['version'] ?? null);
        self::assertSame('editorial', $published['config']['theme']['key'] ?? null);
        self::assertSame(1, $adapter->draft($scope)['version'] ?? null);
        self::assertNull($adapter->published(new CommerceScope('missing', 'missing', 'en', 'USD')));

        $draft = $adapter->saveDraft($scope, ['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general'], 'homepage' => ['title' => 'Draft']], 1, 'actor-1', 'cfg-key-1');
        self::assertSame('draft', $draft['status']);
        self::assertSame(2, $draft['version']);
        self::assertSame(2, $adapter->saveDraft($scope, ['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general']], 1, 'actor-1', 'cfg-key-1')['version']);
        try {
            $adapter->saveDraft($scope, ['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general']], 1, 'actor-1', 'cfg-key-2');
            self::fail('Stale storefront versions must conflict.');
        } catch (StorefrontConfigConflict $error) {
            self::assertStringContainsString('stale', $error->getMessage());
        }
        try {
            $adapter->saveDraft($scope, ['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general']], 2, 'actor-1', '');
            self::fail('Empty idempotency keys must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Idempotency-Key', $error->getMessage());
        }
        try {
            $adapter->saveDraft(new CommerceScope('missing', 'missing', 'en', 'USD'), ['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general']], 1, 'actor-1', 'cfg-missing');
            self::fail('Missing configurations must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }

        $publishedVersion = $adapter->publish($scope, 2, 'actor-2', 'cfg-publish-1');
        self::assertSame('published', $publishedVersion['status']);
        self::assertSame(3, $publishedVersion['version']);
        self::assertSame(3, $adapter->publish($scope, 2, 'actor-2', 'cfg-publish-1')['version']);

        $changed = $adapter->saveDraft($scope, ['theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general'], 'homepage' => ['title' => 'Changed']], 3, 'actor-2', 'cfg-key-3');
        self::assertSame(4, $changed['version']);
        $revisions = $adapter->revisions($scope);
        self::assertGreaterThanOrEqual(3, count($revisions));
        $diff = $adapter->diff($scope, (string) $revisions[count($revisions) - 1]['id'], (string) $revisions[0]['id']);
        self::assertNotEmpty($diff['changes']);
        try {
            $adapter->diff($scope, 'missing-revision', (string) $revisions[0]['id']);
            self::fail('Missing revisions must be rejected by diff.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }

        $restoreFrom = (string) $revisions[count($revisions) - 1]['id'];
        $restored = $adapter->restore($scope, $restoreFrom, 4, 'actor-3', 'cfg-restore-1');
        self::assertSame('published', $restored['status']);
        self::assertSame(5, $restored['version']);
        try {
            $adapter->restore($scope, 'missing-revision', 5, 'actor-3', 'cfg-restore-2');
            self::fail('Missing revisions must be rejected by restore.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not found', $error->getMessage());
        }

        Database::table('storefront_themes')->insert([
            'id' => 9001, 'key' => 'editorial', 'name' => 'Editorial', 'version' => '1.0.0',
            'schema_version' => 1, 'status' => 'active', 'token_schema_json' => '{}',
            'allowed_section_types_json' => '[]', 'defaults_json' => '{"color":"black"}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $themes = $adapter->themes();
        self::assertSame('Editorial', $themes[0]['name'] ?? null);
        self::assertSame(['color' => 'black'], $themes[0]['defaults'] ?? null);

        Database::table('storefront_vertical_presets')->insert([
            'id' => 9002, 'key' => 'general', 'name' => 'General', 'version' => '1.0.0',
            'status' => 'active', 'theme_defaults_json' => '{"theme":"editorial"}',
            'presentation_defaults_json' => '{"density":"comfortable"}', 'homepage_defaults_json' => '{"sections":[]}',
            'validation_schema_json' => '{"description":"General preset","preview":"/preview.png"}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $presets = $adapter->presets();
        self::assertSame('General preset', $presets[0]['description'] ?? null);
        self::assertSame('/preview.png', $presets[0]['preview'] ?? null);
        self::assertSame(['theme' => 'editorial'], $presets[0]['theme_defaults'] ?? null);
    }

    public function testMarketingAutomationEligibilitySuppressionAndFrequencyCaps(): void
    {
        $service = new MarketingAutomationService();
        $now = date('Y-m-d H:i:s');

        self::assertSame(['scheduled' => false, 'reason' => 'cart_not_found'], $service->abandonedCart('missing-cart', $now));

        Database::table('carts')->insert([
            'id' => 'cart-eligible', 'customer_id' => 'marketing-customer', 'currency' => 'USD', 'state' => 'open',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $noConsent = $service->abandonedCart('cart-eligible', $now);
        self::assertFalse($noConsent['scheduled']);
        self::assertArrayHasKey('reasons', $noConsent);

        Database::table('customer_notification_preferences')->insert([
            'customer_id' => 'marketing-customer', 'transactional_email' => 1, 'marketing_email' => 1,
            'idempotency_key' => null, 'request_fingerprint' => null, 'updated_at' => $now,
        ]);
        $scheduled = $service->abandonedCart('cart-eligible', $now);
        self::assertTrue($scheduled['scheduled']);
        self::assertArrayHasKey('campaign_key', $scheduled);
        $second = $service->abandonedCart('cart-eligible', $now); self::assertFalse($second['scheduled']); self::assertSame(['frequency_cap'], $second['reasons']);

        $wishlist = $service->wishlistReengagement('marketing-customer', 'product-1', $now);
        self::assertTrue($wishlist['scheduled']);
        $capped = $service->wishlistReengagement('marketing-customer', 'product-2', $now);
        self::assertFalse($capped['scheduled']);
        self::assertSame(['frequency_cap'], $capped['reasons']);

        Database::table('carts')->insert([
            'id' => 'cart-converted', 'customer_id' => 'marketing-customer', 'currency' => 'USD', 'state' => 'converted',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $suppressed = $service->abandonedCart('cart-converted', $now);
        self::assertFalse($suppressed['scheduled']);

        $service->suppressAfterPurchase('cart-eligible', null);
        $rows = Database::select("SELECT state FROM marketing_automation_deliveries WHERE cart_id = ?", ['cart-eligible']);
        self::assertSame('suppressed', $rows[0]['state'] ?? null);
        $service->suppressAfterPurchase(null, 'marketing-customer');
    }
}
