<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface CatalogCompletenessPort
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOption(string $productId, array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOptionValue(string $optionId, array $data): array;

    /** @return array<int, array<string, mixed>> */
    public function options(string $productId): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addMedia(string $productId, array $data): array;

    /** @return array<int, array<string, mixed>> */
    public function media(string $productId): array;

    /** @return array<string, mixed>|null */
    public function removeMedia(string $productId, string $mediaId): ?array;

    /** @param array<int, string> $mediaIds */
    public function reorderMedia(string $productId, array $mediaIds): void;

    /** @return array<string, mixed>|null */
    public function productBySlug(string $slug): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function importProduct(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addVariant(string $productId, array $data): array;

    /** @param array<int, string> $optionValueIds */
    public function assignVariantOptions(string $variantId, array $optionValueIds): void;

    /** @return array<int, array<string, mixed>> */
    public function variants(string $productId): array;

    /** @param array<int, string> $optionValueIds */
    public function variantCombinationExists(string $productId, array $optionValueIds): bool;

    /** @param array<int, string> $optionValueIds */
    public function optionValuesBelongToProduct(string $productId, array $optionValueIds): bool;
}
