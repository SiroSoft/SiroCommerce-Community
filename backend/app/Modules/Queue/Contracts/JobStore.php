<?php

declare(strict_types=1);

namespace App\Modules\Queue\Contracts;

interface JobStore
{
    public function enqueue(Job $job, string $availableAt): void;

    /** @return array<string, mixed>|null */
    public function claim(string $queue, string $workerId, string $leaseUntil): ?array;

    public function completeJob(string $jobId): void;

    public function fail(string $jobId, string $error, string $retryAt): void;

    public function deadLetter(string $jobId, string $reason): void;

}
