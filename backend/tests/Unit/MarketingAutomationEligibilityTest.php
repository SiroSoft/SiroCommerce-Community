<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\MarketingAutomation\Domain\AutomationEligibility;
use PHPUnit\Framework\TestCase;

final class MarketingAutomationEligibilityTest extends TestCase
{
    private const NOW = '2026-09-29 12:00:00';

    public function testAnonymousUsersCannotBeRecovered(): void
    {
        $result = AutomationEligibility::abandonedCart(['state' => 'open'], ['marketing_email' => 1], self::NOW);
        self::assertFalse($result['eligible']);
        self::assertContains('identity_required', $result['reasons']);
    }

    public function testConsentAndFrequencyAreRequired(): void
    {
        $cart = ['customer_id' => 'customer-1', 'state' => 'open', 'expires_at' => '2026-10-01 00:00:00'];
        self::assertFalse(AutomationEligibility::abandonedCart($cart, ['marketing_email' => 0], self::NOW)['eligible']);
        $capped = AutomationEligibility::abandonedCart($cart, ['marketing_email' => 1], self::NOW, '2026-09-28 12:00:00');
        self::assertContains('frequency_cap', $capped['reasons']);
    }

    public function testExpiryAndPurchaseSuppressionAreFailClosed(): void
    {
        $cart = ['customer_id' => 'customer-1', 'state' => 'open', 'expires_at' => '2026-09-29 11:59:59'];
        $expired = AutomationEligibility::abandonedCart($cart, ['marketing_email' => 1], self::NOW);
        self::assertContains('cart_expired', $expired['reasons']);
        $cart['expires_at'] = '2026-10-01 00:00:00';
        $purchased = AutomationEligibility::abandonedCart($cart, ['marketing_email' => 1], self::NOW, null, true);
        self::assertContains('purchase_suppressed', $purchased['reasons']);
    }

    public function testCampaignKeyIsDeterministicForDuplicateScheduling(): void
    {
        self::assertSame('abandoned_cart:cart-1', AutomationEligibility::campaignKey('abandoned_cart', 'cart-1'));
        self::assertSame(AutomationEligibility::campaignKey('wishlist', 'customer-1', 'product-1'), AutomationEligibility::campaignKey('wishlist', 'customer-1', 'product-1'));
    }
}
