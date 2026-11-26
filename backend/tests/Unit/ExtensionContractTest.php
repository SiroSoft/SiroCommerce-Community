<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\CodPaymentGateway;
use App\Contracts\ExtensionInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\ShippingProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract round (spec docs/07): built-ins honor interfaces; COD capture is
 * idempotent (same input → same output, no side effects).
 */
final class ExtensionContractTest extends TestCase
{
    public function testCodImplementsContract(): void
    {
        $gw = new CodPaymentGateway();
        $this->assertInstanceOf(PaymentGatewayInterface::class, $gw);
    }

    public function testCodCaptureIdempotent(): void
    {
        $gw = new CodPaymentGateway();
        $a = $gw->capture('pay_1', []);
        $b = $gw->capture('pay_1', []);
        $this->assertSame($a, $b);
    }

    public function testContractInterfacesExist(): void
    {
        $this->assertTrue(interface_exists(ShippingProviderInterface::class));
        $this->assertTrue(interface_exists(ExtensionInterface::class));
    }
}
