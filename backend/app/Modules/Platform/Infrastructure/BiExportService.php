<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;

use App\Jobs\BiExportJob;
use App\Models\BiExport;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\ReportingScope;
use Siro\Core\Database;
use Siro\Core\Queue;
use Siro\Core\Storage;

final class BiExportService
{
    public const SCHEMA_VERSION = 1;
    private const BATCH = 100;
    private const DATASETS = ['orders', 'payments', 'refunds', 'subscriptions', 'b2b', 'promotions', 'fulfillment', 'inventory'];

    /** @return array<string, mixed> */
    public function create(string $dataset, string $format, ?string $from, ?string $to, ?string $storeId = null, ?string $channelId = null): array
    {
        [$storeId, $channelId] = ReportingScope::validate($storeId, $channelId);
        if (!in_array($dataset, self::DATASETS, true) || !in_array($format, ['jsonl', 'csv'], true)) throw new \InvalidArgumentException('Unsupported export dataset or format');
        if (($storeId !== null || $channelId !== null) && in_array($dataset, ['subscriptions', 'inventory'], true)) throw new \InvalidArgumentException('Store/channel scope is unavailable for this dataset');
        [$start, $end] = $this->window($from, $to); $now = date('Y-m-d H:i:s'); $id = Ids::new();
        BiExport::create(['id' => $id, 'dataset' => $dataset, 'format' => $format, 'schema_version' => self::SCHEMA_VERSION, 'from_at' => $start, 'to_at' => $end, 'snapshot_at' => $now, 'state' => 'queued', 'rows_written' => 0, 'batch_size' => self::BATCH, 'store_id' => $storeId, 'channel_id' => $channelId, 'created_at' => $now, 'updated_at' => $now]);
        Queue::push(BiExportJob::class, ['export_id' => $id], 0, 0, 5);
        return $this->metadata($id);
    }

    /** @return array<string, mixed> */
    public function status(string $id): array { return $this->metadata($id); }

    /** @return array<string, mixed> */
    public function retry(string $id): array
    {
        $export = $this->find($id); $data = $export->toArray();
        if (Arr::str($data, 'state') !== 'failed') throw new \InvalidArgumentException('Only failed exports can be retried');
        $this->assertSchema($data);
        // A storage write can succeed before the checkpoint is persisted. Do not append
        // that uncheckpointed batch when the failed job is replayed.
        if (Arr::int($data, 'rows_written') === 0 && Arr::nullableStr($data, 'cursor_id') === null) {
            $path = Arr::nullableStr($data, 'storage_path');
            if ($path !== null) Storage::delete($path);
        }
        $this->save($export, ['state' => 'queued', 'last_error' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        Queue::push(BiExportJob::class, ['export_id' => $id, 'retry' => time()], 0, 0, 5); return $this->metadata($id);
    }

    /** @return array<string, mixed> */
    public function downloadMetadata(string $id): array
    {
        $metadata = $this->metadata($id);
        if (Arr::str($metadata, 'state') !== 'completed' || Arr::nullableStr($metadata, 'download_url') === null) {
            throw new \InvalidArgumentException('Export is not completed');
        }
        return $metadata;
    }

    public function process(string $id): void
    {
        $export = $this->find($id); $data = $export->toArray(); $state = Arr::str($data, 'state');
        $this->assertSchema($data);
        if (in_array($state, ['completed', 'failed', 'running'], true)) return;
        $this->save($export, ['state' => 'running', 'updated_at' => date('Y-m-d H:i:s')]);
        try {
            $rows = $this->rows($data); $path = Arr::nullableStr($data, 'storage_path') ?? ('exports/' . $id . '.' . Arr::str($data, 'format'));
            $exists = Storage::exists($path); $old = $exists ? Storage::get($path) : '';
            $chunk = $this->encode($data, $rows);
            // Storage and the checkpoint are separate systems. If storage succeeded
            // but the checkpoint write failed, replay must not append the same batch.
            if ((!$exists || ($chunk !== '' && !str_ends_with((string) $old, $chunk))) && !Storage::put($path, (string) $old . $chunk)) throw new \RuntimeException('Unable to store export');
            $count = count($rows); $changes = ['storage_path' => $path, 'rows_written' => Arr::int($data, 'rows_written') + $count, 'updated_at' => date('Y-m-d H:i:s')]; $nextCursor = null;
            if ($count > 0) { $last = $rows[$count - 1]; $changes['cursor_at'] = Arr::str($last, '_cursor_at'); $changes['cursor_id'] = Arr::str($last, '_cursor_id'); $nextCursor = Arr::str($last, '_cursor_id'); }
            if ($count < Arr::int($data, 'batch_size', self::BATCH)) { $changes['state'] = 'completed'; $changes['completed_at'] = date('Y-m-d H:i:s'); $changes['download_url'] = Storage::url($path); }
            else { $changes['state'] = 'queued'; }
            $this->save($export, $changes);
            if ($count >= Arr::int($data, 'batch_size', self::BATCH)) Queue::push(BiExportJob::class, ['export_id' => $id, 'cursor' => $nextCursor], 0, 0, 5);
        } catch (\Throwable $e) { $this->save($export, ['state' => 'failed', 'last_error' => substr($e->getMessage(), 0, 1000), 'updated_at' => date('Y-m-d H:i:s')]); throw $e; }
    }

    /** @return array<string, mixed> */
    private function metadata(string $id): array { $row = $this->find($id)->toArray(); unset($row['storage_path'], $row['last_error']); return $row; }
    private function find(string $id): BiExport { $row = BiExport::find($id); if ($row === null) throw new \InvalidArgumentException('Export not found'); return $row; }

    /** @param array<string, mixed> $data */
    private function assertSchema(array $data): void
    {
        if (Arr::int($data, 'schema_version') !== self::SCHEMA_VERSION) throw new \InvalidArgumentException('Unsupported export schema version');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data): array
    {
        $spec = $this->spec(Arr::str($data, 'dataset')); $params = Arr::str($data, 'dataset') === 'inventory' ? [$data['snapshot_at'], $data['from_at'], $data['to_at']] : [$data['from_at'], $data['to_at'], $data['snapshot_at']];
        $sql = 'SELECT ' . $spec['select'] . ' FROM ' . $spec['from'] . ' WHERE ' . $spec['where'];
        if (Arr::nullableStr($data, 'store_id') !== null) { $sql .= ' AND o.store_id = ?'; $params[] = Arr::str($data, 'store_id'); }
        if (Arr::nullableStr($data, 'channel_id') !== null) { $sql .= ' AND o.channel_id = ?'; $params[] = Arr::str($data, 'channel_id'); }
        $cursor = Arr::nullableStr($data, 'cursor_id'); $cursorAt = Arr::nullableStr($data, 'cursor_at');
        if ($cursor !== null) { if ($spec['date'] === null) { $sql .= ' AND ' . $spec['id'] . ' > ?'; $params[] = $cursor; } else { $sql .= ' AND (' . $spec['date'] . ' > ? OR (' . $spec['date'] . ' = ? AND ' . $spec['id'] . ' > ?))'; $params[] = $cursorAt ?? ''; $params[] = $cursorAt ?? ''; $params[] = $cursor; } }
        $sql .= ' ORDER BY ' . ($spec['date'] ?? $spec['id']) . ', ' . $spec['id'] . ' LIMIT ' . self::BATCH;
        $query = Database::connection()->prepare($sql); $query->execute($params); $out = [];
        foreach ($query->fetchAll() ?: [] as $raw) {
            if (!is_array($raw)) continue;
            $row = []; foreach ($raw as $key => $value) if (is_string($key)) $row[$key] = $value;
            $row['_cursor_at'] = Arr::str($row, '_export_at'); $row['_cursor_id'] = Arr::str($row, '_export_id'); unset($row['_export_at'], $row['_export_id']); $out[] = $row;
        }
        return $out;
    }

    /** @return array{select:string,from:string,where:string,date:?string,id:string} */
    private function spec(string $dataset): array
    {
        return match ($dataset) {
            'orders' => ['select' => 'o.id AS _export_id,o.number,o.currency,o.subtotal_minor,o.discount_minor,o.shipping_minor,o.tax_minor,o.grand_total_minor,o.state,o.store_id,o.channel_id,o.created_at AS _export_at', 'from' => 'orders o', 'where' => 'o.created_at BETWEEN ? AND ? AND o.created_at <= ?', 'date' => 'o.created_at', 'id' => 'o.id'],
            'payments' => ['select' => 'p.id AS _export_id,p.order_id,p.provider,p.amount_minor,o.currency,p.state,o.created_at AS _export_at', 'from' => 'payments p JOIN orders o ON o.id=p.order_id', 'where' => 'o.created_at BETWEEN ? AND ? AND o.created_at <= ?', 'date' => 'o.created_at', 'id' => 'p.id'],
            'refunds' => ['select' => 'r.id AS _export_id,r.order_id,r.payment_id,r.amount_minor,r.reason,r.state,o.created_at AS _export_at', 'from' => 'refunds r JOIN orders o ON o.id=r.order_id', 'where' => 'o.created_at BETWEEN ? AND ? AND o.created_at <= ?', 'date' => 'o.created_at', 'id' => 'r.id'],
            'subscriptions' => ['select' => 's.id AS _export_id,s.plan_id,s.provider,s.currency,s.amount_minor,s.interval,s.interval_count,s.state,s.started_at,s.paused_at,s.cancelled_at,s.next_billing_at,s.created_at AS _export_at', 'from' => 'subscriptions s', 'where' => 's.created_at BETWEEN ? AND ? AND s.created_at <= ?', 'date' => 's.created_at', 'id' => 's.id'],
            'b2b' => ['select' => 'c.order_id AS _export_id,c.company_id,c.channel_id,o.currency,o.grand_total_minor,o.state,o.created_at AS _export_at', 'from' => 'company_orders c JOIN orders o ON o.id=c.order_id', 'where' => 'o.created_at BETWEEN ? AND ? AND o.created_at <= ?', 'date' => 'o.created_at', 'id' => 'c.order_id'],
            'promotions' => ['select' => 'u.id AS _export_id,u.coupon_id,u.order_id,u.discount_minor,u.created_at AS _export_at', 'from' => 'coupon_usages u JOIN orders o ON o.id=u.order_id', 'where' => 'u.created_at BETWEEN ? AND ? AND u.created_at <= ?', 'date' => 'u.created_at', 'id' => 'u.id'],
            'fulfillment' => ['select' => 'f.id AS _export_id,f.order_id,f.state,f.created_at AS _export_at', 'from' => 'fulfillment_orders f JOIN orders o ON o.id=f.order_id', 'where' => 'f.created_at BETWEEN ? AND ? AND f.created_at <= ?', 'date' => 'f.created_at', 'id' => 'f.id'],
            'inventory' => ['select' => 'i.id AS _export_id,i.variant_id,i.on_hand,i.reserved,i.low_threshold,? AS _export_at', 'from' => 'inventory_items i', 'where' => '? <= ?', 'date' => null, 'id' => 'i.id'],
            default => throw new \InvalidArgumentException('Unsupported export dataset'),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $rows
     */
    private function encode(array $data, array $rows): string
    {
        if (Arr::str($data, 'format') === 'jsonl') { $out = ''; foreach ($rows as $row) { unset($row['_cursor_at'], $row['_cursor_id']); $out .= json_encode(['schema_version' => self::SCHEMA_VERSION, 'record' => $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"; } return $out; }
        if ($rows === []) return ''; $keys = []; foreach ($rows[0] as $key => $_) if ($key !== '_cursor_at' && $key !== '_cursor_id') $keys[] = $key; $out = Arr::int($data, 'rows_written') === 0 ? implode(',', array_map(static fn (string $v): string => '"' . $v . '"', $keys)) . "\n" : '';
        foreach ($rows as $row) { unset($row['_cursor_at'], $row['_cursor_id']); $values = []; foreach ($keys as $key) { $value = $row[$key] ?? ''; $value = is_scalar($value) ? (string) $value : ''; $values[] = '"' . str_replace('"', '""', $value) . '"'; } $out .= implode(',', $values) . "\n"; } return $out;
    }
    /** @return array{0:string,1:string} */
    private function window(?string $from, ?string $to): array { $end = $to === null ? time() : strtotime($to); $start = $from === null ? strtotime('-30 days') : strtotime($from); if ($start === false || $end === false || $start > $end || $end - $start > 366 * 86400) throw new \InvalidArgumentException('Export window must be 1-366 days'); return [date('Y-m-d 00:00:00', $start), date('Y-m-d 23:59:59', $end)]; }
    /** @param array<string, mixed> $changes */
    private function save(BiExport $row, array $changes): void { foreach ($changes as $key => $value) $row->setAttribute($key, $value); $row->save(); }
}
