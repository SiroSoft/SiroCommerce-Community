<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Order\Contracts\OrderCommandPort;
use App\Repositories\OrderRepository;
final class OrderCommandAdapter implements OrderCommandPort
{
    public function __construct(private readonly OrderRepository $orders) {}
    public function find(string $id): ?array { return $this->orders->find($id); }
    public function setState(string $id, string $state): void { $this->orders->setState($id, $state); }
    public function logTransition(string $id, string $from, string $to, string $actor, string $reason): void { $this->orders->logTransition($id, $from, $to, $actor, $reason); }
}
