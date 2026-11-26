<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Extensions\FlatRateShipping;
use App\Models\Extension;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Domain\ExtensionManifest;
use Tests\TestCase;

final class ExtensionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Extension\ExtensionRegistry::reset();
    }

    public function testDiscoveryAndLifecycleStateArePersisted(): void
    {
        $service = new ExtensionLifecycleService([FlatRateShipping::class]);
        $service->discover();
        self::assertSame('discovered', Extension::find('flat-rate')?->state);

        $service->enable('flat-rate');
        self::assertSame('enabled', Extension::find('flat-rate')?->state);
        self::assertNotNull(\App\Extension\ExtensionRegistry::extension('flat-rate'));

        $service->disable('flat-rate');
        self::assertSame('disabled', Extension::find('flat-rate')?->state);
        self::assertNull(\App\Extension\ExtensionRegistry::extension('flat-rate'));
    }

    public function testValidationRejectsIncompatibleManifest(): void
    {
        $service = new ExtensionLifecycleService();
        $manifest = new ExtensionManifest('vendor/test', '1.0.0', ['commerce' => '^9.0'], [], []);
        self::assertNotEmpty($service->validate($manifest));
        $this->expectException(\InvalidArgumentException::class);
        $service->install($manifest);
    }

    public function testHealthReportsAvailableExtension(): void
    {
        $service = new ExtensionLifecycleService([FlatRateShipping::class]);
        $service->discover();
        self::assertTrue($service->health('flat-rate')['ok']);
    }

    public function testExistingRegisteredExtensionIsPreservedOnEnable(): void
    {
        \App\Extension\ExtensionRegistry::register(new FlatRateShipping());
        $service = new ExtensionLifecycleService([FlatRateShipping::class]);
        $service->discover();
        $service->enable('flat-rate');
        self::assertSame(['flat-rate'], \App\Extension\ExtensionRegistry::ids());
    }

    public function testManifestMetadataIsPersistedAndRestored(): void
    {
        $service = new ExtensionLifecycleService([FlatRateShipping::class]);
        $service->discover();
        $data = json_decode((string) Extension::find('flat-rate')?->manifest_json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['shipping.provider'], $data['capabilities']);
        self::assertSame(['shipping.read', 'shipping.write'], $data['permissions']);
        self::assertSame([], $data['migrations']);
        self::assertSame([], $data['routes']);
    }

    public function testManifestRejectsUnsupportedRuntimeAndUnsafeMetadata(): void
    {
        $service = new ExtensionLifecycleService();
        $manifest = new ExtensionManifest('vendor/test', '1.0.0', ['php' => '*'], ['bad capability'], [], ['../secret'], ['/admin']);
        $errors = $service->validate($manifest);
        self::assertCount(4, $errors);
    }
}
