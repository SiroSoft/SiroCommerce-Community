<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProviderCertification;
use PHPUnit\Framework\TestCase;

final class ProviderCertificationTest extends TestCase
{
    public function testMatrixOwnsOnlyFourProviderDomains(): void
    {
        self::assertSame(['payment', 'tax', 'shipping', 'search'], array_keys(ProviderCertification::matrix()));
        self::assertNull(ProviderCertification::matrix()['search']['webhook_url']);
    }

    public function testGenericFixturesDoNotPass(): void
    {
        self::assertFalse(ProviderCertification::validHealthFixture(['status' => 'healthy'], 'stripe'));
        self::assertFalse(ProviderCertification::validWebhookFixture(['accepted' => true], 'stripe'));
        self::assertTrue(ProviderCertification::validHealthFixture(['provider' => 'stripe', 'status' => 'healthy', 'authenticated' => true, 'protocol' => 'health'], 'stripe'));
        self::assertTrue(ProviderCertification::validWebhookFixture(['provider' => 'stripe', 'accepted' => true, 'authenticated' => true, 'protocol' => 'webhook', 'event_id' => 'fixture'], 'stripe', 'fixture'));
        self::assertFalse(ProviderCertification::validWebhookFixture(['provider' => 'stripe', 'accepted' => true, 'authenticated' => true, 'protocol' => 'webhook', 'event_id' => 'other'], 'stripe', 'fixture'));
    }

    public function testDisabledOrCredentiallessCertificationIsNotProven(): void
    {
        $saved = [];
        foreach (['PROVIDER_LIVE_SMOKE', 'STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY'] as $name) { $saved[$name] = getenv($name); putenv($name); }
        $result = ProviderCertification::run();
        self::assertSame('BLOCKED_EXTERNAL', $result['status']);
        self::assertSame('BLOCKED_EXTERNAL', $result['results']['payment']['health']['status']);
        foreach ($saved as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}
