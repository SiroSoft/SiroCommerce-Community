<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\Domain\DomainEvent;

interface OutboxWriter
{
    public function append(DomainEvent $event): void;
}
