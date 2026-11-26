<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application;
use App\Modules\Catalog\Contracts\CatalogTaxonomyPort;
final class CatalogTaxonomyService
{
    public function __construct(private readonly CatalogTaxonomyPort $catalog) {}
    /** @return array<int, array<string, mixed>> */
    public function brands(bool $includeArchived = false): array { return $this->catalog->brands($includeArchived); }
    /** @return array<int, array<string, mixed>> */
    public function categories(bool $includeArchived = false): array { return $this->catalog->categories($includeArchived); }
}
