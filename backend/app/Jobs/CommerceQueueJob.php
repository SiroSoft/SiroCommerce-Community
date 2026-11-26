<?php

declare(strict_types=1);

namespace App\Jobs;

use Siro\Core\Event;
use Siro\Core\QueueInterface;
use App\Modules\Platform\Infrastructure\CommerceEventListeners;
use App\Modules\Webhook\Application\WebhookProcessor;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Modules\Platform\Infrastructure\PaymentStoreAdapter;
use App\Modules\Platform\Infrastructure\InvoiceStoreAdapter;

/** Bridges commerce domain jobs onto Siro's single worker engine. */
final class CommerceQueueJob implements QueueInterface
{
    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void
    {
        $jobId = is_string($data['job_id'] ?? null) ? $data['job_id'] : '';
        $name = is_string($data['name'] ?? null) ? $data['name'] : 'commerce.job';
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $repository = new V2SideEffectRepository();
        $workerId = gethostname() . ':' . getmypid();
        $claimed = $jobId === '' ? null : $repository->claimJob($jobId, $workerId, date('Y-m-d H:i:s', time() + 900));
        if ($claimed === null) return; // completed replay or another live worker owns the lease
        (new CommerceEventListeners())->register();
        try {
            (new WebhookProcessor($repository, new PaymentStoreAdapter(), new InvoiceStoreAdapter()))->register();
            if (!Event::emit($name, $payload)) throw new \RuntimeException('Commerce event listener rejected queued job: ' . $name);
            $repository->completeJob($jobId);
        } catch (\Throwable $e) {
            $repository->fail($jobId, $e->getMessage(), date('Y-m-d H:i:s', time() + 30));
            if ($repository->isQueued($jobId)) {
                // The core queue delivery is being consumed now, so explicitly
                // schedule the next delivery for the durable retry timestamp.
                \Siro\Core\Queue::push(self::class, ['job_id' => $jobId, 'name' => $name, 'payload' => $payload], 30, 0, 1);
            }
        }
    }
}
