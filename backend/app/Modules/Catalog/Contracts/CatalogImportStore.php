<?php
declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface CatalogImportStore
{
    /** @return array<string, mixed>|null */
    public function findByFingerprint(string $fingerprint): ?array;

    /** @param array<string, mixed> $data */
    public function create(array $data): void;
}
