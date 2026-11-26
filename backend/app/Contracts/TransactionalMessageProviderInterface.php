<?php

declare(strict_types=1);

namespace App\Contracts;

interface TransactionalMessageProviderInterface
{
    /** @throws \Throwable when the provider is unavailable or rejects delivery. */
    public function send(string $recipient, string $subject, string $html): void;
}
