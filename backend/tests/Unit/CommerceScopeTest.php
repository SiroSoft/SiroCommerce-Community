<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Request;
use Tests\TestCase;

final class CommerceScopeTest extends TestCase
{
    public function testScopeRequiresAllExplicitDimensions(): void
    {
        $this->expectException(CommerceScopeException::class);
        CommerceScope::fromRequest(new Request('GET', '/api/pages/home'));
    }

    public function testConflictingAndUnsupportedDimensionsAreRejected(): void
    {
        $this->expectExceptionMessage('Ambiguous commerce scope');
        CommerceScope::fromRequest(new Request('GET', '/api/pages/home', ['store_id' => 'store-a'], ['X-Store-Id' => 'store-b', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND']));
    }

    public function testScopeIsCanonicalAndStable(): void
    {
        $scope = CommerceScope::fromRequest(new Request('GET', '/api/pages/home', [], ['X-Store-Id' => 'store-a', 'X-Channel-Id' => 'web', 'X-Locale' => 'VI', 'X-Currency' => 'usd']));
        self::assertSame('store-a:web:vi:USD', $scope->key());
        self::assertSame(['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'vi', 'currency' => 'USD'], $scope->metadata());
    }
}
