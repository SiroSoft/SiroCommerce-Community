<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Catalog\Application\ProductTypeService;
use App\Modules\Catalog\Domain\ProductTypeDefinition;
use App\Modules\Catalog\Contracts\CatalogAdminPort;
use App\Modules\Catalog\Application\DigitalDeliveryService;
use App\Modules\Catalog\Contracts\DigitalDeliveryStore;
use App\Modules\Catalog\Contracts\DigitalDeliveryTransport;
use App\Modules\Pricing\Contracts\PriceResolver;
use PHPUnit\Framework\TestCase;

final class ProductTypeRuntimeTest extends TestCase
{
    public function testComponentExpansionUsesCanonicalIdentifier(): void
    {
        $normalized = ProductTypeDefinition::normalizeComponents([['variant_id' => 'legacy-v', 'qty' => 2]]);

        self::assertSame('legacy-v', $normalized[0]['component_variant_id']);
        self::assertArrayNotHasKey('variant_id', $normalized[0]);
    }

    public function testConflictingComponentIdentifiersAreRejectedAsStale(): void
    {
        $this->expectExceptionMessage('Stale bundle component identifiers');
        ProductTypeDefinition::normalizeComponents([['component_variant_id' => 'current-v', 'variant_id' => 'stale-v', 'qty' => 1]]);
    }

    public function testInvalidComponentSnapshotIsRejected(): void
    {
        $this->expectExceptionMessage('Missing required string: component_variant_id');
        ProductTypeDefinition::normalizeComponents([['qty' => 1]]);
    }

    public function testGroupedProductTypeDoesNotBecomeBundleExpansion(): void
    {
        $definition = ProductTypeDefinition::fromLine(['product_type' => 'grouped']);

        self::assertSame('grouped', $definition->type);
        self::assertSame('component_sum', $definition->bundlePricingMode);
    }

    public function testBundlePriceIsExpandedIntoComponentSnapshotLines(): void
    {
        $catalog = new class implements CatalogAdminPort {
            public function activePage(int $page, int $perPage): array { return ['data' => [], 'meta' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]]; }
            public function bySlug(string $slug): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $id, array $data): ?array { return null; }
            public function delete(string $id): bool { return false; }
            public function createVariant(array $data): array { return $data; }
            public function addComponent(array $data): array { return $data; }
            public function addDigitalAsset(array $data): array { return $data; }
            public function components(string $productId): array { return [['component_variant_id' => 'v-red', 'qty' => 2], ['component_variant_id' => 'v-blue', 'qty' => 1]]; }
            public function digitalAsset(string $assetId): ?array { return null; }
        };
        $prices = new class implements PriceResolver {
            public function resolve(string $variantId, ?string $customerGroupId = null): ?array { return ['unit_price_minor' => $variantId === 'v-red' ? 100 : 250, 'currency' => 'VND', 'source' => 'variant']; }
        };
        $quote = (new ProductTypeService($catalog, $prices))->quoteBundle('bundle-1', 3);
        self::assertSame(450, $quote['unit_price_minor']);
        self::assertSame(1350, $quote['total_minor']);
        self::assertSame(6, $quote['components'][0]['qty']);
    }

    public function testFixedBundlePriceIsExplicitAndDoesNotUseComponentSum(): void
    {
        $catalog = new class implements CatalogAdminPort {
            public function activePage(int $page, int $perPage): array { return ['data' => [], 'meta' => []]; }
            public function bySlug(string $slug): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $id, array $data): ?array { return null; }
            public function delete(string $id): bool { return false; }
            public function createVariant(array $data): array { return $data; }
            public function addComponent(array $data): array { return $data; }
            public function addDigitalAsset(array $data): array { return $data; }
            public function components(string $productId): array { return [['component_variant_id' => 'v', 'qty' => 1, 'bundle_pricing_mode' => 'fixed', 'bundle_price_minor' => 900]]; }
            public function digitalAsset(string $assetId): ?array { return null; }
        };
        $prices = new class implements PriceResolver {
            public function resolve(string $variantId, ?string $customerGroupId = null): ?array { return null; }
        };
        $quote = (new ProductTypeService($catalog, $prices))->quoteBundle('bundle-fixed', 2);
        self::assertSame('fixed', $quote['pricing_mode']);
        self::assertSame(900, $quote['unit_price_minor']);
        self::assertSame(1800, $quote['total_minor']);
    }

    public function testSignedUrlRejectsExpiredDownloadToken(): void
    {
        $catalog = new class implements CatalogAdminPort {
            public function activePage(int $page, int $perPage): array { return ['data' => [], 'meta' => []]; }
            public function bySlug(string $slug): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $id, array $data): ?array { return null; }
            public function delete(string $id): bool { return false; }
            public function createVariant(array $data): array { return $data; }
            public function addComponent(array $data): array { return $data; }
            public function addDigitalAsset(array $data): array { return $data; }
            public function components(string $productId): array { return []; }
            public function digitalAsset(string $assetId): ?array { return ['id' => $assetId, 'malware_scan_status' => 'clean', 'malware_scanned_at' => date('Y-m-d H:i:s')]; }
        };
        $store = new class implements DigitalDeliveryStore {
            public function issue(array $data): array { return $data + ['id' => 'download-1']; }
            public function byTokenHash(string $hash): ?array { return ['id' => 'download-1', 'asset_id' => 'asset-1', 'download_count' => 0, 'download_limit' => 1, 'expires_at' => date('Y-m-d H:i:s', time() - 1)]; }
            public function increment(string $id): void {}
            public function consume(string $id, ?int $limit): bool { return true; }
        };
        $transport = new class implements \App\Modules\Catalog\Contracts\SignedDigitalDeliveryTransport {
            public function read(array $asset): array { return []; }
            public function signed(array $asset, int $ttlSeconds): array { return ['url' => 'https://cdn.invalid/file', 'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds)]; }
            public function readRange(array $asset, int $start, int $end): array { return []; }
        };
        $service = new DigitalDeliveryService($catalog, $store, $transport);
        $this->expectExceptionMessage('Download token expired');
        $service->signedUrl(str_repeat('a', 64));
    }

    public function testSignedUrlRejectsExhaustedDownloadToken(): void
    {
        $catalog = new class implements CatalogAdminPort {
            public function activePage(int $page, int $perPage): array { return ['data' => [], 'meta' => []]; }
            public function bySlug(string $slug): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $id, array $data): ?array { return null; }
            public function delete(string $id): bool { return false; }
            public function createVariant(array $data): array { return $data; }
            public function addComponent(array $data): array { return $data; }
            public function addDigitalAsset(array $data): array { return $data; }
            public function components(string $productId): array { return []; }
            public function digitalAsset(string $assetId): ?array { return ['id' => $assetId, 'malware_scan_status' => 'clean', 'malware_scanned_at' => date('Y-m-d H:i:s')]; }
        };
        $store = new class implements DigitalDeliveryStore {
            public function issue(array $data): array { return $data + ['id' => 'download-1']; }
            public function byTokenHash(string $hash): ?array { return ['id' => 'download-1', 'asset_id' => 'asset-1', 'download_count' => 1, 'download_limit' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 60)]; }
            public function increment(string $id): void {}
            public function consume(string $id, ?int $limit): bool { return true; }
        };
        $service = new DigitalDeliveryService($catalog, $store);
        $this->expectExceptionMessage('Download limit reached');
        $service->signedUrl(str_repeat('a', 64));
    }

    public function testDigitalDeliveryNeverReturnsOriginUrlWhenTransportIsMissing(): void
    {
        $catalog = new class implements CatalogAdminPort {
            public function activePage(int $page, int $perPage): array { return ['data' => [], 'meta' => []]; }
            public function bySlug(string $slug): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $id, array $data): ?array { return null; }
            public function delete(string $id): bool { return false; }
            public function createVariant(array $data): array { return $data; }
            public function addComponent(array $data): array { return $data; }
            public function addDigitalAsset(array $data): array { return $data; }
            public function components(string $productId): array { return []; }
            public function digitalAsset(string $assetId): ?array { return ['id' => $assetId, 'name' => 'file.zip', 'url' => 'https://origin.invalid/file.zip', 'malware_scan_status' => 'clean', 'malware_scanned_at' => date('Y-m-d H:i:s')]; }
        };
        $store = new class implements DigitalDeliveryStore {
            public function issue(array $data): array { return $data + ['id' => 'download-1']; }
            public function byTokenHash(string $hash): ?array { return ['id' => 'download-1', 'asset_id' => 'asset-1', 'order_id' => 'order-1', 'download_count' => 0, 'download_limit' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 60)]; }
            public function increment(string $id): void {}
            public function consume(string $id, ?int $limit): bool { return true; }
        };
        $service = new DigitalDeliveryService($catalog, $store);
        $issued = $service->issue('asset-1', 'order-1', 1, 60);
        self::assertSame(1, $issued['id'] !== '' ? 1 : 0);
        try { $service->resolve($issued['token']); self::fail('Expected fail-closed delivery'); }
        catch (\RuntimeException $e) { self::assertSame('Protected digital delivery transport is not configured', $e->getMessage()); }
    }

    public function testDigitalDeliveryConsumesTokenOnlyAfterTransportRead(): void
    {
        $catalog = new class implements CatalogAdminPort {
            public function activePage(int $page, int $perPage): array { return ['data' => [], 'meta' => []]; }
            public function bySlug(string $slug): ?array { return null; }
            public function create(array $data): array { return $data; }
            public function update(string $id, array $data): ?array { return null; }
            public function delete(string $id): bool { return false; }
            public function createVariant(array $data): array { return $data; }
            public function addComponent(array $data): array { return $data; }
            public function addDigitalAsset(array $data): array { return $data; }
            public function components(string $productId): array { return []; }
            public function digitalAsset(string $assetId): ?array { return ['id' => $assetId, 'name' => 'file.txt', 'url' => 'file.txt', 'malware_scan_status' => 'clean', 'malware_scanned_at' => date('Y-m-d H:i:s')]; }
        };
        $consumed = 0;
        $store = new class($consumed) implements DigitalDeliveryStore {
            public function __construct(private int &$consumed) {}
            public function issue(array $data): array { return $data + ['id' => 'download-1']; }
            public function byTokenHash(string $hash): ?array { return ['id' => 'download-1', 'asset_id' => 'asset-1', 'order_id' => 'order-1', 'download_count' => 0, 'download_limit' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 60)]; }
            public function increment(string $id): void {}
            public function consume(string $id, ?int $limit): bool { $this->consumed++; return true; }
        };
        $transport = new class implements DigitalDeliveryTransport {
            public function read(array $asset): array { return ['content' => 'safe bytes', 'mime' => 'text/plain', 'filename' => 'file.txt']; }
        };
        $service = new DigitalDeliveryService($catalog, $store, $transport);
        $issued = $service->issue('asset-1', 'order-1', 1, 60);
        $delivery = $service->resolve($issued['token']);
        self::assertSame('safe bytes', $delivery['content']);
        self::assertSame(1, $consumed);
    }
}
