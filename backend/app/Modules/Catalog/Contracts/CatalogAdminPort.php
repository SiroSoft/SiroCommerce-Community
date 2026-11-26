<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Contracts;
interface CatalogAdminPort
{
    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function activePage(int $page, int $perPage): array;
    /** @return array<string, mixed>|null */ public function bySlug(string $slug): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array;
    public function delete(string $id): bool;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createVariant(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addComponent(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addDigitalAsset(array $data): array;

    /** @return array<int, array<string, mixed>> */
    public function components(string $productId): array;

    /** @return array<string, mixed>|null */
    public function digitalAsset(string $assetId): ?array;
}
