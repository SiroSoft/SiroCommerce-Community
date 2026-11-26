<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use PHPUnit\Framework\TestCase;

final class ExtensionScopeTest extends TestCase
{
    public function testColumnsAndScopeMappingAreCanonical(): void
    {
        self::assertSame(['store_id' => 'store_id', 'channel_id' => 'channel_id'], ExtensionScope::columns());
        self::assertSame(
            ['store_id' => 'store-a', 'channel_id' => 'web'],
            ExtensionScope::fromScope($this->scope('store-a', 'web'))
        );
    }

    public function testEmptyScopeIsRejected(): void
    {
        foreach ([[], ['store_id' => 'store-a'], ['channel_id' => 'web'], ['store_id' => '', 'channel_id' => 'web'], ['store_id' => 'store-a', 'channel_id' => '  ']] as $scope) {
            try {
                ExtensionScope::where($scope);
                self::fail('Empty scope must be rejected: ' . (string) json_encode($scope));
            } catch (CommerceScopeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEmptyCommerceScopeIsRejected(): void
    {
        $this->expectException(CommerceScopeException::class);
        ExtensionScope::fromScope($this->scope('', 'web'));
    }

    public function testWhereSqlAndParamsAreDeterministic(): void
    {
        $scope = ExtensionScope::fromScope($this->scope('store-a', 'web'));
        self::assertSame(
            ['store_id = ? AND channel_id = ?', ['store-a', 'web']],
            ExtensionScope::where($scope)
        );
    }

    public function testMergeInjectsScopeWhenMissing(): void
    {
        $data = ExtensionScope::merge(['name' => 'rule'], $this->scope('store-a', 'web'));
        self::assertSame(['name' => 'rule', 'store_id' => 'store-a', 'channel_id' => 'web'], $data);
    }

    public function testMergeAcceptsMatchingScope(): void
    {
        $data = ExtensionScope::merge(
            ['name' => 'rule', 'store_id' => 'store-a', 'channel_id' => 'web'],
            $this->scope('store-a', 'web')
        );
        self::assertSame(['name' => 'rule', 'store_id' => 'store-a', 'channel_id' => 'web'], $data);
    }

    public function testMergeRejectsMismatchedScope(): void
    {
        $this->expectException(CommerceScopeException::class);
        ExtensionScope::merge(['name' => 'rule', 'store_id' => 'store-b'], $this->scope('store-a', 'web'));
    }

    public function testAssertScopedAcceptsOwnedRow(): void
    {
        ExtensionScope::assertScoped(['id' => 'r1', 'store_id' => 'store-a', 'channel_id' => 'web'], $this->scope('store-a', 'web'));
        self::addToAssertionCount(1);
    }

    public function testAssertScopedRejectsCrossStoreRow(): void
    {
        $this->expectException(CommerceScopeException::class);
        ExtensionScope::assertScoped(['id' => 'r1', 'store_id' => 'store-b', 'channel_id' => 'web'], $this->scope('store-a', 'web'));
    }

    public function testAssertScopedRejectsMissingOrEmptyScope(): void
    {
        foreach ([[], ['store_id' => 'store-a'], ['store_id' => 'store-a', 'channel_id' => ''], ['store_id' => 'store-a', 'channel_id' => null]] as $row) {
            try {
                ExtensionScope::assertScoped($row, $this->scope('store-a', 'web'));
                self::fail('Unscoped row must be rejected: ' . (string) json_encode($row));
            } catch (CommerceScopeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testIsGlobalRequiresExplicitBooleanTrue(): void
    {
        self::assertTrue(ExtensionScope::isGlobal(['global' => true]));
        self::assertFalse(ExtensionScope::isGlobal([]));
        self::assertFalse(ExtensionScope::isGlobal(['global' => false]));
        self::assertFalse(ExtensionScope::isGlobal(['global' => 'true']));
        self::assertFalse(ExtensionScope::isGlobal(['global' => 1]));
    }

    private function scope(string $storeId, string $channelId): CommerceScope
    {
        return new CommerceScope($storeId, $channelId, 'en', 'VND');
    }
}
