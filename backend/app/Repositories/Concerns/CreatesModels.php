<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use Siro\Core\Model;

/**
 * Commerce uses CHAR(26) string PKs (spec docs/20). Core's save() overwrites
 * the in-memory PK with lastInsertId, so re-assert our id post-create.
 * The DB row itself is correct — only the returned attribute needs fixing.
 */
trait CreatesModels
{
    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function createRow(string $modelClass, array $data): array
    {
        /** @var array<string, mixed> $row */
        $row = $modelClass::create($data)->toArray();
        if (isset($data['id']) && is_string($data['id'])) {
            $row['id'] = $data['id'];
        }
        return $row;
    }
}
