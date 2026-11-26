<?php
declare(strict_types=1);
namespace App\Modules\Order\Contracts;
interface OrderCommandPort
{
    /** @return array<string, mixed>|null */ public function find(string $id): ?array;
    public function setState(string $id, string $state): void;
    public function logTransition(string $id, string $from, string $to, string $actor, string $reason): void;
}
