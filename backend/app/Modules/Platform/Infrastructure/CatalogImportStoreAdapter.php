<?php
declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CatalogImport;
use App\Modules\Catalog\Contracts\CatalogImportStore;

final class CatalogImportStoreAdapter implements CatalogImportStore
{
    public function findByFingerprint(string $fingerprint): ?array
    {
        $import = CatalogImport::query()->where('source_fingerprint', $fingerprint)->first();
        if ($import === null) return null;

        /** @var array<string, mixed> $result */
        $result = $import->toArray();
        return $result;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): void
    {
        CatalogImport::create($data);
    }
}
