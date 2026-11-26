<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Checkout\Contracts\CartPort;
use App\Repositories\CartRepository;
final class CartCheckoutAdapter implements CartPort
{
    public function __construct(private readonly CartRepository $carts) {}
    public function find(string $id): ?array { return $this->carts->findCart($id); }
    public function lines(string $id): array { return $this->carts->lines($id); }
    public function converted(string $id): void { $this->carts->setCartState($id, 'converted'); }
}
