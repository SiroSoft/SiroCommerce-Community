<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\DigitalDownload;
use App\Modules\Catalog\Contracts\DigitalDeliveryStore;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

final class DigitalDeliveryStoreAdapter implements DigitalDeliveryStore
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function issue(array $data): array { return DigitalDownload::create(['id' => Ids::new()] + $data)->toArray(); }
    public function byTokenHash(string $hash): ?array
    {
        $row = DigitalDownload::query()->where('token_hash', $hash)->first();
        return $row === null ? null : Arr::row($row);
    }
    public function increment(string $id): void
    {
        $row = DigitalDownload::find($id);
        if ($row === null) return;
        $data = $row->toArray();
        $count = is_numeric($data['download_count'] ?? null) ? (int) $data['download_count'] : 0;
        $row->update(['download_count' => $count + 1, 'last_downloaded_at' => date('Y-m-d H:i:s')]);
    }

    public function consume(string $id, ?int $limit): bool
    {
        $sql = 'UPDATE digital_downloads SET download_count = download_count + 1, last_downloaded_at = ? WHERE id = ?';
        $params = [date('Y-m-d H:i:s'), $id];
        if ($limit !== null) { $sql .= ' AND download_count < ?'; $params[] = $limit; }
        $statement = Database::connection()->prepare($sql);
        foreach ($params as $index => $value) $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $statement->execute();
        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $evidence */
    public function recordEvidence(string $id, array $evidence): void
    {
        $row = DigitalDownload::find($id);
        if ($row === null) return;
        $existing = json_decode(Arr::str($row->toArray(), 'evidence_json'), true);
        $attempts = is_array($existing) && is_array($existing['attempts'] ?? null) ? $existing['attempts'] : [];
        $attempts[] = $evidence;
        $row->update(['evidence_json' => json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR)]);
    }

    public function revoke(string $id): void
    {
        DigitalDownload::query()->where('id', $id)->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }
}
