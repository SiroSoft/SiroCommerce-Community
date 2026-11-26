<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProviderEvidence;
use PHPUnit\Framework\TestCase;

final class ProviderEvidenceTest extends TestCase
{
    public function testEvidenceRedactsCredentialAliases(): void
    {
        $safe = ProviderEvidence::redact([
            'client_id' => 'id',
            'clientSecret' => 'secret',
            'authorization' => 'Bearer secret',
            'nested' => ['access-token' => 'token'],
            'provider_token' => 'token',
            'provider_reference' => 'ref-1',
        ]);

        self::assertSame('[redacted]', $safe['client_id']);
        self::assertSame('[redacted]', $safe['clientSecret']);
        self::assertSame('[redacted]', $safe['authorization']);
        self::assertSame('[redacted]', $safe['nested']['access-token']);
        self::assertSame('[redacted]', $safe['provider_token']);
        self::assertSame('ref-1', $safe['provider_reference']);
    }
}
