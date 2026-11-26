<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Cart\Contracts\CartCommandPort;
use App\Repositories\CartRepository;
final class CartCommandAdapter implements CartCommandPort
{
    public function __construct(private readonly CartRepository $carts) {}
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array { return $this->carts->createCart($data); }
    public function find(string $id): ?array { return $this->carts->findCart($id); }
    public function lines(string $id): array { return $this->carts->lines($id); }
    public function price(string $variantId): ?int { return $this->carts->variantPrice($variantId); }
    public function resolveVariant(string $id): ?string { return $this->carts->resolveVariant($id); }
    public function accessTokenIsValid(string $id, string $token): bool { return $this->carts->accessTokenIsValid($id, $token); }
    public function accessTokenIsValidForReplay(string $id, string $token): bool { return $this->carts->accessTokenIsValidForReplay($id, $token); }
public function addLine(string $cartId, string $variantId, int $qty, int $price, array $extra = []): array { return
             $this->carts->addLine($cartId, $variantId, $qty, $price, $extra); }
    public function setLineQuantity(string $cartId, string $lineId, int $quantity): void { $this->carts->setLineQuantity($cartId, $lineId, $quantity); }
    public function removeLine(string $cartId, string $lineId): void { $this->carts->removeLine($cartId, $lineId); }
    public function primaryImages(array $productIds): array { return $this->carts->primaryImages($productIds); }
}
