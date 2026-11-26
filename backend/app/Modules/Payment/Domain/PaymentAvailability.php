<?php
declare(strict_types=1);
namespace App\Modules\Payment\Domain;

final readonly class PaymentAvailability
{
    public function __construct(public string $method, public bool $available, public string $status, public string $reason = '') {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['method' => $this->method, 'available' => $this->available, 'status' => $this->status, 'reason' => $this->reason];
    }
}
