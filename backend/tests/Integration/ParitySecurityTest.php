<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\CommerceScope;
use App\Support\ScopeAuthorizer;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;

final class ParitySecurityTest extends TestCase
{
    public function testSellerAndSiteClaimsCannotCrossScope(): void
    {
        $scope = CommerceScope::fromRequest(new Request('GET', '/', [], [
            'X-Tenant-Id' => 'tenant-a', 'X-Seller-Id' => 'seller-a', 'X-Site-Id' => 'site-a',
            'X-Store-Id' => 'store-a', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'USD',
        ]));
        ScopeAuthorizer::assertAllowed($scope, ['tenant_ids' => ['tenant-a'], 'seller_ids' => ['seller-a'], 'site_ids' => ['site-a']]);
        $this->expectException(\Throwable::class);
        ScopeAuthorizer::assertAllowed($scope, ['tenant_ids' => ['tenant-a'], 'seller_ids' => ['seller-b']]);
    }
}
