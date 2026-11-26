<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cms\Application\CmsService;
use App\Modules\Cms\Contracts\CmsStore;
use App\Modules\Seo\Application\SeoService;
use App\Modules\Seo\Contracts\SeoStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\OwnedContentRoutes;

final class ContentReadinessTest extends TestCase
{
    public function testOwnedContentOperationsAreExact(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        preg_match_all('~\$app->router->(get|post|put|patch|delete)\(\'([^\']+)\'~i', $source, $matches, PREG_SET_ORDER);
        $routes = array_map(static fn (array $match): string => strtoupper($match[1]) . ' ' . $match[2], $matches);
        $owned = array_values(array_filter($routes, static fn (string $route): bool => in_array($route, OwnedContentRoutes::all(), true)));
        sort($owned);
        $expected = OwnedContentRoutes::all();
        sort($expected);
        self::assertSame($expected, $owned);
    }

    public function testCmsAllowsScheduledPagesAndRejectsUnsafeMediaReferences(): void
    {
        $store = $this->createMock(CmsStore::class);
        $store->expects(self::once())->method('create')->with(self::callback(
            static fn (array $data): bool => ($data['status'] ?? null) === 'scheduled'
        ))->willReturn(['id' => 'page-1', 'status' => 'scheduled']);
        $cms = new CmsService($store);

        self::assertSame(['id' => 'page-1', 'status' => 'scheduled'], $cms->create([
            'slug' => 'launch', 'title' => 'Launch', 'status' => 'scheduled', 'scheduled_at' => '2030-01-01 00:00:00',
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $cms->create(['slug' => 'unsafe', 'title' => 'Unsafe', 'media_json' => [['id' => '../secret']]]);
    }

    public function testSeoRedirectsRemainLocalAndUseSupportedStatusCodes(): void
    {
        $store = $this->createMock(SeoStore::class);
        $store->expects(self::once())->method('saveRedirect')->with([
            'from_path' => '/old', 'to_path' => '/new?ref=1', 'code' => 308,
        ])->willReturn(['from_path' => '/old', 'to_path' => '/new?ref=1', 'code' => 308]);
        $seo = new SeoService($store);

        self::assertSame(308, $seo->saveRedirect(['from_path' => '/old', 'to_path' => '/new?ref=1', 'code' => 308])['code']);
        $this->expectException(\InvalidArgumentException::class);
        $seo->saveRedirect(['from_path' => '/old', 'to_path' => 'https://evil.example']);
    }
}
