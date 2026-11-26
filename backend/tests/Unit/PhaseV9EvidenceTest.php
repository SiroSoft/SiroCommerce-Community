<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Resources\CreditMemoResource;
use App\Support\ProviderEvidence;
use PHPUnit\Framework\TestCase;

final class PhaseV9EvidenceTest extends TestCase
{
    public function testProviderEvidenceRedactsNestedPaymentSecrets(): void
    {
        $redacted = ProviderEvidence::redact(['payment' => ['pan' => '4111111111111111', 'provider_ref' => 'ref-1', 'nested' => ['signature' => 'secret']]]);
        self::assertSame('[redacted]', $redacted['payment']['pan']);
        self::assertSame('[redacted]', $redacted['payment']['nested']['signature']);
        self::assertSame('ref-1', $redacted['payment']['provider_ref']);
    }

    public function testCreditMemoResourceDoesNotExposeRawEvidenceSecrets(): void
    {
        $resource = CreditMemoResource::make(['id' => 'cm-1', 'amount_minor' => 500, 'currency' => 'USD', 'evidence_json' => json_encode(['payment' => ['authorization' => 'bearer secret', 'provider_ref' => 'ref-1']], JSON_THROW_ON_ERROR)]);
        $data = $resource;
        self::assertStringNotContainsString('bearer secret', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('ref-1', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
