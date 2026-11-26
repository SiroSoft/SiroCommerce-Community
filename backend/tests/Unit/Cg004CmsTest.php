<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cms\Application\CmsService;
use App\Modules\Cms\Contracts\CmsStore;
use App\Support\CommerceScope;
use PHPUnit\Framework\TestCase;

final class Cg004CmsTest extends TestCase
{
    private function scope(): CommerceScope
    {
        return new CommerceScope('store', 'channel', 'en', 'VND');
    }

    public function testAutosavePassesTypedPayloadAndIdempotencyKey(): void
    {
        $store = $this->createMock(CmsStore::class);
        $store->expects(self::once())->method('autosave')->with('page-1', self::callback(static fn (array $data): bool => ($data['status'] ?? null) === 'scheduled'), self::isInstanceOf(CommerceScope::class), 'actor-1', 'save-1', 3)->willReturn(['id' => 'page-1', 'version' => 4]);
        $cms = new CmsService($store);

        self::assertSame(['id' => 'page-1', 'version' => 4], $cms->autosave('page-1', ['status' => 'scheduled', 'scheduled_at' => '2030-01-01 00:00:00'], $this->scope(), 'save-1', 3, ['id' => 'actor-1', 'role' => 'owner']));
    }

    public function testMediaRequiresMetadataAndHttpsReference(): void
    {
        $store = $this->createMock(CmsStore::class);
        $cms = new CmsService($store);

        $this->expectException(\InvalidArgumentException::class);
        $cms->create(['slug' => 'hero', 'title' => 'Hero', 'media_json' => [['id' => 'asset-1', 'url' => 'https://cdn.example/hero.png', 'mime' => 'image/png']]]);
    }
}
