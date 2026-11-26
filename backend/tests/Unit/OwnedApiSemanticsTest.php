<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Catalog\Application\DigitalDeliveryService;
use App\Modules\Catalog\Contracts\CatalogAdminPort;
use App\Modules\Catalog\Contracts\DigitalDeliveryStore;
use PHPUnit\Framework\TestCase;

final class OwnedApiSemanticsTest extends TestCase
{
    public function testDigitalDeliveryStoresOnlyTokenDigestAndRejectsMalformedTokens(): void
    {
        $stored = [];
        $catalog = $this->createMock(CatalogAdminPort::class);
         $catalog->method('digitalAsset')->willReturnCallback(static fn (string $id): ?array => $id === 'asset-1' ? ['id' => $id, 'download_limit' => 1, 'malware_scan_status' => 'clean', 'malware_scanned_at' => date('Y-m-d H:i:s')] : null);
        $downloads = new class($stored) implements DigitalDeliveryStore {
            public function __construct(private array &$stored) {}
            public function issue(array $data): array { $this->stored = ['id' => 'download-1'] + $data; return $this->stored; }
            public function byTokenHash(string $hash): ?array { return $hash === ($this->stored['token_hash'] ?? '') ? $this->stored : null; }
            public function increment(string $id): void {}
            public function consume(string $id, ?int $limit): bool { return true; }
        };
        $service = new DigitalDeliveryService($catalog, $downloads);
        $issued = $service->issue('asset-1', 'order-1');

        self::assertArrayNotHasKey('token', $stored);
        self::assertSame(hash('sha256', $issued['token']), $stored['token_hash']);
        $this->expectException(\InvalidArgumentException::class);
        $service->resolve('not-a-token');
    }

}
