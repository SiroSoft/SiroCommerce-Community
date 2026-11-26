<?php

declare(strict_types=1);

namespace App\Modules\MarketingAutomation\Application;

use App\Modules\Queue\Contracts\Job;

final class MarketingAutomationJob implements Job
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly string $key, private readonly array $data) {}
    public function name(): string { return 'marketing.automation.intent'; }
    public function idempotencyKey(): string { return $this->key; }
    /** @return array<string,mixed> */
    public function payload(): array { return $this->data; }
    public function maxAttempts(): int { return 3; }
}
