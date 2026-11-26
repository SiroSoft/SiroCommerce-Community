<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Modules\Queue\Contracts\Job;
final readonly class SubscriptionRenewalJob implements Job
{
    public function __construct(private string $subscriptionId, private string $key) {}
    public function name(): string { return 'subscription.renewal'; }
    public function idempotencyKey(): string { return $this->key; }
    public function payload(): array { return ['subscription_id' => $this->subscriptionId, 'idempotency_key' => $this->key]; }
    public function maxAttempts(): int { return 5; }
}
