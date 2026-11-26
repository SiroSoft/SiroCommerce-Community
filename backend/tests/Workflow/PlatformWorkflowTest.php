<?php

declare(strict_types=1);

namespace Tests\Workflow;

use PHPUnit\Framework\TestCase;

final class PlatformWorkflowTest extends TestCase
{
    public function testPlatformWorkflowKeepsRawWebhookAndOperationalRecoveryBoundaries(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        $receiver = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Webhook/Application/WebhookReceiver.php');

        self::assertStringContainsString("'/api/version'", $routes);
        self::assertStringContainsString("'/api/v2/health/outbox'", $routes);
        self::assertStringContainsString('$rawBody', $receiver);
        self::assertStringContainsString("maxAttempts(): int { return 5; }", $receiver);
        self::assertStringContainsString("idempotencyKey(): string", $receiver);
    }
}
