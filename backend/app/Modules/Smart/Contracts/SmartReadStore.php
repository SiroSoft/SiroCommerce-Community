<?php
declare(strict_types=1);
namespace App\Modules\Smart\Contracts;
interface SmartReadStore
{
    /** @return array<int, array<string, mixed>> */ public function failedOrders(): array;
    /** @return array<int, array<string, mixed>> */ public function awaitingPaymentOrders(): array;
    /** @return array<int, array<string, mixed>> */ public function inventoryItems(): array;
    /** @return array<int, array<string, mixed>> */ public function recentLedgers(string $variantId, string $since): array;
    /** @return array<int, array<string, mixed>> */ public function committedReservations(string $variantId): array;
    /** @return array<string, mixed>|null */ public function variant(string $id): ?array;
    /** @return array<int, array<string, mixed>> */ public function products(): array;
    /** @return array<int, array<string, mixed>> */ public function variantsForProduct(string $productId): array;
    /** @return array<string, mixed> */ public function search(string $query): array;
    /** @return array<string, mixed>|null */ public function orderByNumberOrId(string $value): ?array;
}
