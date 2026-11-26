<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application;

use App\Modules\Integration\Domain\EndpointPolicy;
use App\Modules\Integration\Domain\IntegrationEvent;
use App\Modules\Integration\Domain\IntegrationRedactor;
use App\Modules\Integration\Domain\WebhookSigner;

/** Builds a deterministic, redacted outbound request. Transport is deliberately injected. */
final class OutboundWebhook
{
    /** @return array{url:string,body:string,headers:array<string,string>} */
    public static function request(string $url, string $secret, IntegrationEvent $event, int $timestamp): array
    {
        EndpointPolicy::assertSafeUrl($url);
        $envelope = $event->envelope();
        $envelope['payload'] = IntegrationRedactor::redact($event->payload);
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return [
            'url' => $url,
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Integration-Event-Id' => $event->id,
                'X-Integration-Event' => $event->name,
                'X-Integration-Timestamp' => (string) $timestamp,
                'X-Integration-Signature' => WebhookSigner::sign($secret, $body, $timestamp, $event->id),
                'X-Correlation-Id' => $event->correlationId,
            ],
        ];
    }
}
