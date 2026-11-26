<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Contracts;
interface CatalogTaxonomyPort
{
    /** @return array<int, array<string, mixed>> */
    public function brands(bool $includeArchived = false): array;
    /** @return array<int, array<string, mixed>> */
    public function categories(bool $includeArchived = false): array;
}
