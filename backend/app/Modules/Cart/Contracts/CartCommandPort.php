<?php
declare(strict_types=1);
namespace App\Modules\Cart\Contracts;
interface CartCommandPort
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    /** @return array<string, mixed>|null */ public function find(string $id): ?array;
    /** @return array<int, array<string, mixed>> */ public function lines(string $id): array;
    public function price(string $variantId): ?int;
    /** Resolves a variant id or product id to a purchasable variant id. */
    public function resolveVariant(string $id): ?string;
    public function accessTokenIsValid(string $id, string $token): bool;
    /** @return array<string, mixed> */ public function addLine(string $cartId, string $variantId, int $qty, int $price, array $extra = []): array;
    public function setLineQuantity(string $cartId, string $lineId, int $quantity): void;
    public function removeLine(string $cartId, string $lineId): void;
    /** @param array<int, string> $productIds
     *  @return array<string, string> product id to primary gallery image url */
    public function primaryImages(array $productIds): array;
}
