<?php
declare(strict_types=1);
namespace App\Modules\Subscriptions\Domain;
final readonly class Subscription
{
    public function __construct(public string $id, public string $customerId, public string $state) {}
    public function transition(string $action): string
    {
        $next = match ($action) {
            'pause' => $this->state === 'active' ? 'paused' : throw new \InvalidArgumentException('Subscription cannot be paused from ' . $this->state),
            'resume' => $this->state === 'paused' ? 'active' : throw new \InvalidArgumentException('Subscription cannot be resumed from ' . $this->state),
            // Cancellation at period end is distinct from immediate termination.
            'cancel' => in_array($this->state, ['active', 'paused', 'past_due', 'dunning'], true) ? 'cancel_pending' : throw new \InvalidArgumentException('Subscription cannot be cancelled from ' . $this->state),
            'cancel_now' => in_array($this->state, ['active', 'paused', 'cancel_pending', 'past_due', 'dunning'], true) ? 'cancelled' : throw new \InvalidArgumentException('Subscription cannot be cancelled from ' . $this->state),
            default => throw new \InvalidArgumentException('Unsupported subscription transition'),
        };
        return $next;
    }
}
