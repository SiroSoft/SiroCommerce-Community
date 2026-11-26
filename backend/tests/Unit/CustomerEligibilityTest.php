<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Catalog\Domain\CustomerEligibility;
use PHPUnit\Framework\TestCase;

final class CustomerEligibilityTest extends TestCase
{
    public function testAllConfiguredRulesMustPass(): void
    {
        $rules = ['customer_groups' => ['vip'], 'requires_verified_email' => true, 'minimum_order_count' => 2, 'countries' => ['vn']];
        self::assertTrue(CustomerEligibility::allows($rules, ['group' => 'vip', 'email_verified' => true, 'order_count' => 3, 'country' => 'VN']));
        self::assertFalse(CustomerEligibility::allows($rules, ['group' => 'vip', 'email_verified' => false, 'order_count' => 3, 'country' => 'VN']));
    }
}
