<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Request;
use PHPUnit\Framework\TestCase;

final class CatalogTaxonomyTest extends TestCase
{
    public function testCatalogScopeRejectsMalformedHeaders(): void
    {
        $this->expectException(CommerceScopeException::class);
        CommerceScope::fromRequest(new Request('GET', '/api/v2/catalog/tags', [], ['X-Store-Id' => 'bad space']));
    }

    public function testCatalogScopeAcceptsTheStableStorefrontScope(): void
    {
        $scope = CommerceScope::fromRequest(new Request('GET', '/api/v2/catalog/product-types', [], [
            'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND',
        ]));

        self::assertSame('default:default:en:VND', $scope->key());
    }
}
