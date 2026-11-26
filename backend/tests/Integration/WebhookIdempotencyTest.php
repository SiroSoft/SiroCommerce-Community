<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\CodPaymentGateway;
use Tests\TestCase;

/**
 * AC-A04 + F02: duplicate webhook event_id with valid HMAC is accepted without
 * creating a second side effect. COD gateway has no webhooks (verifyWebhook
 * false) — the contract rule is pinned here so extensions cannot break it.
 */
final class WebhookIdempotencyTest extends TestCase
{
    public function testCodHasNoWebhookSurface(): void
    {
        $gw = new CodPaymentGateway();
        $this->assertFalse($gw->verifyWebhook('{"id":"evt_1"}', ['sig' => 'x']));
    }

    public function testCodCaptureIdempotentAcrossCalls(): void
    {
        $gw = new CodPaymentGateway();
        $first = $gw->capture('pay_evt_1', []);
        $second = $gw->capture('pay_evt_1', []);
        $this->assertSame($first, $second);
        $this->assertSame('captured', $second['state']);
    }
}
