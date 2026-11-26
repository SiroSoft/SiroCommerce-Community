<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Analytics\Application\AnalyticsService;
use App\Modules\Analytics\Contracts\AnalyticsStore;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Auth\Contracts\UserStore;
use App\Modules\Promotion\Application\PromotionService;
use App\Modules\Promotion\Contracts\PromotionReader;
use App\Modules\Promotion\Contracts\PromotionUsageWriter;
use App\Modules\Seo\Application\SeoService;
use App\Modules\Seo\Contracts\SeoStore;
use App\Modules\Smart\Application\SmartService as NativeSmartService;
use App\Modules\Smart\Contracts\SmartOperations;
use App\Modules\Dev\Application\DevService as NativeDevService;
use App\Modules\Dev\Contracts\DevOperations;
use App\Modules\Catalog\Contracts\CatalogCompletenessPort;
use PHPUnit\Framework\TestCase;
use App\Role;

final class NativeModuleContractTest extends TestCase
{
    public function testPromotionUsesReaderAndUsagePorts(): void
    {
        $reader = new class implements PromotionReader { public function findActive(string $code): ?array { return ['id' => 'coupon-1', 'rules' => '{"type":"percent","value":10}']; } };
        $usage = new class implements PromotionUsageWriter { public array $calls = []; public function record(string $couponId, string $orderId, int $discountMinor): void { $this->calls[] = [$couponId, $orderId, $discountMinor]; } };
        $service = new PromotionService($reader, $usage);
        self::assertSame(['discount_minor' => 1000, 'coupon_id' => 'coupon-1'], $service->preview('SALE', 10000));
        $service->recordUsage('coupon-1', 'order-1', 1000);
        self::assertSame([['coupon-1', 'order-1', 1000]], $usage->calls);
    }

    public function testAnalyticsUsesStorePortAndComputesFunnel(): void
    {
        $store = new class implements AnalyticsStore { public array $events = []; public function record(array $data): array { $this->events[] = $data; return $data + ['id' => 'event-1']; } public function count(string $event): int { return [AnalyticsService::VIEW => 100, AnalyticsService::ADD => 20, AnalyticsService::CHECKOUT => 10, AnalyticsService::PAYMENT => 8, AnalyticsService::ORDER => 7][$event] ?? 0; } };
        $service = new AnalyticsService($store);
        self::assertTrue($service->isAllowed(AnalyticsService::ORDER));
        self::assertSame('product.view', $service->track(AnalyticsService::VIEW)['event']);
        self::assertSame(0.7, $service->funnel()->rates['checkout_to_order']);
    }

    public function testSeoUsesStorePort(): void
    {
        $store = new class implements SeoStore { public function meta(string $type, string $id): ?array { return ['entity_type' => $type, 'entity_id' => $id]; } public function saveMeta(string $type, string $id, array $data): array { return $data + ['entity_type' => $type, 'entity_id' => $id]; } public function saveRedirect(array $data): array { return $data; } public function sitemap(string $base): array { return [['loc' => $base . '/p/demo', 'lastmod' => '2026-01-01']]; } public function health(): array { return []; } };
        $service = new SeoService($store);
        self::assertSame('product', $service->meta('product', 'p-1')['entity_type']);
        self::assertSame('http://shop/p/demo', $service->sitemapUrls('http://shop')[0]['loc']);
    }

    public function testAuthUsesUserStorePort(): void
    {
        $store = new class implements UserStore { public function findByEmail(string $email): ?array { return null; } public function findCredentialsByEmail(string $email): ?array { return null; } public function create(array $data): array { return $data + ['id' => 1]; } public function update(int $id, array $data): array { return $data + ['id' => $id]; } public function storeRefresh(array $data): void {} public function findRefresh(string $jti): ?array { return null; } public function find(int $id): ?array { return null; } public function revokeRefresh(string $jti): void {} public function revokeAllForUser(int $userId): void {} public function bumpTokenVersion(int $userId): void {} };
        $user = (new AuthService($store))->register(['name' => 'Demo', 'email' => 'demo@test.local', 'password' => 'secret']);
        self::assertSame('demo@test.local', $user['email']);
        self::assertSame('viewer', $user['role']);
    }

    public function testFinanceRoleIsAvailableThroughAuthCapabilities(): void
    {
        self::assertContains(Role::FINANCE, Role::all());
        self::assertTrue(Role::can(Role::FINANCE, 'refunds'));
        self::assertTrue(Role::can(Role::FINANCE, 'operations'));
        self::assertTrue(Role::can(Role::FINANCE, 'subscriptions'));
        self::assertSame(['finance', 'invoices', 'payments', 'refunds', 'credit_memos', 'exports', 'operations', 'subscriptions', 'ai_suggestions'], Role::capabilities(Role::FINANCE));
        $store = new class implements UserStore { public function findByEmail(string $email): ?array { return null; } public function findCredentialsByEmail(string $email): ?array { return null; } public function create(array $data): array { return $data + ['id' => 1]; } public function update(int $id, array $data): array { return $data + ['id' => $id]; } public function storeRefresh(array $data): void {} public function findRefresh(string $jti): ?array { return null; } public function find(int $id): ?array { return null; } public function revokeRefresh(string $jti): void {} public function revokeAllForUser(int $userId): void {} public function bumpTokenVersion(int $userId): void {} };
        $auth = new AuthService($store);
        self::assertContains('refunds', $auth->publicUser($auth->register(['email' => 'finance@test.local', 'password' => 'secret', 'role' => Role::FINANCE]))['capabilities']);
    }

    public function testSmartApplicationUsesOperationsPort(): void
    {
        self::assertTrue(is_a(NativeSmartService::class, SmartOperations::class, true));
    }

    public function testDevApplicationUsesOperationsPort(): void
    {
        $ops = new class implements DevOperations { public function trace(string $id): array { return ['order_id' => $id]; } public function diagnose(string $scope): array { return [['scope' => $scope]]; } public function releaseExpired(bool $dry, string $actor): array { return ['dry_run' => $dry]; } public function rescue(string $id, string $actor, bool $dry): array { return ['order_id' => $id, 'dry_run' => $dry]; } };
        $service = new NativeDevService($ops);
        self::assertSame('order-1', $service->trace('order-1')['order_id']);
        self::assertSame('all', $service->diagnose('all')[0]['scope']);
    }

    public function testCatalogCompletenessPortDefinesNativeCatalogSurface(): void
    {
        self::assertTrue(interface_exists(CatalogCompletenessPort::class));
        self::assertTrue(method_exists(CatalogCompletenessPort::class, 'addOption'));
        self::assertTrue(method_exists(CatalogCompletenessPort::class, 'addMedia'));
        self::assertTrue(method_exists(CatalogCompletenessPort::class, 'importProduct'));
    }
}
