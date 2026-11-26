<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Extension\ExtensionRegistry;
use App\Extension\VersionConstraint;
use App\Extensions\FlatRateShipping;
use PHPUnit\Framework\TestCase;

final class ExtensionRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ExtensionRegistry::reset();
    }

    public function testSemverConstraints(): void
    {
        self::assertTrue(VersionConstraint::satisfies('1.0.14', '^1.0'));
        self::assertTrue(VersionConstraint::satisfies('1.9.0', '^1.0'));
        self::assertFalse(VersionConstraint::satisfies('2.0.0', '^1.0'));
        self::assertTrue(VersionConstraint::satisfies('0.1.0', '^0.1'));
        self::assertFalse(VersionConstraint::satisfies('0.2.0', '^0.1'));
        self::assertTrue(VersionConstraint::satisfies('1.0.14', '1.0.14'));
        self::assertFalse(VersionConstraint::satisfies('1.0.15', '1.0.14'));
    }

    public function testExampleExtensionRegistersAndProvidesRates(): void
    {
        ExtensionRegistry::register(new FlatRateShipping());

        self::assertSame(['flat-rate'], ExtensionRegistry::ids());
        self::assertTrue(ExtensionRegistry::hasCapability('flat-rate', 'shipping.provider'));
        self::assertCount(1, ExtensionRegistry::forCapability('shipping.provider'));
        self::assertSame('Provider is registered', ExtensionRegistry::providerHealth('shipping.provider')['flat']['message']);
        $provider = ExtensionRegistry::shippings()['flat'];
        self::assertSame(30000, $provider->getRates(['subtotal_minor' => 100000], [])[0]['amount_minor']);
        self::assertSame(0, $provider->getRates(['subtotal_minor' => 500000], [])[0]['amount_minor']);
    }

    public function testRegistrationRejectsProviderCollision(): void
    {
        ExtensionRegistry::register(new FlatRateShipping());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Extension already registered: flat-rate');
        ExtensionRegistry::register(new FlatRateShipping());
    }

    public function testCaretZeroConstraintDoesNotCrossMinorBoundary(): void
    {
        self::assertTrue(VersionConstraint::satisfies('0.0.9', '^0.0.1'));
        self::assertFalse(VersionConstraint::satisfies('0.1.0', '^0.0.1'));
    }
}
