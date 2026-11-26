<?php
declare(strict_types=1);
namespace App\Modules\Seo\Contracts;
interface SeoStore
{
    /** @return array<string, mixed>|null */ public function meta(string $type, string $id): ?array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveMeta(string $type, string $id, array $data): array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveRedirect(array $data): array;
    /** @return array<int, array{loc: string, lastmod: string}> */ public function sitemap(string $base): array;
    /** @return array<int, array{type: string, entity: string, impact: string, fix: string}> */ public function health(): array;
}
