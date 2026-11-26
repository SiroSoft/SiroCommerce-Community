<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
final class CatalogConflict extends \RuntimeException
{
    public function __construct(public readonly string $productId, public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct('Catalog product was modified by another writer');
    }
}
