<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Headless\Domain\HeadlessException;
use App\Modules\Headless\Application\HeadlessQueryService;
use App\Modules\Headless\Contracts\OrderOwnerReader;
use App\Modules\Catalog\Application\CatalogService;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Cms\Application\CmsService;
use App\Modules\Cms\Contracts\CmsStore;
use App\Modules\Order\Application\OrderQueryService;
use App\Modules\Order\Contracts\OrderReader;
use App\Modules\Headless\Infrastructure\GraphQlParser;
use PHPUnit\Framework\TestCase;

final class HeadlessGraphQlTest extends TestCase
{
    public function testParserBuildsNestedReadSelection(): void
    {
        $query = (new GraphQlParser())->parse('{ product(slug: "shoe") { id options { name values { value } } } }');
        self::assertSame('product', $query['selection'][0]['name']);
        self::assertSame('shoe', $query['selection'][0]['args']['slug']);
    }

    public function testMutationsAreRejectedWithStableCode(): void
    {
        $this->expectException(HeadlessException::class);
        $this->expectExceptionMessage('Mutations are not supported');
        (new GraphQlParser())->parse('mutation { createProduct { id } }');
    }

    public function testDeepQueriesAreRejected(): void
    {
        $query = '{ a { b { c { d { e { f { g { h { i } } } } } } } } }';
        $this->expectException(HeadlessException::class);
        $this->expectExceptionMessage('depth');
        (new GraphQlParser())->parse($query);
    }

    public function testDepthEightIsAccepted(): void
    {
        $query = '{ a { b { c { d { e { f { g { h } } } } } } } }';
        $parsed = (new GraphQlParser())->parse($query);

        self::assertSame('a', $parsed['selection'][0]['name']);
    }

    public function testAliasesAndFragmentsCannotBypassDepthLimit(): void
    {
        $this->expectException(HeadlessException::class);
        $this->expectExceptionMessage('depth');
        (new GraphQlParser())->parse(
            '{ root: a { ...Deep } } fragment Deep on Thing { b { c { d { e { f { g { h { i { j } } } } } } } } }'
        );
    }

    public function testVariablesAliasesAndFragmentsAreExpanded(): void
    {
        $query = (new GraphQlParser())->parse(
            'query Product($slug: String!) { item: product(slug: $slug) { ...ProductFields } } fragment ProductFields on Product { id name }',
            ['slug' => 'shoe']
        );

        self::assertSame('product', $query['selection'][0]['name']);
        self::assertSame('item', $query['selection'][0]['alias']);
        self::assertSame('shoe', $query['selection'][0]['args']['slug']);
        self::assertSame('id', $query['selection'][0]['children'][0]['name']);
    }

    public function testMissingVariablesFailClosed(): void
    {
        $this->expectException(HeadlessException::class);
        (new GraphQlParser())->parse('{ product(slug: $slug) { id } }');
    }

    public function testInvalidCharactersAreNotSilentlyIgnored(): void
    {
        $this->expectExceptionMessage('Invalid query syntax');
        (new GraphQlParser())->parse('{ product(slug: "shoe") { id } # ignored? }');
    }

    public function testDepthEightLeafDoesNotCountAPhantomNinthLevel(): void
    {
        $query = '{ a { b { c { d { e { f { g { h } } } } } } } }';
        $parsed = (new GraphQlParser())->parse($query);

        self::assertCount(1, $parsed['selection']);
    }

    public function testMissingOrderIsDeniedWithoutAnExistenceResponse(): void
    {
        $orders = $this->createMock(OrderReader::class);
        $orders->expects(self::once())->method('find')->with('missing')->willReturn(null);
        $orders->expects(self::never())->method('guestTokenIsValid');

        $service = new HeadlessQueryService(
            new CatalogService($this->createMock(CatalogReader::class)),
            new CmsService($this->createMock(CmsStore::class)),
            new OrderQueryService($orders),
            new GraphQlParser(),
            $this->createMock(OrderOwnerReader::class),
        );

        $this->expectException(HeadlessException::class);
        $this->expectExceptionMessage('not authorized');
        $service->execute('{ order(id: "missing", token: "bad") { id } }', null);
    }

    public function testDigitalAssetOriginUrlCannotBeSelectedByHeadlessClients(): void
    {
        $slug = 'download-asset-origin-' . bin2hex(random_bytes(4));
        $catalog = $this->createMock(CatalogReader::class);
        $catalog->expects(self::once())->method('bySlug')->with($slug)->willReturn([
            'id' => 'product-1', 'slug' => 'download', 'name' => 'Download',
            'digital_assets' => [['id' => 'asset-1', 'name' => 'file.zip', 'url' => 'https://origin.example/file.zip']],
        ]);
        $service = new HeadlessQueryService(
            new CatalogService($catalog),
            new CmsService($this->createMock(CmsStore::class)),
            new OrderQueryService($this->createMock(OrderReader::class)),
            new GraphQlParser(),
            $this->createMock(OrderOwnerReader::class),
        );

        $this->expectException(HeadlessException::class);
        $this->expectExceptionMessage('Unknown digitalAssets field: url');
        $service->execute('{ product(slug: "' . $slug . '") { digitalAssets { url } } }', null);
    }

}
