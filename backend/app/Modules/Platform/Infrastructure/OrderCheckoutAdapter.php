<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Checkout\Contracts\OrderPort;
use App\Repositories\OrderRepository;
final class OrderCheckoutAdapter implements OrderPort
{
    public function __construct(private readonly OrderRepository $orders) {}
    public function byIdempotency(string $key): ?array { return $this->orders->findByIdempotency($key); }
    public function create(array $data): void { $this->orders->createOrder($data); }
    public function addLine(array $data): void { $this->orders->addLine($data); }
    public function payment(array $data): void { $this->orders->createPayment($data); }
    public function updatePayment(string $id, string $state, ?string $providerRef = null): void { $this->orders->setPaymentState($id, $state, $providerRef); }
    public function find(string $id): ?array
    {
        $order = $this->orders->find($id);
        $token = $this->orders->issuedGuestToken($id);
        if ($order !== null && $token !== null) $order['guest_access_token'] = $token;
        return $order;
    }
}
