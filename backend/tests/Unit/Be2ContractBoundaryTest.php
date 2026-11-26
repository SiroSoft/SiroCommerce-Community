<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Customer\Domain\CustomerNotificationEvent;
use App\Modules\Payment\Domain\PaymentStatusBoundary;
use App\Modules\Shipping\Domain\DeliveryMethod;
use App\Modules\Shared\Domain\ExternalCallStatus;
use App\Support\CommerceScope;
use App\Support\ScopeAuthorizer;
use Siro\Core\Request;
use Tests\TestCase;

final class Be2ContractBoundaryTest extends TestCase
{
    public function testTenantScopeAndAuthorizationAreBounded(): void
    {
        $scope = CommerceScope::fromRequest(new Request('GET', '/', [], ['X-Tenant-Id' => 'tenant-a', 'X-Store-Id' => 'store-a', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'USD']));
        ScopeAuthorizer::assertAllowed($scope, ['tenant_ids' => ['tenant-a'], 'store_ids' => ['store-a'], 'channel_ids' => ['web']]);
        $this->expectException(\Throwable::class);
        ScopeAuthorizer::assertAllowed($scope, ['tenant_ids' => ['tenant-b']]);
    }

    public function testMissingScopeClaimIsNotTreatedAsUnrestricted(): void
    {
        $scope = CommerceScope::fromRequest(new Request('GET', '/', [], [
            'X-Tenant-Id' => 'tenant-a', 'X-Store-Id' => 'store-a', 'X-Channel-Id' => 'web',
            'X-Locale' => 'en', 'X-Currency' => 'USD',
        ]));

        $this->expectException(\Throwable::class);
        ScopeAuthorizer::assertAllowed($scope, []);
    }

    public function testDeliveryRuleAndCostAreDeterministic(): void
    {
        $method = new DeliveryMethod('STANDARD', 'Standard', 500, 'USD', ['countries' => ['US'], 'free_over_minor' => 5000]);
        self::assertTrue($method->available(['country' => 'us', 'subtotal_minor' => 1000]));
        self::assertSame(0, $method->costMinor(['country' => 'US', 'subtotal_minor' => 5000]));
        self::assertFalse($method->available(['country' => 'CA', 'subtotal_minor' => 5000]));
    }

    public function testPaymentTransitionsAndEventsAreVersioned(): void
    {
        self::assertTrue(PaymentStatusBoundary::canTransition('authorized', 'captured'));
        self::assertFalse(PaymentStatusBoundary::canTransition('refunded', 'captured'));
        $event = CustomerNotificationEvent::create('evt-1', 'cus-1', 'order.created', 'cor-1', 'cmd-1');
        self::assertSame('customer.notification.requested.v1', $event->name);
        self::assertSame(ExternalCallStatus::BLOCKED_EXTERNAL, ExternalCallStatus::blocked('dhl', 'live evidence required')['status']);
    }
}
